<?php

namespace LedgerPilot;

/**
 * The llx_ledgerpilot_proposal.layer vocabulary, as PHP constants (the table stores layer as a
 * varchar(16) — spec §4/§5). `layer` is the proposal's mode discriminator: it records which cascade step
 * produced the suggestion AND which columns carry the payload (the dual mode the proposal DDL documents):
 *   - STEP0   → invoice track: fk_facture / fk_facture_fourn set, proposed_account NULL.
 *   - L1 / L2 → account track: proposed_account set, fk_facture* NULL.
 *   - CLEARING → account track too (proposed_account = the processor's clearing account), but a distinct
 *     stratum from L1/L2 so offline-eval scores it on its own — it is a deterministic config match, not a
 *     learned suggestion (decision E).
 *
 * Single source for ProposalPlan (which emits it), the worker (which INSERTs it), and decision_log /
 * Dashboard (which read it). ProposalLayerTest pins the values so they cannot drift from the DDL.
 */
final class ProposalLayer
{
	/** Step 0 invoice match (structured QR/SCOR reference or ref-in-title → facture.ref). */
	public const STEP0 = 'step0';

	/** L1: exact counterparty-IBAN → account, from the approved-postings corpus (the strongest signal). */
	public const L1 = 'l1';

	/** L2: retriever (FULLTEXT candidate-gen → trigram rerank → top-K agreement) → account. */
	public const L2 = 'l2';

	/** Processor payout (TWINT/Stripe/card) → its configured clearing account (decision E). */
	public const CLEARING = 'clearing';
}
