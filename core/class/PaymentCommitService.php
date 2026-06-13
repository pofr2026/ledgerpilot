<?php

namespace LedgerPilot;

/**
 * Posts an approved Step 0 invoice-match proposal as a native payment onto the EXISTING imported bank
 * line — the §6/§7 commit. The whole transaction skeleton (one outer tx, READ COMMITTED, guard-update
 * first, canonical lock order, per-call return-code checks, outermost rollback) was proven live by
 * docs/spikes/commit_reversal_atomicity_check.php, which now drives THIS service (no duplicated skeleton).
 *
 * DB-coupled (verified by that integration spike, not unit tests); the numeric verdict is delegated to
 * the pure CommitDecision, the sales/supplier asymmetry to PaymentFlow, and the idempotency guard to
 * ProposalGuard.
 *
 * Contract:
 *   - Work item = an APPROVED proposal (its rowid). fk_bank / invoice / direction are read from it; the
 *     amount + sign + account + payment mode come from the existing bank line, read ONCE under its lock
 *     (D-C reuse, no hardcoded mode/total).
 *   - Idempotency anchor = ProposalGuard::transition(approved→booked) as the FIRST statement (rowid, not
 *     fk_bank: proposal has no UNIQUE(fk_bank), and many proposals may share a line).
 *   - Per-line anti-double-spend (the protection queue.UNIQUE(fk_bank) gave, lost when the anchor moved to
 *     proposal.rowid) = the bank line read FOR UPDATE + a payment%-link backstop UNDER that lock. The
 *     backstop also catches a line posted natively by the accountant outside the module (§12.1). The same
 *     locking read fetches the line fresh, so a line deleted after the proposal was made → FAILED, not a
 *     stale pre-read amount posted onto a missing fk_bank.
 *   - Overpayment race (§7) = the invoice read FOR UPDATE + a fresh getRemainToPay() handed to
 *     CommitDecision. READ COMMITTED (next-tx scope, before begin() — it auto-reverts, so it cannot leak
 *     across Dolibarr's pconnect, unlike SESSION) makes the recheck see a competitor's commit; the spike
 *     proves this behaviourally (@@tx_isolation does not reflect next-tx scope).
 *   - Canonical lock order proposal(rowid) → bank(fk_bank) → invoice(rowid), identical for every caller,
 *     so two proposals sharing a line + invoice cannot deadlock.
 *   - On ANY native return code <= 0 (or an exception): rollback at the OUTERMOST level and never commit
 *     — DoliDB's depth counter only DEcrements a nested rollback (§2), so a commit after a failed nested
 *     call would persist partial state.
 *
 * Aborts (ABORTED_*) and FAILED roll the whole tx back, so the guard-update is undone and the proposal
 * returns to 'approved'. Mapping an abort to a Dashboard 'exception' status (D-B: flag for human review,
 * do NOT re-queue the engine) is the proposal/queue cycle's job — this service only reports the rich
 * CommitResult.
 */
final class PaymentCommitService
{
	/** Test-only fault-injection point, fired AFTER create() + payment link, BEFORE the company link. */
	public const FAULT_BEFORE_LINK2 = 'before-link2';

	/**
	 * Commit the payment for an approved proposal.
	 *
	 * @param  \DoliDB        $db
	 * @param  \User          $user
	 * @param  int            $proposalId    The approved llx_ledgerpilot_proposal.rowid (the work item).
	 * @param  callable|null  $faultInjector TEST SEAM ONLY (null in production): called as
	 *                                        $faultInjector(self::FAULT_BEFORE_LINK2) inside the tx so the
	 *                                        atomicity spike can throw mid-transaction and prove the outer
	 *                                        rollback. Production passes null → no-op.
	 * @return string                         A CommitResult::* constant.
	 */
	public static function commit(\DoliDB $db, \User $user, int $proposalId, ?callable $faultInjector = null): string
	{
		global $conf;
		$prefix = MAIN_DB_PREFIX;

		// Read the work item (outside the tx; the guard-update below is the first tx statement). Entity-
		// scoped (§8): a proposal from another company must not be commitable through this handle.
		$prop = $db->query(
			'SELECT fk_bank, fk_facture, fk_facture_fourn FROM '.$prefix.'ledgerpilot_proposal'
			.' WHERE rowid = '.((int) $proposalId).' AND entity = '.((int) $conf->entity)
		);
		if (!$prop) {
			dol_syslog('LedgerPilot PaymentCommitService::commit proposal read failed: '.$db->lasterror(), LOG_ERR);

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

		// READ COMMITTED for the guarded block (next-tx scope), BEFORE begin(). A silent degradation to
		// REPEATABLE READ would disable the overpayment recheck, so the SET is return-code checked (L4).
		if (!$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) {
			dol_syslog('LedgerPilot PaymentCommitService::commit SET ISOLATION failed: '.$db->lasterror(), LOG_ERR);

			return CommitResult::FAILED;
		}
		$db->begin();

		try {
			// --- Idempotency guard: FIRST statement. proposal(rowid), approved -> booked. ---
			$guarded = ProposalGuard::transition($db, $proposalId, ProposalStatus::APPROVED, ProposalStatus::BOOKED);
			if ($guarded !== null) {
				$db->rollback();

				return $guarded;
			}

			// --- Canonical lock #2: the bank line. Read amount + account + mode ONCE under the lock (D-C
			// reuse, fresh-under-lock), and treat a missing row (line deleted since the proposal) as FAILED. ---
			$lineRes = $db->query(
				'SELECT amount, fk_account, fk_type FROM '.$prefix.'bank WHERE rowid = '.$fkBank.' FOR UPDATE'
			);
			$line = $lineRes ? $db->fetch_object($lineRes) : null;
			if (!$line) {
				$db->rollback();

				return CommitResult::FAILED;
			}
			$lineAmount    = (float) price2num($line->amount, 'MT');
			$amount        = abs($lineAmount);
			$bankAccountId = (int) $line->fk_account;
			$modeId        = self::resolvePaymentModeId($db, (string) $line->fk_type);

			// Sign guard (D-C): the line's sign must match the flow direction (catches a Dashboard mis-wire
			// of a supplier payment onto a credit line before anything is posted).
			if (($lineAmount <=> 0.0) !== $flow->bankSign) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			// Per-line backstop UNDER the line lock: a payment%-family link means the line is already posted.
			$linkCheck = $db->query(
				'SELECT COUNT(*) AS n FROM '.$prefix.'bank_url WHERE fk_bank = '.$fkBank." AND type LIKE 'payment%'"
			);
			$alreadyPosted = ($linkCheck && ($lc = $db->fetch_object($linkCheck))) ? ((int) $lc->n > 0) : false;
			if ($alreadyPosted) {
				$db->rollback();

				return CommitResult::ABORTED_SETTLED;
			}

			// The native posting cannot succeed with an unresolved payment mode / account (Paiement::create()
			// does not validate paiementid — a 0 would silently write fk_paiement=0): fail fast (M1).
			if ($modeId <= 0 || $bankAccountId <= 0) {
				dol_syslog('LedgerPilot PaymentCommitService::commit unresolved mode/account for fk_bank '.$fkBank, LOG_ERR);
				$db->rollback();

				return CommitResult::FAILED;
			}

			// --- Canonical lock #3: the invoice (overpayment race), then a fresh balance. ---
			$invTable = $isPurchase ? 'facture_fourn' : 'facture';
			if (!$db->query('SELECT rowid FROM '.$prefix.$invTable.' WHERE rowid = '.$invoiceId.' FOR UPDATE')) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
			$invoice = new ($flow->invoiceClass)($db);
			if ($invoice->fetch($invoiceId) <= 0) {
				// A failed fetch must not read as a zero balance (→ ABORTED_SETTLED, a wrong verdict); fail (L1).
				$db->rollback();

				return CommitResult::FAILED;
			}
			$remain = (float) $invoice->getRemainToPay(0);

			$verdict = CommitDecision::decide($remain, $amount);
			if ($verdict === CommitDecision::ABORT_SETTLED) {
				$db->rollback();

				return CommitResult::ABORTED_SETTLED;
			}
			if ($verdict === CommitDecision::ABORT_OVERPAY) {
				$db->rollback();

				return CommitResult::ABORTED_OVERPAY;
			}

			// --- Native posting: create() + both links on the EXISTING line (NOT addPaymentToBank). ---
			require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

			$paiement = new ($flow->paymentClass)($db);
			$paiement->datepaye     = dol_now();
			$paiement->paiementid   = $modeId;
			$paiement->num_payment  = '';
			$paiement->amounts      = array($invoiceId => $amount);
			if ($flow->paymentClass === 'PaiementFourn') {
				// PaiementFourn::create() reads fk_account when multicurrency is set; same-currency v0.1 is
				// fine either way, but set it so the currency-validation branch passes (open item §12.5).
				$paiement->fk_account         = $bankAccountId;
				$paiement->multicurrency_code = array($invoiceId => $conf->currency);
				$paiement->multicurrency_tx   = array($invoiceId => 1);
			}
			if ($paiement->create($user) <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			$acc = new \Account($db);
			$acc->fetch($bankAccountId);

			// Link #1: payment / payment_supplier.
			$r1 = $acc->add_url_line($fkBank, $paiement->id, DOL_URL_ROOT.$flow->paymentUrlPath, PaymentFlow::PAYMENT_LABEL, $flow->bankMode);
			if ($r1 <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			if ($faultInjector !== null) {
				$faultInjector(self::FAULT_BEFORE_LINK2); // test seam: throw here to prove rollback undoes create() + link #1
			}

			// Link #2: company (invoice ↔ third party).
			$invoice->fetch_thirdparty();
			$r2 = $acc->add_url_line($fkBank, $invoice->thirdparty->id, DOL_URL_ROOT.$flow->companyUrlPath, (string) $invoice->thirdparty->name, PaymentFlow::COMPANY_LINK_TYPE);
			if ($r2 <= 0) {
				$db->rollback();

				return CommitResult::FAILED;
			}

			// Settle the invoice when the amount covers the balance.
			if ($verdict === CommitDecision::PROCEED_FULL) {
				if ($invoice->setPaid($user) <= 0) {
					$db->rollback();

					return CommitResult::FAILED;
				}
			}

			// SEAM (D-E): the decision_log append (action=accept, the candidate_set carried by the proposal)
			// belongs HERE — inside this same outer tx, so a posted payment always has an audit row (no
			// ledger entry without a trace). Implemented in the queue/Dashboard cycle that owns decision_log.

			$db->commit();

			return CommitResult::COMMITTED;
		} catch (\Throwable $e) {
			$db->rollback();
			dol_syslog('LedgerPilot PaymentCommitService::commit rolled back: '.$e->getMessage(), LOG_ERR);

			return CommitResult::FAILED;
		}
	}

	/**
	 * The payment mode (llx_c_paiement.id) for the line's fk_type — reused so we do not hardcode a mode
	 * (the line already carries how the money moved). Returns 0 when the code has no active dictionary
	 * entry for the entity; the caller treats 0 as FAILED (M1).
	 */
	private static function resolvePaymentModeId(\DoliDB $db, string $fkType): int
	{
		if ($fkType === '') {
			return 0;
		}
		$res = $db->query(
			'SELECT id FROM '.MAIN_DB_PREFIX.'c_paiement'
			." WHERE code = '".$db->escape($fkType)."' AND entity IN (".getEntity('c_paiement').')'
		);

		return ($res && ($o = $db->fetch_object($res))) ? (int) $o->id : 0;
	}
}
