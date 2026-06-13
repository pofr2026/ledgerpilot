<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\QueueStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pins the llx_ledgerpilot_queue lifecycle status strings (spec §5/§7) to the values the DDL stores.
 *
 * The queue column is `status varchar(16) DEFAULT 'pending'`, documented as `pending|leased|done|dead`
 * (sql/llx_ledgerpilot_queue.sql). The worker writes those strings via these constants, so this test is
 * the drift-guard between the PHP contract and the schema: change a constant and this breaks, change the
 * DDL and a reviewer is sent here. There is no design freedom — the values ARE the schema — so the test
 * is a deliberate change-detector, not a behavioural assertion.
 *
 * Lifecycle (A1 — the queue tracks ENGINE work, the proposal tracks the human/commit workflow):
 *   pending → leased (claim) → done (engine produced a proposal / skip / manual outcome for the line)
 *                            ↘ dead (attempts hit the cutoff: the line repeatedly failed the engine)
 *   A stuck `leased` row (worker crashed mid-processing) is reaped back to `pending` (or to `dead` at the
 *   cutoff) — see RequeueDecision. `done` and `dead` are terminal tombstones that, with UNIQUE(fk_bank),
 *   keep a line from being re-enqueued.
 */
final class QueueStatusTest extends TestCase
{
	/** Freshly enqueued, awaiting a claim. DDL default. */
	public function testPending(): void
	{
		$this->assertSame('pending', QueueStatus::PENDING);
	}

	/** Claimed by a worker (lease_token / worker_id / claimed_at set); reapable if the lease goes stale. */
	public function testLeased(): void
	{
		$this->assertSame('leased', QueueStatus::LEASED);
	}

	/** Terminal: the engine produced an outcome (proposal, skip, or manual) for the line. */
	public function testDone(): void
	{
		$this->assertSame('done', QueueStatus::DONE);
	}

	/** Terminal: dead-letter — the line hit the attempts cutoff without a clean engine pass. */
	public function testDead(): void
	{
		$this->assertSame('dead', QueueStatus::DEAD);
	}

	/** The four constants are distinct (a copy-paste collapse would silently merge two states). */
	public function testStatusesAreDistinct(): void
	{
		$all = array(QueueStatus::PENDING, QueueStatus::LEASED, QueueStatus::DONE, QueueStatus::DEAD);
		$this->assertSame($all, array_values(array_unique($all)));
	}

	/** Every value fits the varchar(16) column (the DDL width is the hard limit). */
	public function testValuesFitColumnWidth(): void
	{
		foreach (array(QueueStatus::PENDING, QueueStatus::LEASED, QueueStatus::DONE, QueueStatus::DEAD) as $value) {
			$this->assertLessThanOrEqual(16, strlen($value));
		}
	}
}
