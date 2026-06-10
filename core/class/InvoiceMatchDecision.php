<?php

namespace LedgerPilot;

/**
 * Pure verdict of the Step 0 structured-reference executor (spec §4). InvoiceMatchPlan decides WHAT
 * reference to look the invoice up by; the DB executor (a later sub-cycle) does the native Facture /
 * FactureFournisseur fetch + getRemainToPay() and hands the result here. This class decides — Dolibarr-
 * free, unit-testable — whether the fetched candidate is an ACCEPT (post the payment against it) or a
 * FALLTHROUGH (drop to the ref-in-title / fk_soc+amount sub-cycles, ultimately manual). Same split as
 * IbanAccountLookup (pure resolve() + DB lookup()): the verdict is pure here, the fetch is spike-verified.
 *
 * Precision-first: ACCEPT only when EVERY guard passes; anything else FALLTHROUGH.
 *   - fetchResult > 0 ............. native fetch() found exactly one invoice (0 = not found, <0 = error;
 *                                   the error is fail-soft, mirroring L1 / candidate-gen).
 *   - entity == expectedEntity .... strict company isolation (D3): native fetch()-by-ref scopes with
 *                                   getEntity('invoice'|'supplier_invoice'), which a sharing config could
 *                                   widen, so we re-check strictly against $conf->entity post-fetch.
 *   - status == STATUS_OPEN AND paye == PAYE_UNPAID ... the invoice is open. fetch() does NOT filter
 *                                   this, so the guard lives here (no posting to a draft or a paid invoice).
 *   - remainToPay > 0 ............. something is still owed (D2). The amount-vs-remainToPay comparison is
 *                                   NOT a gate (partial payments are legitimate, and a structured-ref hit
 *                                   is a strong key like L1 → suggest-and-human-approves); it is recorded
 *                                   for the Dashboard at a higher layer, not weighed in this verdict.
 *   - lineFkSoc == 0 OR == fk_soc . the fk_soc cross-check is OPTIONAL (D4): lineFkSoc == 0 means the
 *                                   line's counterparty is unknown (v0.1 typical — only an IBAN hash), so
 *                                   the check is skipped; when known, a mismatch falls through (guards a
 *                                   ref collision across socs).
 *
 * Purchase note (D1): the purchase QRR/SCOR is the foreign creditor's reference, not invertible to our
 * facture_fourn.ref in v0.1, so the executor never fetches on it — that routing lives in the DB executor.
 * decide() is direction-agnostic: it judges whatever the SALES Swico-token fetch returned.
 */
final class InvoiceMatchDecision
{
	/** Verdict: post the payment against the fetched invoice. */
	public const ACCEPT = 'accept';

	/** Verdict: drop to the later fallbacks / manual. */
	public const FALLTHROUGH = 'fallthrough';

	/** Native fk_statut of a validated (open) invoice — the only status payable here. */
	private const STATUS_OPEN = 1;

	/** Native paye flag of an unpaid invoice. */
	private const PAYE_UNPAID = 0;

	/**
	 * Decide whether a fetched structured-reference candidate is an ACCEPT or a FALLTHROUGH.
	 *
	 * @param  int $fetchResult     Native fetch() return: >0 found, 0 not found, <0 error.
	 * @param  array{entity?: int, status?: int, paye?: int, fk_soc?: int, remainToPay?: float} $invoice
	 *         The fetched invoice's relevant state (only read when $fetchResult > 0; an empty array is
	 *         tolerated when nothing was found / errored).
	 * @param  int $expectedEntity  $conf->entity — strict company isolation (D3).
	 * @param  int $lineFkSoc       The line's counterparty societe, or 0 when unknown (skip cross-check, D4).
	 * @return string               self::ACCEPT | self::FALLTHROUGH.
	 */
	public static function decide(int $fetchResult, array $invoice, int $expectedEntity, int $lineFkSoc): string
	{
		// Nothing fetched (0 = not found) or a fail-soft error (<0): there is no candidate to judge.
		if ($fetchResult <= 0) {
			return self::FALLTHROUGH;
		}

		// Strict company isolation — reject a cross-company invoice a sharing config may have returned.
		if ((int) ($invoice['entity'] ?? 0) !== $expectedEntity) {
			return self::FALLTHROUGH;
		}

		// Open for payment: validated and not yet paid.
		if ((int) ($invoice['status'] ?? 0) !== self::STATUS_OPEN || (int) ($invoice['paye'] ?? 0) !== self::PAYE_UNPAID) {
			return self::FALLTHROUGH;
		}

		// Still owes something (D2). Strictly positive — a zero balance is not payable.
		if ((float) ($invoice['remainToPay'] ?? 0.0) <= 0.0) {
			return self::FALLTHROUGH;
		}

		// Optional fk_soc cross-check (D4): only when the line's counterparty is known.
		if ($lineFkSoc !== 0 && $lineFkSoc !== (int) ($invoice['fk_soc'] ?? 0)) {
			return self::FALLTHROUGH;
		}

		return self::ACCEPT;
	}
}
