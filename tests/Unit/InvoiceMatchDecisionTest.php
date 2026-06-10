<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\InvoiceMatchDecision;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\InvoiceMatchDecision::decide() — the PURE verdict of the Step 0
 * structured-reference executor (spec §4). InvoiceMatchPlan said WHAT reference to look up; the DB
 * executor (next sub-cycle) does the native Facture / FactureFournisseur fetch + getRemainToPay() and
 * hands the result here. This class decides — Dolibarr-free, unit-testable — whether that fetched
 * candidate is an ACCEPT (post the payment against it) or a FALLTHROUGH (drop to the later ref-in-title
 * / fk_soc+amount sub-cycles, ultimately manual). Same split as IbanAccountLookup (pure resolve() +
 * DB lookup()): the verdict is pure here, the fetch is spike-verified there.
 *
 * The verdict is precision-first — ACCEPT only when EVERY guard passes; anything else FALLTHROUGH:
 *   - fetchResult > 0 ............. native fetch() found exactly one invoice (0 = not found, <0 = error;
 *                                   the error case is fail-soft, mirroring L1/candidate-gen).
 *   - entity == expectedEntity .... strict company isolation (D3). Native fetch()-by-ref scopes with
 *                                   getEntity('invoice'|'supplier_invoice'), which a sharing config could
 *                                   widen; we re-check strictly against $conf->entity post-fetch so a
 *                                   shared invoice is never silently matched.
 *   - status == 1 AND paye == 0 ... the invoice is open (native fk_statut=1 validated, not paid).
 *                                   fetch() does NOT filter this, so the guard lives here (no posting to
 *                                   a draft or an already-paid invoice).
 *   - remainToPay > 0 ............. something is still owed (D2). The amount-vs-remainToPay comparison is
 *                                   NOT a gate (partial payments are legitimate, and a structured-ref hit
 *                                   is a strong key like L1 → suggest-and-human-approves); it is recorded
 *                                   for the Dashboard at a higher layer, not weighed in this verdict.
 *   - lineFkSoc == 0 OR == fk_soc . the fk_soc cross-check is OPTIONAL (D4): in v0.1 the line's
 *                                   counterparty is usually unknown at this stage (only an IBAN hash), so
 *                                   lineFkSoc == 0 means "unknown → skip the check"; when it IS known, a
 *                                   mismatch falls through (guards against a ref collision across socs).
 *
 * Purchase note (D1): the purchase QRR/SCOR is the foreign creditor's reference, not invertible to our
 * facture_fourn.ref in v0.1, so the executor never fetches on it — it falls through to fk_soc+amount.
 * That routing lives in the DB executor; decide() itself is direction-agnostic (it judges whatever the
 * SALES Swico-token fetch returned).
 */
final class InvoiceMatchDecisionTest extends TestCase
{
	/**
	 * Happy path: a found, open, same-entity invoice whose fk_soc matches the (known) line counterparty
	 * and still has a balance → ACCEPT.
	 */
	public function testFoundOpenMatchingAccepts(): void
	{
		$invoice = ['entity' => 1, 'status' => 1, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 210.50];

		$this->assertSame(
			InvoiceMatchDecision::ACCEPT,
			InvoiceMatchDecision::decide(1, $invoice, 1, 42)
		);
	}

	/**
	 * D4: lineFkSoc == 0 means the line's counterparty is unknown (v0.1 typical — only an IBAN hash), so
	 * the fk_soc cross-check is skipped and an otherwise-valid candidate is ACCEPTED.
	 */
	public function testFkSocUnknownSkipsCrossCheckAndAccepts(): void
	{
		$invoice = ['entity' => 1, 'status' => 1, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 210.50];

		$this->assertSame(
			InvoiceMatchDecision::ACCEPT,
			InvoiceMatchDecision::decide(1, $invoice, 1, 0)
		);
	}

	/**
	 * D4: a KNOWN line counterparty that does NOT match the invoice's fk_soc → FALLTHROUGH (the
	 * structured ref hit an invoice belonging to a different soc — a ref collision; do not post).
	 */
	public function testFkSocMismatchFallsThrough(): void
	{
		$invoice = ['entity' => 1, 'status' => 1, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 210.50];

		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(1, $invoice, 1, 99)
		);
	}

	/**
	 * fetchResult == 0 (no invoice with that ref) → FALLTHROUGH. The $invoice payload is irrelevant when
	 * nothing was found, so an empty array must be tolerated.
	 */
	public function testNotFoundFallsThrough(): void
	{
		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(0, [], 1, 0)
		);
	}

	/**
	 * fetchResult < 0 (native fetch error) → FALLTHROUGH, fail-soft (same posture as a query error in
	 * IbanAccountLookup / CandidateGenerator: the line drops to the fallbacks / manual, never throws).
	 */
	public function testFetchErrorFallsThrough(): void
	{
		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(-1, [], 1, 0)
		);
	}

	/**
	 * An already-paid invoice (paye == 1) → FALLTHROUGH (do not double-post).
	 */
	public function testPaidInvoiceFallsThrough(): void
	{
		$invoice = ['entity' => 1, 'status' => 1, 'paye' => 1, 'fk_soc' => 42, 'remainToPay' => 0.0];

		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(1, $invoice, 1, 0)
		);
	}

	/**
	 * A draft / non-validated invoice (status != 1) → FALLTHROUGH (only validated invoices are open for
	 * payment).
	 */
	public function testDraftInvoiceFallsThrough(): void
	{
		$invoice = ['entity' => 1, 'status' => 0, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 210.50];

		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(1, $invoice, 1, 0)
		);
	}

	/**
	 * D2: nothing left to pay (remainToPay == 0, e.g. closed-with-discount where getRemainToPay() returns
	 * 0 on an otherwise-validated row) → FALLTHROUGH. The boundary is exclusive: only a strictly positive
	 * balance is payable.
	 */
	public function testZeroRemainToPayFallsThrough(): void
	{
		$invoice = ['entity' => 1, 'status' => 1, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 0.0];

		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(1, $invoice, 1, 0)
		);
	}

	/**
	 * D3: a found, open invoice in a DIFFERENT entity → FALLTHROUGH. Native fetch()-by-ref uses
	 * getEntity(), so a sharing config can return a cross-company invoice; the strict expectedEntity
	 * re-check rejects it (the corpus / posting posture stays company-isolated like L1 / candidate-gen).
	 */
	public function testWrongEntityFallsThrough(): void
	{
		$invoice = ['entity' => 2, 'status' => 1, 'paye' => 0, 'fk_soc' => 42, 'remainToPay' => 210.50];

		$this->assertSame(
			InvoiceMatchDecision::FALLTHROUGH,
			InvoiceMatchDecision::decide(1, $invoice, 1, 0)
		);
	}
}
