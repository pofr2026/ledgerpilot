<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\ProposalLayer;
use LedgerPilot\ProposalPlan;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\ProposalPlan::fromVerdict() — the PURE mapper from the engine's per-line
 * verdict to what the worker writes for that line: a proposal row to INSERT, a silent SKIP, or a MANUAL
 * fall-through (spec §4/§5). The DB-coupled facade runs the cascade (own-transfer → clearing → step0 →
 * L1 → L2) and emits a plain-data verdict; this class — Dolibarr-free, unit-testable — owns the single
 * place that knows "verdict X → proposal columns Y / skip / manual", i.e. the dual-mode column rule the
 * proposal DDL documents (step0 ⇒ fk_facture*; account ⇒ proposed_account). Pattern: EntryPlan / FeeSplitter.
 *
 * Outcome is a DISCRIMINATED result `{kind, row}`, never a bare null, so the worker can tell the two
 * "no proposal row" cases apart for its summary (the reviewer's load-bearing point):
 *   - SKIP   — an own transfer; deliberately filtered, queue → done, NO proposal, NO decision_log.
 *   - MANUAL — the cascade was exhausted; queue → done, NO proposal, the line feeds the corpus later via
 *              the accountant's manual posting (the abstain-with-candidates eval signal is a Dashboard/
 *              decision_log concern — C1 keeps no proposal row here).
 *   - PROPOSE — `row` carries the proposal columns (layer, proposed_account, fk_facture, fk_facture_fourn,
 *              score, candidate_set); row keys ARE the DDL column names (no aliases), the worker adds
 *              entity / fk_bank / status / date_creation.
 *
 * Verdict vocabulary (4 types; the facade builds them with these constants — single source):
 *   - own_transfer → SKIP.
 *   - none         → MANUAL.
 *   - invoice {fkFacture, fkFactureFourn} → PROPOSE step0. v0.1 step0 is SALES-ONLY (D1: purchase
 *     structured-ref falls through), so the facade always passes fkFactureFourn = 0 → fk_facture_fourn
 *     NULL; the mapping itself stays symmetric / purchase-ready (one of the two ids drives the column).
 *   - account {layer ∈ {l1,l2,clearing}, account, score?, candidateSet?} → PROPOSE account-track. Clearing
 *     is its own LAYER (decision E: a deterministic config match, its own eval stratum), not its own
 *     verdict type — same columns as L1/L2, just no score / candidate_set.
 *
 * Fail-safe (precision-first): an unrecognized type, or a propose-type verdict whose payload cannot name
 * a target (invoice with no id, account with an empty account or a non-account layer), degrades to MANUAL
 * — never a spurious PROPOSE. The facade is responsible for not emitting garbage; this is the backstop.
 */
final class ProposalPlanTest extends TestCase
{
	/** An own transfer is filtered: SKIP, no row. */
	public function testOwnTransferSkips(): void
	{
		$result = ProposalPlan::fromVerdict(array('type' => ProposalPlan::VERDICT_OWN_TRANSFER));

		$this->assertSame(ProposalPlan::SKIP, $result['kind']);
		$this->assertNull($result['row']);
	}

	/** Cascade exhausted: MANUAL, no row. */
	public function testNoneIsManual(): void
	{
		$result = ProposalPlan::fromVerdict(array('type' => ProposalPlan::VERDICT_NONE));

		$this->assertSame(ProposalPlan::MANUAL, $result['kind']);
		$this->assertNull($result['row']);
	}

	/** A sales invoice match → PROPOSE step0 on the invoice track (fk_facture set, proposed_account NULL). */
	public function testSalesInvoiceProposesStep0(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'           => ProposalPlan::VERDICT_INVOICE,
			'fkFacture'      => 240,
			'fkFactureFourn' => 0,
		));

		$this->assertSame(ProposalPlan::PROPOSE, $result['kind']);
		$this->assertSame(array(
			'layer'            => ProposalLayer::STEP0,
			'proposed_account' => null,
			'fk_facture'       => 240,
			'fk_facture_fourn' => null,
			'score'            => null,
			'candidate_set'    => null,
		), $result['row']);
	}

	/** A purchase invoice match → step0 on fk_facture_fourn (symmetric / purchase-ready; v0.1 facade won't emit it). */
	public function testPurchaseInvoiceProposesStep0(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'           => ProposalPlan::VERDICT_INVOICE,
			'fkFacture'      => 0,
			'fkFactureFourn' => 88,
		));

		$this->assertSame(ProposalPlan::PROPOSE, $result['kind']);
		$this->assertSame(ProposalLayer::STEP0, $result['row']['layer']);
		$this->assertNull($result['row']['fk_facture']);
		$this->assertSame(88, $result['row']['fk_facture_fourn']);
		$this->assertNull($result['row']['proposed_account']);
	}

	/** An L1 IBAN match → PROPOSE account track (proposed_account + score + candidate_set, fk_facture* NULL). */
	public function testL1AccountProposes(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'         => ProposalPlan::VERDICT_ACCOUNT,
			'layer'        => ProposalLayer::L1,
			'account'      => '6000',
			'score'        => 1.0,
			'candidateSet' => '[{"account":"6000","score":1.0}]',
		));

		$this->assertSame(ProposalPlan::PROPOSE, $result['kind']);
		$this->assertSame(array(
			'layer'            => ProposalLayer::L1,
			'proposed_account' => '6000',
			'fk_facture'       => null,
			'fk_facture_fourn' => null,
			'score'            => 1.0,
			'candidate_set'    => '[{"account":"6000","score":1.0}]',
		), $result['row']);
	}

	/** An L2 retriever match → account track at layer l2. */
	public function testL2AccountProposes(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'         => ProposalPlan::VERDICT_ACCOUNT,
			'layer'        => ProposalLayer::L2,
			'account'      => '6500',
			'score'        => 0.82,
			'candidateSet' => '[{"account":"6500","score":0.82}]',
		));

		$this->assertSame(ProposalPlan::PROPOSE, $result['kind']);
		$this->assertSame(ProposalLayer::L2, $result['row']['layer']);
		$this->assertSame('6500', $result['row']['proposed_account']);
		$this->assertSame(0.82, $result['row']['score']);
	}

	/** A clearing payout → account track at layer clearing, with no learned score / candidate_set. */
	public function testClearingAccountProposes(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'    => ProposalPlan::VERDICT_ACCOUNT,
			'layer'   => ProposalLayer::CLEARING,
			'account' => '1099',
		));

		$this->assertSame(ProposalPlan::PROPOSE, $result['kind']);
		$this->assertSame(array(
			'layer'            => ProposalLayer::CLEARING,
			'proposed_account' => '1099',
			'fk_facture'       => null,
			'fk_facture_fourn' => null,
			'score'            => null,
			'candidate_set'    => null,
		), $result['row']);
	}

	/** The two "no row" outcomes are DISTINCT kinds — the worker must count skips and manuals separately. */
	public function testOwnTransferAndNoneAreDistinctOutcomes(): void
	{
		$skip   = ProposalPlan::fromVerdict(array('type' => ProposalPlan::VERDICT_OWN_TRANSFER));
		$manual = ProposalPlan::fromVerdict(array('type' => ProposalPlan::VERDICT_NONE));

		$this->assertNull($skip['row']);
		$this->assertNull($manual['row']);
		$this->assertNotSame($skip['kind'], $manual['kind']);
	}

	/** The three outcome kinds are distinct constants. */
	public function testOutcomeKindsAreDistinct(): void
	{
		$all = array(ProposalPlan::PROPOSE, ProposalPlan::SKIP, ProposalPlan::MANUAL);
		$this->assertSame($all, array_values(array_unique($all)));
	}

	/** Fail-safe: an unrecognized verdict type degrades to MANUAL, never a spurious proposal. */
	public function testUnknownVerdictTypeIsManual(): void
	{
		$result = ProposalPlan::fromVerdict(array('type' => 'not_a_real_verdict'));

		$this->assertSame(ProposalPlan::MANUAL, $result['kind']);
		$this->assertNull($result['row']);
	}

	/** Fail-safe: an "invoice" verdict naming no invoice (both ids 0) is MANUAL, not an empty step0 row. */
	public function testInvoiceWithNoIdIsManual(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'           => ProposalPlan::VERDICT_INVOICE,
			'fkFacture'      => 0,
			'fkFactureFourn' => 0,
		));

		$this->assertSame(ProposalPlan::MANUAL, $result['kind']);
		$this->assertNull($result['row']);
	}

	/** Fail-safe: an "account" verdict with an empty account names no target → MANUAL. */
	public function testAccountWithEmptyAccountIsManual(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'    => ProposalPlan::VERDICT_ACCOUNT,
			'layer'   => ProposalLayer::L1,
			'account' => '',
		));

		$this->assertSame(ProposalPlan::MANUAL, $result['kind']);
		$this->assertNull($result['row']);
	}

	/** Fail-safe: an "account" verdict carrying a non-account layer (e.g. step0) cannot smuggle it in → MANUAL. */
	public function testAccountWithInvalidLayerIsManual(): void
	{
		$result = ProposalPlan::fromVerdict(array(
			'type'    => ProposalPlan::VERDICT_ACCOUNT,
			'layer'   => ProposalLayer::STEP0,
			'account' => '6000',
		));

		$this->assertSame(ProposalPlan::MANUAL, $result['kind']);
		$this->assertNull($result['row']);
	}
}
