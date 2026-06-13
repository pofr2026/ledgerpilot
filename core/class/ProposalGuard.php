<?php

namespace LedgerPilot;

/**
 * The idempotency guard shared by PaymentCommitService and PaymentReversalService: a conditional status
 * transition on a proposal row, run as the FIRST statement inside the service's outer tx. It is the
 * per-proposal idempotency anchor (keyed on rowid, since proposal has no UNIQUE(fk_bank) and several
 * proposals may share a bank line).
 *
 * The 0-rows ambiguity resolution (already in the target state = idempotent re-entry, vs in neither the
 * source nor the target state = a UI/logic error) is a subtle contract that MUST stay identical in both
 * services — a copy would drift on the first edit — so it lives here once (CLAUDE.md #2). The caller owns
 * the transaction: transition() only runs the UPDATE + the diagnostic SELECT and returns a verdict; on a
 * non-null result the caller rolls back its outer tx and returns that CommitResult.
 */
final class ProposalGuard
{
	/**
	 * Conditionally move a proposal from $from to $to (the guard-update).
	 *
	 * @param  \DoliDB $db
	 * @param  int     $proposalId
	 * @param  string  $from A ProposalStatus::* the row must currently be in.
	 * @param  string  $to   The ProposalStatus::* terminal state to move it to.
	 * @return string|null    null = proceed (the row moved $from→$to); otherwise a terminal CommitResult::*:
	 *                        ALREADY_DONE (already in $to), INVALID_STATE (in neither $from nor $to), or
	 *                        FAILED (the UPDATE query errored).
	 */
	public static function transition(\DoliDB $db, int $proposalId, string $from, string $to): ?string
	{
		$prefix = MAIN_DB_PREFIX;

		$res = $db->query(
			'UPDATE '.$prefix.'ledgerpilot_proposal SET status = \''.$db->escape($to).'\''
			.' WHERE rowid = '.$proposalId.' AND status = \''.$db->escape($from).'\''
		);
		if (!$res) {
			return CommitResult::FAILED;
		}
		if ((int) $db->affected_rows($res) !== 0) {
			return null; // moved $from -> $to: proceed
		}

		// 0 rows is ambiguous: already in $to (idempotent re-entry) vs not in $from (UI/logic error).
		$cur = $db->query('SELECT status FROM '.$prefix.'ledgerpilot_proposal WHERE rowid = '.$proposalId);
		$st  = ($cur && ($o = $db->fetch_object($cur))) ? $o->status : null;

		return ($st === $to) ? CommitResult::ALREADY_DONE : CommitResult::INVALID_STATE;
	}
}
