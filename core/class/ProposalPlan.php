<?php

namespace LedgerPilot;

/**
 * The PURE mapper from the engine's per-line verdict to what the worker writes for that line: a proposal
 * row to INSERT, a silent SKIP, or a MANUAL fall-through (spec §4/§5). The DB-coupled facade runs the
 * cascade (own-transfer → clearing → step0 → L1 → L2) and emits a plain-data verdict; this class — Dolibarr-
 * free, unit-testable — owns the single place that knows "verdict X → proposal columns Y / skip / manual",
 * i.e. the dual-mode column rule the proposal DDL documents (step0 ⇒ fk_facture*; account ⇒ proposed_account).
 * Pattern: EntryPlan / FeeSplitter — a pure planner the DB layer feeds.
 *
 * Result is a DISCRIMINATED `{kind, row}`, never a bare null, so the worker tells the two "no proposal"
 * cases apart for its summary:
 *   - SKIP   — an own transfer; deliberately filtered (queue → done, no proposal, no decision_log).
 *   - MANUAL — the cascade was exhausted (queue → done, no proposal); the line feeds the corpus later via
 *              the accountant's manual posting.
 *   - PROPOSE — `row` carries the proposal columns; row keys ARE the DDL column names (no aliases), and
 *              the worker adds entity / fk_bank / status / date_creation when it INSERTs.
 *
 * Fail-safe (precision-first): an unrecognized type, or a propose-type verdict whose payload names no
 * target (invoice with no id; account with an empty account or a non-account layer), degrades to MANUAL —
 * never a spurious PROPOSE. The facade must not emit garbage; this is only the backstop.
 */
final class ProposalPlan
{
	/** Verdict types — the input vocabulary the facade builds verdicts with (single source). */
	public const VERDICT_OWN_TRANSFER = 'own_transfer';
	public const VERDICT_NONE         = 'none';
	public const VERDICT_INVOICE      = 'invoice';
	public const VERDICT_ACCOUNT      = 'account';

	/** Outcome kinds — what the worker switches on (not stored; internal to the engine→worker contract). */
	public const PROPOSE = 'propose';
	public const SKIP    = 'skip';
	public const MANUAL  = 'manual';

	/**
	 * Map a verdict to a proposal-row plan or a no-row outcome.
	 *
	 * @param  array $verdict {type: VERDICT_*, ...payload}. Invoice payload: {fkFacture, fkFactureFourn}.
	 *                        Account payload: {layer ∈ {l1,l2,clearing}, account, score?, candidateSet?}.
	 * @return array          {kind: PROPOSE|SKIP|MANUAL, row: ?array}. On PROPOSE, row has the proposal
	 *                        columns (layer, proposed_account, fk_facture, fk_facture_fourn, score,
	 *                        candidate_set); otherwise row is null.
	 */
	public static function fromVerdict(array $verdict): array
	{
		$type = $verdict['type'] ?? self::VERDICT_NONE;

		switch ($type) {
			case self::VERDICT_OWN_TRANSFER:
				return self::result(self::SKIP);

			case self::VERDICT_INVOICE:
				return self::planInvoice($verdict);

			case self::VERDICT_ACCOUNT:
				return self::planAccount($verdict);

			case self::VERDICT_NONE:
			default:
				// Exhausted cascade, or an unrecognized type (fail-safe): the line goes to manual.
				return self::result(self::MANUAL);
		}
	}

	/** Step 0 invoice match → invoice track. v0.1 is sales-only (D1), so fkFactureFourn is 0 in practice;
	 *  the mapping stays symmetric (whichever id is positive drives its column), so it is purchase-ready. */
	private static function planInvoice(array $verdict): array
	{
		$fkFacture      = (int) ($verdict['fkFacture'] ?? 0);
		$fkFactureFourn = (int) ($verdict['fkFactureFourn'] ?? 0);

		if ($fkFacture <= 0 && $fkFactureFourn <= 0) {
			return self::result(self::MANUAL); // names no invoice → not a real step0 match
		}

		return self::result(self::PROPOSE, array(
			'layer'            => ProposalLayer::STEP0,
			'proposed_account' => null,
			'fk_facture'       => $fkFacture > 0 ? $fkFacture : null,
			'fk_facture_fourn' => $fkFactureFourn > 0 ? $fkFactureFourn : null,
			'score'            => null,
			'candidate_set'    => null,
		));
	}

	/** L1 / L2 / clearing → account track. Clearing carries no learned score / candidate_set (decision E). */
	private static function planAccount(array $verdict): array
	{
		$layer   = (string) ($verdict['layer'] ?? '');
		$account = (string) ($verdict['account'] ?? '');

		$accountLayers = array(ProposalLayer::L1, ProposalLayer::L2, ProposalLayer::CLEARING);
		if ($account === '' || !in_array($layer, $accountLayers, true)) {
			return self::result(self::MANUAL); // names no target, or a non-account layer → backstop
		}

		return self::result(self::PROPOSE, array(
			'layer'            => $layer,
			'proposed_account' => $account,
			'fk_facture'       => null,
			'fk_facture_fourn' => null,
			'score'            => isset($verdict['score']) ? (float) $verdict['score'] : null,
			'candidate_set'    => isset($verdict['candidateSet']) ? (string) $verdict['candidateSet'] : null,
		));
	}

	/** Centralizes the discriminated result shape so a change to it stays in one place (CLAUDE.md #2). */
	private static function result(string $kind, ?array $row = null): array
	{
		return array('kind' => $kind, 'row' => $row);
	}
}
