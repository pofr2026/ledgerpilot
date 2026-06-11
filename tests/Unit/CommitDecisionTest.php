<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\CommitDecision;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\CommitDecision::decide() — the PURE balance-recheck verdict of the Step 0
 * payment commit (spec §6/§7). The DB-coupled PaymentCommitService locks the invoice (FOR UPDATE, under
 * READ COMMITTED) and reads getRemainToPay() fresh, then hands the locked balance + the line amount here.
 * This class decides — Dolibarr-free, unit-testable — whether to post and whether the payment fully
 * settles the invoice, or to abort. Same split as IbanAccountLookup / InvoiceMatchDecision: the verdict
 * is pure here, the transaction is spike-verified in PaymentCommitService.
 *
 * Single-valued verdict (idiom: InvoiceMatchDecision::decide(): string), four outcomes — fullySettle is
 * encoded in PROCEED_FULL vs PROCEED_PARTIAL rather than a separate flag:
 *   - PROCEED_FULL ...... post the payment AND setPaid() (the amount settles the remaining balance).
 *   - PROCEED_PARTIAL ... post the payment, leave the invoice open (a legitimate partial payment).
 *   - ABORT_SETTLED ..... nothing left to pay (remain <= ε) — a concurrent payment already settled it;
 *                         the service flags this for the Dashboard, it does NOT re-queue the engine
 *                         (the money already arrived; the engine would only re-propose the same thing).
 *   - ABORT_OVERPAY ..... the amount would overpay (amount > remain + ε). Dolibarr allows overpayment,
 *                         but in high-confidence Step 0 auto-commit it is suspicious → human review.
 *
 * Verdict rule (precision-first), with BALANCE_EPSILON = 0.005 (CommitDecision owns this tolerance; the
 * integration that grows next — the atomicity spike, then PaymentCommitService — reads the constant
 * rather than duplicating the literal, so pure decision and live commit share one threshold):
 *   (a) remain <= ε ............... ABORT_SETTLED   (first gate, independent of amount)
 *   (b) |amount − remain| <= ε .... PROCEED_FULL    (the ±ε boundary belongs to FULL, inclusive <=)
 *   (c) amount < remain − ε ....... PROCEED_PARTIAL (strictly below the FULL band)
 *   (d) amount > remain + ε ....... ABORT_OVERPAY   (strictly above the FULL band)
 *
 * decide() assumes amount > 0: the sign / amount guard (D-C) rejects amount <= 0 in the service BEFORE
 * decide(), so a zero amount is out of this pure verdict's scope and is not pinned here.
 */
final class CommitDecisionTest extends TestCase
{
	/**
	 * Settled: a fully-paid invoice (remain == 0) is not payable at all → ABORT_SETTLED, regardless of
	 * the amount on the line.
	 */
	public function testSettledWhenRemainIsZero(): void
	{
		$this->assertSame(
			CommitDecision::ABORT_SETTLED,
			CommitDecision::decide(0.00, 100.00)
		);
	}

	/**
	 * Settled boundary: remain exactly at ε counts as nothing left to pay (remain <= ε) → ABORT_SETTLED.
	 */
	public function testSettledWhenRemainAtEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::ABORT_SETTLED,
			CommitDecision::decide(0.005, 100.00)
		);
	}

	/**
	 * Settled: a tiny negative float residual (remain just below 0) still means nothing is owed →
	 * ABORT_SETTLED.
	 */
	public function testSettledWhenRemainNegativeResidual(): void
	{
		$this->assertSame(
			CommitDecision::ABORT_SETTLED,
			CommitDecision::decide(-0.001, 100.00)
		);
	}

	/**
	 * Just above the settled gate (remain > ε): there IS a balance to act on, so we no longer abort. Here
	 * the amount equals that balance → PROCEED_FULL.
	 */
	public function testNotSettledJustAboveEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(0.006, 0.006)
		);
	}

	/**
	 * Full settle: the amount exactly equals the remaining balance → PROCEED_FULL.
	 */
	public function testFullSettleWhenAmountEqualsRemain(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(100.00, 100.00)
		);
	}

	/**
	 * Full settle within tolerance: the amount is a few rappen short of the balance (|Δ| < ε) → treat as
	 * fully settled, PROCEED_FULL.
	 */
	public function testFullSettleWithinEpsilonBelow(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(100.00, 99.996)
		);
	}

	/**
	 * Full settle within tolerance: the amount is a few rappen over the balance (|Δ| < ε) → still fully
	 * settled (not an overpayment), PROCEED_FULL.
	 */
	public function testFullSettleWithinEpsilonAbove(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(100.00, 100.004)
		);
	}

	/**
	 * Boundary exactly at −ε: |Δ| == ε is inclusive (<=) → PROCEED_FULL, NOT a partial payment.
	 */
	public function testFullSettleExactlyAtMinusEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(100.00, 99.995)
		);
	}

	/**
	 * Boundary exactly at +ε: |Δ| == ε is inclusive (<=) → PROCEED_FULL, NOT an overpayment.
	 */
	public function testFullSettleExactlyAtPlusEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_FULL,
			CommitDecision::decide(100.00, 100.005)
		);
	}

	/**
	 * Partial payment: the amount is well below the balance → PROCEED_PARTIAL (post it, leave the invoice
	 * open; partial payments are legitimate).
	 */
	public function testPartialWhenAmountWellBelowRemain(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_PARTIAL,
			CommitDecision::decide(100.00, 60.00)
		);
	}

	/**
	 * Partial boundary: the first point strictly beyond −ε (Δ = −0.006) falls on the partial side →
	 * PROCEED_PARTIAL.
	 */
	public function testPartialJustBeyondMinusEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::PROCEED_PARTIAL,
			CommitDecision::decide(100.00, 99.994)
		);
	}

	/**
	 * Overpayment: the amount is well above the balance → ABORT_OVERPAY (flag for human review, do not
	 * silently overpay on a high-confidence auto-commit).
	 */
	public function testOverpayWhenAmountWellAboveRemain(): void
	{
		$this->assertSame(
			CommitDecision::ABORT_OVERPAY,
			CommitDecision::decide(100.00, 150.00)
		);
	}

	/**
	 * Overpay boundary: the first point strictly beyond +ε (Δ = +0.006) falls on the overpay side →
	 * ABORT_OVERPAY.
	 */
	public function testOverpayJustBeyondPlusEpsilon(): void
	{
		$this->assertSame(
			CommitDecision::ABORT_OVERPAY,
			CommitDecision::decide(100.00, 100.006)
		);
	}

	/**
	 * Pin the penny tolerance. CommitDecision is the single owner of this constant — the atomicity spike
	 * and PaymentCommitService read it (not a duplicated literal), so the pure verdict and the live commit
	 * agree on one threshold by reuse.
	 */
	public function testBalanceEpsilonConstantValue(): void
	{
		$this->assertSame(0.005, CommitDecision::BALANCE_EPSILON);
	}
}
