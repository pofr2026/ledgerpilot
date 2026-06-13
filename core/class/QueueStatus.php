<?php

namespace LedgerPilot;

/**
 * Lifecycle statuses of a llx_ledgerpilot_queue row, as PHP constants (the table stores status as a
 * varchar(16) — spec §5/§7). These are the single source the worker writes through; the DDL comment
 * documents the same set, and QueueStatusTest pins the values so the two cannot drift.
 *
 * A1 lifecycle — the queue tracks ENGINE work, the proposal tracks the human/commit workflow:
 *   PENDING → LEASED (atomic claim) → DONE (the engine produced an outcome — a proposal, an own-transfer
 *   skip, or a manual fall-through — for the line). A LEASED row whose lease goes stale (the worker
 *   crashed mid-processing) is reaped back to PENDING, or to DEAD once attempts hit the cutoff
 *   (RequeueDecision owns that threshold). DONE and DEAD are terminal tombstones: together with
 *   UNIQUE(fk_bank) they keep a line from being re-enqueued.
 */
final class QueueStatus
{
	/** Freshly enqueued, awaiting a claim (DDL default). */
	public const PENDING = 'pending';

	/** Claimed by a worker — lease_token / worker_id / claimed_at are set; reaped if the lease goes stale. */
	public const LEASED = 'leased';

	/** Terminal: the engine produced an outcome for the line (a proposal, a skip, or a manual fall-through). */
	public const DONE = 'done';

	/** Terminal: dead-letter — the line hit the attempts cutoff without a clean engine pass (RequeueDecision). */
	public const DEAD = 'dead';
}
