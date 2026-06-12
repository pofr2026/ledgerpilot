<?php

namespace LedgerPilot;

/**
 * The sales/supplier asymmetry of the Step 0 payment commit (spec §6) captured in ONE place — the DRY
 * home, mirroring the flow_config pattern first used in bankimport's spike1_commit.php (a separate repo;
 * this module's live counterpart is docs/spikes/commit_reversal_atomicity_check.php). The two flows are
 * mirror images; everything that
 * differs between posting a customer payment (Facture + Paiement) and a supplier payment
 * (FactureFournisseur + PaiementFourn) lives here so PaymentCommitService / PaymentReversalService and
 * the atomicity spike stay single-sourced.
 *
 * The bank_url link shapes are mirrored 1:1 from native Paiement::addPaymentToBank() (the 'payment' /
 * 'payment_supplier' link onto the bank line + the 'company' link to the third party). There is no clean
 * native source to import them from, so they are hand-mirrored here — but NOT left to a passive comment:
 * the atomicity spike's durable drift-test commits via the native "naive" path and asserts that the
 * bank_url.type / url it writes equal these constants, so a Dolibarr upgrade that changes a URL template
 * breaks the spike (spec §8 durable regression-guard).
 *
 * URL paths are stored WITHOUT the DOL_URL_ROOT prefix so this class stays pure / unit-testable with no
 * live Dolibarr; the consumer prefixes DOL_URL_ROOT (exactly as addPaymentToBank does:
 * DOL_URL_ROOT.'/compta/paiement/card.php?id=').
 */
final class PaymentFlow
{
	/** bank_url type of the invoice↔third-party link — flow-invariant (native writes it for both). */
	public const COMPANY_LINK_TYPE = 'company';

	/** bank_url label native addPaymentToBank() writes on the payment link — flow-invariant. */
	public const PAYMENT_LABEL = '(paiement)';

	/**
	 * @param bool   $isPurchase     false = sales (customer), true = purchase (supplier).
	 * @param string $invoiceClass   Native invoice class to fetch ('Facture' | 'FactureFournisseur').
	 * @param string $paymentClass   Native payment class to create ('Paiement' | 'PaiementFourn').
	 * @param string $bankMode       addPaymentToBank() mode AND the payment link's bank_url type — one
	 *                               native value plays both roles ('payment' | 'payment_supplier').
	 * @param string $paymentUrlPath bank_url url of the payment link, sans DOL_URL_ROOT.
	 * @param string $companyUrlPath bank_url url of the company link, sans DOL_URL_ROOT.
	 * @param int    $bankSign       Sign of the imported bank line for this flow (+1 money received for
	 *                               sales, -1 money paid out for supplier) — the D-C sign guard checks the
	 *                               line's sign against this before posting.
	 */
	public function __construct(
		public readonly bool $isPurchase,
		public readonly string $invoiceClass,
		public readonly string $paymentClass,
		public readonly string $bankMode,
		public readonly string $paymentUrlPath,
		public readonly string $companyUrlPath,
		public readonly int $bankSign
	) {
	}

	/** Customer payment: Facture + Paiement, 'payment' link, money received (+1). */
	public static function sales(): self
	{
		return new self(
			false,
			'Facture',
			'Paiement',
			'payment',
			'/compta/paiement/card.php?id=',
			'/comm/card.php?socid=',
			1
		);
	}

	/** Supplier payment: FactureFournisseur + PaiementFourn, 'payment_supplier' link, money paid out (-1). */
	public static function purchase(): self
	{
		return new self(
			true,
			'FactureFournisseur',
			'PaiementFourn',
			'payment_supplier',
			'/fourn/paiement/card.php?id=',
			'/fourn/card.php?socid=',
			-1
		);
	}

	/**
	 * Dispatcher the service uses — it carries a bool isPurchase derived from the proposal's layer/track.
	 *
	 * @param  bool $isPurchase
	 * @return self
	 */
	public static function forPurchase(bool $isPurchase): self
	{
		return $isPurchase ? self::purchase() : self::sales();
	}
}
