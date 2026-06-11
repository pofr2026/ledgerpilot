<?php

namespace LedgerPilot;

/**
 * Step 0 structured-reference executor (spec §4): the SALES gate over the shared InvoiceByRefResolver.
 * The plan said WHAT reference to look up; this gates on direction/asymmetry and delegates the fetch +
 * verdict to the resolver (shared with RefInTitleExecutor — both by-ref strategies are siblings of the
 * neutral resolver, CLAUDE.md #2).
 *
 * SALES-only in v0.1 (D1): the gate keys on the plan's structuredRefKind === InvoiceMatchPlan::KIND_SWICO.
 * The plan already encodes every direction / asymmetry rule (§12.4), so this single key covers "sales
 * AND has a Swico token" — purchase yields 'qrr'|'scor'|null and sales-without-token yields null, all
 * blocked here without a DB hit. A purchase QRR/SCOR is the foreign creditor's reference and is not
 * invertible to our facture_fourn.ref in v0.1, so it falls through to the later fk_soc+amount sub-cycle.
 *
 * D5 (verified on dev): the Swico /10/ token equals facture.ref byte-for-byte (it is str_replace('/','',ref)
 * and mod_facture_terre's dash mask emits no slash — 0/174 refs carried one), so the resolver's
 * fetch(0, $token) hits uk_facture_ref exactly and needs no ref normalization. This holds while the
 * numbering addon emits no '/'; switching to a slash-capable mask (e.g. mod_facture_mercure) would
 * reactivate the need.
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
	 */
	public static function execute(\DoliDB $db, array $plan, int $lineFkSoc = 0): array
	{
		// Gate (D1): only a sales line carrying a Swico token has an invertible DB lookup in v0.1. The
		// plan already resolved direction + asymmetry, so this one key blocks every other case (purchase
		// / sales-without-token) before any DB hit.
		if (($plan['structuredRefKind'] ?? null) !== InvoiceMatchPlan::KIND_SWICO) {
			return ['matched' => false, 'fkFacture' => 0, 'fkFactureFourn' => 0];
		}

		return InvoiceByRefResolver::fetchAndDecide($db, (string) ($plan['structuredRef'] ?? ''), $lineFkSoc);
	}
}
