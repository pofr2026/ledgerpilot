<?php

namespace LedgerPilot;

/**
 * Reverses a booked Step 0 commit — the §6 SPIKE #2 procedure, with the same transaction rigor as the
 * commit (M2): setUnpaid() FIRST, then delete() the payment (line-safe at fk_bank=NULL/0), then manually
 * remove the bank_url links delete() never touches — ALL in one outer tx. The skeleton was proven live by
 * docs/spikes/commit_reversal_atomicity_check.php, which now drives THIS service.
 *
 * Why one outer tx is load-bearing (M2): the reversal makes several mutations mixing native objects + raw
 * SQL. If delete() succeeded but the link cleanup failed, a partial reversal would orphan the bank_url
 * links — AND the next re-entry would see the payment already gone and return ALREADY_DONE, leaving the
 * orphans forever. The single outer tx guarantees a partial failure rolls back fully (never an orphan
 * window), so the per-proposal idempotency guard (booked→reversed) is sound.
 *
 * DB-coupled (verified by that integration spike, not unit tests); the idempotency guard is delegated to
 * ProposalGuard (shared with the commit).
 */
final class PaymentReversalService
{
	/** Test-only fault-injection point, fired AFTER delete(), BEFORE the bank_url link cleanup. */
	public const FAULT_BEFORE_CLEANUP = 'before-cleanup';

	/**
	 * Reverse the committed payment for a booked proposal.
	 *
	 * @param  \DoliDB        $db
	 * @param  \User          $user
	 * @param  int            $proposalId    The booked llx_ledgerpilot_proposal.rowid.
	 * @param  callable|null  $faultInjector TEST SEAM ONLY (null in production): called as
	 *                                        $faultInjector(self::FAULT_BEFORE_CLEANUP) so the spike can
	 *                                        throw between delete() and the link cleanup and prove the outer
	 *                                        rollback leaves no orphan.
	 * @return string                         A CommitResult::* constant (REVERSED on success).
	 */
	public static function reverse(\DoliDB $db, \User $user, int $proposalId, ?callable $faultInjector = null): string
	{
		global $conf;
		$prefix = MAIN_DB_PREFIX;

		// Read the work item (entity-scoped §8), mirroring commit's read.
		$prop = $db->query(
			'SELECT fk_bank, fk_facture, fk_facture_fourn FROM '.$prefix.'ledgerpilot_proposal'
			.' WHERE rowid = '.((int) $proposalId).' AND entity = '.((int) $conf->entity)
		);
		if (!$prop) {
			dol_syslog('LedgerPilot PaymentReversalService::reverse proposal read failed: '.$db->lasterror(), LOG_ERR);

			return CommitResult::FAILED;
		}
		$prow = $db->fetch_object($prop);
		$db->free($prop);
		if (!$prow) {
			return CommitResult::INVALID_STATE;
		}

		$isPurchase = !empty($prow->fk_facture_fourn);
		$flow       = PaymentFlow::forPurchase($isPurchase);
		$fkBank     = (int) $prow->fk_bank;
		$invoiceId  = $isPurchase ? (int) $prow->fk_facture_fourn : (int) $prow->fk_facture;

		// The payment to reverse = the url_id on our payment link (reuse the native trace, like the backstop).
		$payRes = $db->query(
			'SELECT url_id FROM '.$prefix.'bank_url WHERE fk_bank = '.$fkBank." AND type = '".$db->escape($flow->bankMode)."'"
		);
		$paymentId = ($payRes && ($po = $db->fetch_object($payRes))) ? (int) $po->url_id : 0;

		// L4: a silent isolation downgrade is harmless for reversal but the SET is rc-checked for parity.
		if (!$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) {
			dol_syslog('LedgerPilot PaymentReversalService::reverse SET ISOLATION failed: '.$db->lasterror(), LOG_ERR);

			return CommitResult::FAILED;
		}
		$db->begin();

		try {
			// --- Mirror guard: FIRST statement. proposal(rowid), booked -> reversed. ---
			$guarded = ProposalGuard::transition($db, $proposalId, ProposalStatus::BOOKED, ProposalStatus::REVERSED);
			if ($guarded !== null) {
				$db->rollback();

				return $guarded;
			}

			// A booked proposal with no payment link is an anomaly (commit's invariant is link present);
			// fail explicitly rather than let fetch(0)+delete() fail by accident (L2).
			if ($paymentId <= 0) {
				dol_syslog('LedgerPilot PaymentReversalService::reverse booked proposal '.$proposalId.' has no payment link', LOG_ERR);
				$db->rollback();

				return CommitResult::FAILED;
			}

			// --- setUnpaid() FIRST — native delete() refuses on a closed/paid invoice (§6 SPIKE #2 order). ---
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
			$invoice = new ($flow->invoiceClass)($db);
			if ($invoice->fetch($invoiceId) <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}
			if ($invoice->setUnpaid($user) <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			// --- delete() our payment (fetched fresh; fk_bank=NULL/0 => the imported line is line-safe). ---
			require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
			$payment = new ($flow->paymentClass)($db);
			$payment->fetch($paymentId);
			if ($payment->delete($user) <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			if ($faultInjector !== null) {
				$faultInjector(self::FAULT_BEFORE_CLEANUP); // test seam: throw here to prove rollback leaves no orphan
			}

			// --- Manually remove OUR bank_url links — delete() never touches them. Narrowed by url_id
			// (payment link → paymentId, company link → the invoice's socid) so a link a human attached to
			// the line outside the module is never collateral (L3). ---
			$del = $db->query(
				'DELETE FROM '.$prefix.'bank_url WHERE fk_bank = '.$fkBank
				." AND ((type = '".$db->escape($flow->bankMode)."' AND url_id = ".$paymentId.")"
				." OR (type = '".PaymentFlow::COMPANY_LINK_TYPE."' AND url_id = ".((int) $invoice->socid).'))'
			);
			if (!$del) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			// SEAM (D-E): a decision_log reversal event belongs HERE, in this same outer tx (implemented in
			// the queue/Dashboard cycle that owns decision_log).

			$db->commit();

			return CommitResult::REVERSED;
		} catch (\Throwable $e) {
			$db->rollback();
			dol_syslog('LedgerPilot PaymentReversalService::reverse rolled back: '.$e->getMessage(), LOG_ERR);

			return CommitResult::FAILED;
		}
	}
}
