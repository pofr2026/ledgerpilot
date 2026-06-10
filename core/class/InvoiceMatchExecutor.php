<?php

namespace LedgerPilot;

/**
 * DB-coupled half of the Step 0 structured-reference executor (spec §4) — the thin shell around the
 * pure InvoiceMatchDecision verdict, the same split as IbanAccountLookup (pure resolve() + DB lookup()).
 * Given the InvoiceMatchPlan output, it fetches the native invoice by its structured reference, reads
 * the balance, and delegates the ACCEPT/FALLTHROUGH decision. Verified by an integration spike
 * (docs/spikes/invoice_match_executor_check.php), not unit tests — the verdict logic is unit-tested in
 * InvoiceMatchDecision.
 *
 * SALES-only in v0.1 (D1): the gate keys on the plan's structuredRefKind === InvoiceMatchPlan::KIND_SWICO.
 * The plan already encodes every direction / asymmetry rule (§12.4), so this single key covers "sales
 * AND has a Swico token" — purchase yields 'qrr'|'scor'|null and sales-without-token yields null, all
 * blocked here without a DB hit. A purchase QRR/SCOR is the foreign creditor's reference and is not
 * invertible to our facture_fourn.ref in v0.1, so it falls through to the later fk_soc+amount sub-cycle.
 *
 * D5 (verified on dev): the Swico /10/ token equals facture.ref byte-for-byte (it is str_replace('/','',ref)
 * and mod_facture_terre's dash mask emits no slash — 0/174 refs carried one), so Facture::fetch(0, $token)
 * hits uk_facture_ref exactly and needs no ref normalization. This holds while the numbering addon emits
 * no '/'; switching to a slash-capable mask (e.g. mod_facture_mercure) would reactivate the need.
 */
final class InvoiceMatchExecutor
{
	/**
	 * Run the SALES structured-reference lookup for a planned bank line.
	 *
	 * @param  \DoliDB $db        Open Dolibarr database handle.
	 * @param  array{invoiceType?: string, structuredRef?: string|null, structuredRefKind?: string|null} $plan
	 *         The InvoiceMatchPlan::forLine() output.
	 * @param  int     $lineFkSoc The line's counterparty societe, or 0 when unknown (skips the D4
	 *                            cross-check).
	 * @return array{matched: bool, fkFacture: int, fkFactureFourn: int}
	 *         matched=false is the fall-through signal for the pipeline (drop to the later fallbacks).
	 *         fkFactureFourn stays 0 in v0.1 (purchase has no invertible lookup yet) but is kept for
	 *         invoice symmetry with decision_log / proposal.
	 */
	public static function execute(\DoliDB $db, array $plan, int $lineFkSoc = 0): array
	{
		$noMatch = ['matched' => false, 'fkFacture' => 0, 'fkFactureFourn' => 0];

		// Gate (D1): only a sales line carrying a Swico token has an invertible DB lookup in v0.1. The
		// plan already resolved direction + asymmetry, so this one key blocks every other case (purchase
		// / sales-without-token) before any DB hit.
		if (($plan['structuredRefKind'] ?? null) !== InvoiceMatchPlan::KIND_SWICO) {
			return $noMatch;
		}

		$ref = (string) ($plan['structuredRef'] ?? '');

		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$facture = new \Facture($db);
		// fetch(rowid=0, ref) takes the by-ref branch (uk_facture_ref). Native scopes it with
		// getEntity('invoice'); we re-check entity strictly in decide() (D3). fetch() has no LIMIT, so a
		// widened getEntity() with the same ref in two entities yields the first row — if that row is a
		// foreign entity, decide() rejects it (→ fall-through, a safe miss, never a wrong ACCEPT). On a
		// single-entity install this cannot arise.
		$fetchResult = $facture->fetch(0, $ref);

		$invoice = [];
		if ($fetchResult > 0) {
			// Field names confirmed against native Facture::fetch (status, not the deprecated statut).
			$invoice = [
				'entity'      => (int) $facture->entity,
				'status'      => (int) $facture->status,
				'paye'        => (int) $facture->paye,
				'fk_soc'      => (int) $facture->socid,
				'remainToPay' => (float) $facture->getRemainToPay(0),
			];
		}

		$verdict = InvoiceMatchDecision::decide($fetchResult, $invoice, (int) $conf->entity, $lineFkSoc);
		if ($verdict !== InvoiceMatchDecision::ACCEPT) {
			return $noMatch;
		}

		return ['matched' => true, 'fkFacture' => (int) $facture->id, 'fkFactureFourn' => 0];
	}
}
