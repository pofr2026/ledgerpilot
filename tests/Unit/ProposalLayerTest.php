<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\ProposalLayer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the llx_ledgerpilot_proposal.layer vocabulary (spec §4/§5) to the values the DDL stores.
 *
 * `layer` is the proposal's mode discriminator (`varchar(16) NOT NULL`): it says which cascade step
 * produced the suggestion AND which columns carry the payload (the dual mode the proposal DDL documents):
 *   - STEP0   → invoice track: fk_facture / fk_facture_fourn set, proposed_account NULL.
 *   - L1 / L2 → account track: proposed_account set, fk_facture* NULL.
 *   - CLEARING (decision E) → account track too (proposed_account = the processor's clearing account),
 *     but a distinct stratum from L1/L2 so offline-eval scores it separately (it is a deterministic
 *     config match, not a learned suggestion).
 *
 * Drift-guard between the PHP constants (the single source ProposalPlan emits, the worker INSERTs, and
 * decision_log/Dashboard read) and the schema string — same posture as QueueStatusTest / ProposalStatusTest.
 */
final class ProposalLayerTest extends TestCase
{
	/** Step 0 invoice match (structured-ref or ref-in-title). */
	public function testStep0(): void
	{
		$this->assertSame('step0', ProposalLayer::STEP0);
	}

	/** L1: exact IBAN → account (the strongest learned signal). */
	public function testL1(): void
	{
		$this->assertSame('l1', ProposalLayer::L1);
	}

	/** L2: retriever (candidate-gen → rerank → top-K agreement) → account. */
	public function testL2(): void
	{
		$this->assertSame('l2', ProposalLayer::L2);
	}

	/** Processor payout → clearing account (decision E; its own eval stratum). */
	public function testClearing(): void
	{
		$this->assertSame('clearing', ProposalLayer::CLEARING);
	}

	/** All four layers are distinct. */
	public function testLayersAreDistinct(): void
	{
		$all = array(ProposalLayer::STEP0, ProposalLayer::L1, ProposalLayer::L2, ProposalLayer::CLEARING);
		$this->assertSame($all, array_values(array_unique($all)));
	}

	/** Every value fits the varchar(16) column ('clearing' is the longest at 8 chars). */
	public function testValuesFitColumnWidth(): void
	{
		foreach (array(ProposalLayer::STEP0, ProposalLayer::L1, ProposalLayer::L2, ProposalLayer::CLEARING) as $value) {
			$this->assertLessThanOrEqual(16, strlen($value));
		}
	}
}
