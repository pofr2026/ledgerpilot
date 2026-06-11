<?php

namespace LedgerPilot;

/**
 * Pure balance-recheck verdict of the Step 0 payment commit (spec §6/§7). The DB-coupled
 * PaymentCommitService locks the invoice (SELECT ... FOR UPDATE, under READ COMMITTED) and reads
 * getRemainToPay() fresh inside the guarded block, then hands the locked balance + the line amount here.
 * This class decides — Dolibarr-free, unit-testable — whether to post the payment and whether it fully
 * settles the invoice, or to abort. Same split as IbanAccountLookup / InvoiceMatchDecision (pure verdict
 * here, the transaction is spike-verified in the service).
 *
 * The verdict is the §7 overpayment guard made meaningful: it is read AFTER the FOR UPDATE lock + a fresh
 * getRemainToPay(), so a concurrent payment that drained the balance since the accountant approved is
 * caught here (ABORT_SETTLED / ABORT_OVERPAY) rather than silently overpaying.
 *
 * Single-valued verdict (idiom: InvoiceMatchDecision::decide(): string), four outcomes — fullySettle is
 * encoded in PROCEED_FULL vs PROCEED_PARTIAL rather than a separate flag the caller could read out of an
 * ABORT result:
 *   - PROCEED_FULL ...... post the payment AND setPaid() (the amount settles the remaining balance).
 *   - PROCEED_PARTIAL ... post the payment, leave the invoice open (a legitimate partial payment).
 *   - ABORT_SETTLED ..... nothing left to pay (remain <= ε). The service flags this for the Dashboard;
 *                         it does NOT re-queue the engine (the money already arrived — the engine would
 *                         only re-propose the same thing).
 *   - ABORT_OVERPAY ..... the amount would overpay (amount > remain + ε). Dolibarr allows overpayment,
 *                         but on a high-confidence Step 0 auto-commit it is suspicious → human review.
 *
 * Precision-first rule (BALANCE_EPSILON is the penny tolerance):
 *   (a) remain <= ε ............... ABORT_SETTLED   (first gate, independent of amount)
 *   (b) |amount − remain| <= ε .... PROCEED_FULL    (the ±ε boundary belongs to FULL, inclusive <=)
 *   (c) amount < remain − ε ....... PROCEED_PARTIAL (strictly below the FULL band)
 *   (d) amount > remain + ε ....... ABORT_OVERPAY   (strictly above the FULL band)
 *
 * decide() assumes amount > 0: the sign / amount guard (D-C) rejects amount <= 0 in the service BEFORE
 * decide(), so a zero amount is out of this pure verdict's scope.
 */
final class CommitDecision
{
	/** Verdict: post the payment and close the invoice (the amount settles the balance). */
	public const PROCEED_FULL = 'proceed_full';

	/** Verdict: post the payment, leave the invoice open (a legitimate partial payment). */
	public const PROCEED_PARTIAL = 'proceed_partial';

	/** Verdict: nothing left to pay — abort and flag for the Dashboard (do not re-queue the engine). */
	public const ABORT_SETTLED = 'abort_settled';

	/** Verdict: the amount would overpay — abort and flag for human review. */
	public const ABORT_OVERPAY = 'abort_overpay';

	/**
	 * Penny tolerance for money equality ("|amount − remain| ≤ ε → fully settled"). This class is the
	 * single owner of the constant: the integration that grows next (the atomicity spike, then
	 * PaymentCommitService) MUST read CommitDecision::BALANCE_EPSILON rather than duplicate the literal,
	 * so the pure verdict and the live commit speak of one threshold by reuse, not by comment. Deliberately
	 * NOT shared with InvoiceMatchDecision's remain > 0 gate (a coarse "is anything owed at all" check) —
	 * same value today, different concept.
	 */
	public const BALANCE_EPSILON = 0.005;

	/**
	 * Decide whether to post the line's payment against the freshly-locked invoice balance.
	 *
	 * @param  float $remainToPay The invoice balance read under FOR UPDATE inside the guarded block.
	 * @param  float $amount      The (positive) amount to post, from the existing llx_bank line.
	 * @return string             self::PROCEED_FULL | self::PROCEED_PARTIAL | self::ABORT_SETTLED |
	 *                            self::ABORT_OVERPAY.
	 */
	public static function decide(float $remainToPay, float $amount): string
	{
		// (a) Nothing left to pay (a concurrent payment settled it, or a float residual) — not payable.
		if ($remainToPay <= self::BALANCE_EPSILON) {
			return self::ABORT_SETTLED;
		}

		$delta = $amount - $remainToPay;

		// (b) The amount settles the balance within tolerance — the ±ε boundary is inclusive.
		if (abs($delta) <= self::BALANCE_EPSILON) {
			return self::PROCEED_FULL;
		}

		// (c) Strictly below the FULL band (|delta| > ε already ruled out, so delta < 0 means amount <
		// remain − ε) — a legitimate partial payment.
		if ($delta < 0) {
			return self::PROCEED_PARTIAL;
		}

		// (d) Strictly above the FULL band — the amount would overpay.
		return self::ABORT_OVERPAY;
	}
}
