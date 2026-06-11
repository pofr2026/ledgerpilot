<?php

namespace LedgerPilot;

/**
 * Step 0 ref-in-title fallback executor (spec §4): when the structured-reference lookup misses, try to
 * recognise a known open SALES-invoice ref inside the bank line's free-text title, then resolve + decide
 * via the shared InvoiceByRefResolver (sibling of InvoiceMatchExecutor — both depend on the neutral
 * resolver, never on each other). The pure title↔refs matching is RefInTitleMatcher; this is the thin
 * DB-context shell, spike-verified.
 *
 * SALES-ONLY direction gate (A, mirrors InvoiceMatchExecutor's D1): the pre-flight ref index holds OPEN
 * SALES invoices (fk_statut=1 AND paye=0 on llx_facture), so this fallback must only fire on an incoming
 * (credit) line. Otherwise an outgoing supplier payment whose title happens to contain a token equal to
 * an open sales-invoice ref would match that sales invoice. The gate reuses
 * InvoiceMatchPlan::isSalesDirection() so the direction rule is single-sourced — it does NOT rely on the
 * wiring remembering to call this only for sales.
 *
 * The title is normalized HERE (LabelNormalizer) so the matcher receives the same canonical space as the
 * indexed refs; the ref index is built once per batch by RefInTitleMatcher::buildRefIndex().
 */
final class RefInTitleExecutor
{
	/**
	 * Run the ref-in-title fallback for a bank line.
	 *
	 * @param  \DoliDB                $db        Open Dolibarr database handle.
	 * @param  float                  $amount    The bank line amount, signed (the direction gate).
	 * @param  string                 $title     The bank line's free-text title (raw; normalized here).
	 * @param  array<string, string>  $refIndex  Per-batch RefInTitleMatcher::buildRefIndex() output.
	 * @param  int                    $lineFkSoc The line's counterparty societe, or 0 when unknown
	 *                                          (skips the D4 cross-check).
	 * @return array{matched: bool, fkFacture: int, fkFactureFourn: int}
	 *         matched=false is the pipeline's fall-through signal (drop to the next strategy / manual).
	 */
	public static function execute(\DoliDB $db, float $amount, string $title, array $refIndex, int $lineFkSoc = 0): array
	{
		$noMatch = ['matched' => false, 'fkFacture' => 0, 'fkFactureFourn' => 0];

		// Direction gate (A): the ref index is open SALES invoices, so only an incoming line is eligible.
		if (!InvoiceMatchPlan::isSalesDirection($amount)) {
			return $noMatch;
		}

		$matchedRef = RefInTitleMatcher::match(LabelNormalizer::normalize($title), $refIndex);
		if ($matchedRef === null) {
			return $noMatch;
		}

		return InvoiceByRefResolver::fetchAndDecide($db, $matchedRef, $lineFkSoc);
	}
}
