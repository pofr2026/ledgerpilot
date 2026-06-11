<?php

namespace LedgerPilot;

/**
 * Shared DB core of the Step 0 by-ref strategies (spec §4): fetch a native sales invoice by its ref,
 * read the balance, and hand the result to the pure InvoiceMatchDecision verdict. Both by-ref executors
 * depend on this neutral resolver (the EntryPlan/FeeSplitter shared-helper pattern, CLAUDE.md #2) rather
 * than on each other — InvoiceMatchExecutor (structured-ref, primary) and RefInTitleExecutor (ref-in-
 * title, fallback) are siblings, so the fallback never imports the primary.
 *
 * DB-coupled: verified by an integration spike, not unit tests — the verdict logic is unit-tested in
 * InvoiceMatchDecision.
 *
 * Native field mapping confirmed against Dolibarr 23 Facture::fetch: entity, status (not the deprecated
 * statut), paye, socid (= fk_soc), getRemainToPay(0) for same-currency. fetch(0, $ref) takes the by-ref
 * branch (uk_facture_ref). Native scopes it with getEntity('invoice'), which a sharing config could
 * widen, so D3 strict-entity is re-checked in decide(); fetch() has no LIMIT, so a widened getEntity()
 * with the same ref in two entities yields the first row — if that row is a foreign entity, decide()
 * rejects it (→ fall-through, a safe miss, never a wrong ACCEPT). On a single-entity install this cannot
 * arise.
 */
final class InvoiceByRefResolver
{
	/**
	 * Fetch the sales invoice with the given ref and decide ACCEPT/FALLTHROUGH against the line.
	 *
	 * @param  \DoliDB $db        Open Dolibarr database handle.
	 * @param  string  $ref       The sales invoice ref to fetch by (uk_facture_ref).
	 * @param  int     $lineFkSoc The line's counterparty societe, or 0 when unknown (skips the D4
	 *                            cross-check).
	 * @return array{matched: bool, fkFacture: int, fkFactureFourn: int}
	 *         matched=false is the caller's fall-through signal. fkFactureFourn stays 0 in v0.1 (purchase
	 *         has no invertible lookup yet) but is kept for invoice symmetry with decision_log / proposal.
	 */
	public static function fetchAndDecide(\DoliDB $db, string $ref, int $lineFkSoc = 0): array
	{
		$noMatch = ['matched' => false, 'fkFacture' => 0, 'fkFactureFourn' => 0];

		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$facture = new \Facture($db);
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
