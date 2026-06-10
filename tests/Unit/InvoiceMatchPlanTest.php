<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\InvoiceMatchPlan;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\InvoiceMatchPlan::forLine() — the PURE front of Step 0 (spec §4): from a
 * bank line (signed amount) plus the keystone line_ref fields, decide the invoice DIRECTION and which
 * STRUCTURED reference to look the invoice up by. The actual DB lookup (facture by ref, fk_soc
 * cross-check, getRemainToPay balance) and the ref-in-title / fk_soc+amount fallbacks are later
 * sub-cycles (DB / spike); this class only plans the highest-priority structured-reference attempt.
 *
 * The load-bearing rule is the sales/purchase ASYMMETRY established by code-reading native Dolibarr
 * (spec §12.4):
 *   - SALES (CRDT, money in): native Dolibarr emits QR-bill reference type NON (no QRR); the invoice
 *     ref travels in the Swico S1 `/10/` token (keystone invoice_ref_token) → facture.ref. So a sales
 *     line is matched by the Swico token and IGNORES any structured QRR/SCOR.
 *   - PURCHASE (DBIT, money out): a foreign issuer may use a real QRR/SCOR (keystone structured_ref).
 *     So a purchase line is matched by the structured_ref and IGNORES the Swico token.
 *
 * Direction comes from the amount sign (>= 0 → sales / incoming, < 0 → purchase / outgoing). The
 * known v0.1 edge — a supplier refund is CRDT and a customer refund is DBIT, so a refund lands in the
 * wrong lane — is accepted (it falls through to manual), not special-cased here.
 *
 * Return shape: ['invoiceType' => 'sales'|'purchase', 'structuredRef' => ?string,
 * 'structuredRefKind' => 'swico'|'qrr'|'scor'|null].
 */
final class InvoiceMatchPlanTest extends TestCase
{
	/**
	 * A credit line is SALES and is keyed by the Swico token — even when a structured QRR/SCOR is also
	 * present, sales ignores it (§12.4: native sales carry the ref in the Swico token, not a QRR).
	 */
	public function testCreditLineIsSalesKeyedBySwicoToken(): void
	{
		$this->assertSame(
			['invoiceType' => 'sales', 'structuredRef' => 'TC1-2605-0158', 'structuredRefKind' => 'swico'],
			InvoiceMatchPlan::forLine(15.02, 'RF18539007547034', 'SCOR', 'TC1-2605-0158')
		);
	}

	/**
	 * A debit line is PURCHASE and is keyed by the structured QRR reference.
	 */
	public function testDebitLineIsPurchaseKeyedByQrr(): void
	{
		$this->assertSame(
			['invoiceType' => 'purchase', 'structuredRef' => '210000000003139471430009017', 'structuredRefKind' => 'qrr'],
			InvoiceMatchPlan::forLine(-210.50, '210000000003139471430009017', 'QRR', null)
		);
	}

	/**
	 * A debit line with a SCOR structured reference → purchase, kind 'scor' (the kind mirrors the
	 * keystone structured_ref_type, lower-cased).
	 */
	public function testDebitLineWithScorReference(): void
	{
		$this->assertSame(
			['invoiceType' => 'purchase', 'structuredRef' => 'RF18539007547034', 'structuredRefKind' => 'scor'],
			InvoiceMatchPlan::forLine(-50.0, 'RF18539007547034', 'SCOR', null)
		);
	}

	/**
	 * Asymmetry, sales side (load-bearing): a sales line with NO Swico token has no structured key —
	 * even though a QRR/SCOR structured_ref is present, sales does not use it, so structuredRef is null
	 * (the executor falls through to the title-ref / fk_soc+amount strategies).
	 */
	public function testSalesIgnoresStructuredRefWhenNoSwicoToken(): void
	{
		$this->assertSame(
			['invoiceType' => 'sales', 'structuredRef' => null, 'structuredRefKind' => null],
			InvoiceMatchPlan::forLine(15.0, 'RF18539007547034', 'SCOR', null)
		);
	}

	/**
	 * Asymmetry, purchase side (load-bearing): a purchase line with NO structured_ref has no structured
	 * key — even though a Swico token is present, purchase does not use it, so structuredRef is null.
	 */
	public function testPurchaseIgnoresSwicoTokenWhenNoStructuredRef(): void
	{
		$this->assertSame(
			['invoiceType' => 'purchase', 'structuredRef' => null, 'structuredRefKind' => null],
			InvoiceMatchPlan::forLine(-50.0, null, null, 'TC1-2605-0158')
		);
	}

	/**
	 * Edge case (review IMP-1): a purchase line can have a structuredRef present but no
	 * structuredRefType (the keystone could in principle write a ref without a recognized type).
	 * structuredRefKind must normalize to null in that case — not strtolower(null) === '', which would
	 * be an undocumented fourth state alongside the documented 'qrr'|'scor'|null.
	 */
	public function testPurchaseRefWithoutTypeYieldsNullKind(): void
	{
		$this->assertSame(
			['invoiceType' => 'purchase', 'structuredRef' => 'RF18539007547034', 'structuredRefKind' => null],
			InvoiceMatchPlan::forLine(-50.0, 'RF18539007547034', null, null)
		);
	}

	/**
	 * Boundary (review IMP-2): amount == 0.0 is sales — the >= in the direction check (L41) is a
	 * deliberate choice ("incoming"), not >. Pin the boundary so it can't silently drift to purchase
	 * in a future refactor.
	 */
	public function testZeroAmountIsSales(): void
	{
		$this->assertSame(
			['invoiceType' => 'sales', 'structuredRef' => null, 'structuredRefKind' => null],
			InvoiceMatchPlan::forLine(0.0, null, null, null)
		);
	}
}
