<?php

namespace LedgerPilot;

/**
 * Pure front of Step 0 (spec §4): from a bank line (signed amount) plus the keystone line_ref fields,
 * decide the invoice DIRECTION and which STRUCTURED reference to look the invoice up by. Dolibarr-free,
 * unit-testable.
 *
 * It plans only the highest-priority attempt — the structured-reference lookup. The actual DB lookup
 * (facture by ref, fk_soc cross-check, getRemainToPay balance) and the ref-in-title / fk_soc+amount
 * fallbacks are later sub-cycles.
 *
 * Sales/purchase ASYMMETRY (the load-bearing rule, code-read from native Dolibarr, spec §12.4):
 *   - SALES (credit, money in): native Dolibarr emits QR-bill reference type NON (no QRR); the invoice
 *     ref travels in the Swico S1 `/10/` token (keystone invoice_ref_token) → facture.ref. So a sales
 *     line is keyed by the Swico token and IGNORES any structured QRR/SCOR.
 *   - PURCHASE (debit, money out): a foreign issuer may use a real QRR/SCOR (keystone structured_ref).
 *     So a purchase line is keyed by the structured_ref and IGNORES the Swico token.
 *
 * Direction comes from the amount sign (>= 0 → sales / incoming, < 0 → purchase / outgoing). The known
 * v0.1 edge — a supplier refund is a credit and a customer refund a debit, so a refund lands in the
 * wrong lane — is accepted (it falls through to manual), not special-cased.
 */
final class InvoiceMatchPlan
{
	/**
	 * structuredRefKind for a sales line: the reference is the Swico S1 `/10/` token (= facture.ref).
	 * Shared with InvoiceMatchExecutor, which gates its DB lookup on this single key (D1) — keeping the
	 * literal in one place rather than hardcoding 'swico' on both sides.
	 */
	public const KIND_SWICO = 'swico';

	/**
	 * Whether a bank line's amount is in the SALES (credit / money in) direction. The single source of
	 * the direction rule: forLine() routes on it, and RefInTitleExecutor gates its sales-only fallback on
	 * it (so the boundary — zero counts as sales, "incoming" — lives in one place, pinned by
	 * testZeroAmountIsSales). A debit (< 0) is the purchase direction.
	 *
	 * @param  float $amount The bank line amount, signed.
	 * @return bool          True for sales / incoming (>= 0), false for purchase / outgoing.
	 */
	public static function isSalesDirection(float $amount): bool
	{
		return $amount >= 0.0;
	}

	/**
	 * Plan the direction and structured-reference key for a bank line.
	 *
	 * @param  float       $amount            The bank line amount, signed (>= 0 incoming, < 0 outgoing).
	 * @param  string|null $structuredRef     The keystone structured QR/SCOR reference, if any.
	 * @param  string|null $structuredRefType The keystone structured_ref_type ('QRR'|'SCOR'), if any.
	 * @param  string|null $invoiceRefToken   The keystone Swico `/10/` token (the sales invoice ref).
	 * @return array{invoiceType: string, structuredRef: string|null, structuredRefKind: string|null}
	 *         invoiceType is 'sales'|'purchase'; structuredRef is the reference to look up by (or null
	 *         when the direction's reference is absent → the executor falls through to the fallbacks);
	 *         structuredRefKind is 'swico' (sales), 'qrr'|'scor' (purchase), or null — including the
	 *         purchase edge where structuredRef is present but structuredRefType is missing/empty.
	 */
	public static function forLine(float $amount, ?string $structuredRef, ?string $structuredRefType, ?string $invoiceRefToken): array
	{
		if (self::isSalesDirection($amount)) {
			// SALES (credit / money in): keyed by the Swico token, never the structured QRR/SCOR.
			$ref = ($invoiceRefToken ?? '') !== '' ? $invoiceRefToken : null;

			return [
				'invoiceType'       => 'sales',
				'structuredRef'     => $ref,
				'structuredRefKind' => $ref !== null ? self::KIND_SWICO : null,
			];
		}

		// PURCHASE (debit / money out): keyed by the structured QRR/SCOR, never the Swico token.
		$ref = ($structuredRef ?? '') !== '' ? $structuredRef : null;

		// A ref present without a recognized type normalizes to a null kind — strtolower('') would
		// otherwise produce '', an undocumented fourth state alongside 'qrr'|'scor'|null.
		$kind = null;
		if ($ref !== null && ($structuredRefType ?? '') !== '') {
			$kind = strtolower($structuredRefType);
		}

		return [
			'invoiceType'       => 'purchase',
			'structuredRef'     => $ref,
			'structuredRefKind' => $kind,
		];
	}
}
