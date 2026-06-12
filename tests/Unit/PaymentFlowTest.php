<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\PaymentFlow;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\PaymentFlow — the value object that captures the sales/supplier asymmetry of
 * the Step 0 payment commit (spec §6) in ONE place (DRY home, mirroring spike1_commit.php's flow_config):
 * the invoice / payment classes, the bank_url link types + URL paths, and the bank sign. The
 * PaymentCommitService / PaymentReversalService and the atomicity spike read this instead of inlining the
 * native shapes.
 *
 * These unit tests pin the DISPATCH (sales → 'payment', purchase → 'payment_supplier' — the easy
 * mix-up) and the sign. The URL-path VALUES are mirrored from native addPaymentToBank() and are proven
 * equal to what the live commit actually writes by the durable drift-test in the atomicity spike (the
 * real oracle); the literals are pinned here too as a cheap typo regression-guard.
 */
final class PaymentFlowTest extends TestCase
{
	/**
	 * Sales flow: Facture / Paiement, the 'payment' link type + sales URL paths, money received → +1.
	 */
	public function testSalesFlowShape(): void
	{
		$flow = PaymentFlow::sales();

		$this->assertFalse($flow->isPurchase);
		$this->assertSame('Facture', $flow->invoiceClass);
		$this->assertSame('Paiement', $flow->paymentClass);
		$this->assertSame('payment', $flow->bankMode);
		$this->assertSame('/compta/paiement/card.php?id=', $flow->paymentUrlPath);
		$this->assertSame('/comm/card.php?socid=', $flow->companyUrlPath);
		$this->assertSame(1, $flow->bankSign);
	}

	/**
	 * Supplier flow: FactureFournisseur / PaiementFourn, the 'payment_supplier' link type + supplier URL
	 * paths, money paid out → -1.
	 */
	public function testPurchaseFlowShape(): void
	{
		$flow = PaymentFlow::purchase();

		$this->assertTrue($flow->isPurchase);
		$this->assertSame('FactureFournisseur', $flow->invoiceClass);
		$this->assertSame('PaiementFourn', $flow->paymentClass);
		$this->assertSame('payment_supplier', $flow->bankMode);
		$this->assertSame('/fourn/paiement/card.php?id=', $flow->paymentUrlPath);
		$this->assertSame('/fourn/card.php?socid=', $flow->companyUrlPath);
		$this->assertSame(-1, $flow->bankSign);
	}

	/**
	 * forPurchase() is the dispatcher the service uses (it carries a bool isPurchase from the proposal).
	 * Pin that true → purchase and false → sales, so the asymmetry can never be wired backwards.
	 */
	public function testForPurchaseDispatch(): void
	{
		$this->assertSame('PaiementFourn', PaymentFlow::forPurchase(true)->paymentClass);
		$this->assertSame('payment_supplier', PaymentFlow::forPurchase(true)->bankMode);

		$this->assertSame('Paiement', PaymentFlow::forPurchase(false)->paymentClass);
		$this->assertSame('payment', PaymentFlow::forPurchase(false)->bankMode);
	}

	/**
	 * The company link type and the payment label are flow-invariant (native addPaymentToBank writes
	 * 'company' / '(paiement)' for both sales and supplier).
	 */
	public function testCompanyLinkTypeAndLabelConstants(): void
	{
		$this->assertSame('company', PaymentFlow::COMPANY_LINK_TYPE);
		$this->assertSame('(paiement)', PaymentFlow::PAYMENT_LABEL);
	}
}
