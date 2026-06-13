<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\ProposalStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full llx_ledgerpilot_proposal lifecycle (spec §5/§6) to the values the DDL stores.
 *
 * The proposal column is `status varchar(16) DEFAULT 'pending'`. The commit/reversal cycle introduced
 * APPROVED/BOOKED/REVERSED minimally; the queue/proposal cycle now completes the lifecycle by adding the
 * states the worker and the Dashboard own: PENDING (worker just created the suggestion), REJECTED (the
 * accountant declined it), and EXCEPTION (a commit aborted — settled/overpay — and is flagged for human
 * review, NOT re-queued; the abort→exception mapping PaymentCommitService deferred to this cycle).
 *
 * Like QueueStatusTest this is the drift-guard between the PHP constants (the single source the services
 * and Dashboard write through) and the schema string. Lifecycle:
 *   pending ─approve→ approved ─commit→ booked ─reverse→ reversed
 *      │                  └──abort(settled/overpay)→ exception
 *      └─reject→ rejected
 */
final class ProposalStatusTest extends TestCase
{
	/** Freshly suggested by the engine, awaiting the accountant's review. DDL default. */
	public function testPending(): void
	{
		$this->assertSame('pending', ProposalStatus::PENDING);
	}

	/** Accepted on the Dashboard — the only state PaymentCommitService::commit() acts on. */
	public function testApproved(): void
	{
		$this->assertSame('approved', ProposalStatus::APPROVED);
	}

	/** Declined by the accountant (a reject-without-alternative still feeds decision_log). */
	public function testRejected(): void
	{
		$this->assertSame('rejected', ProposalStatus::REJECTED);
	}

	/** Terminal: commit() posted the payment (the idempotency anchor, approved→booked). */
	public function testBooked(): void
	{
		$this->assertSame('booked', ProposalStatus::BOOKED);
	}

	/** Terminal: reverse() undid a booked commit (booked→reversed). */
	public function testReversed(): void
	{
		$this->assertSame('reversed', ProposalStatus::REVERSED);
	}

	/** A commit aborted (settled / overpay) → flagged for human review, the engine is NOT re-queued. */
	public function testException(): void
	{
		$this->assertSame('exception', ProposalStatus::EXCEPTION);
	}

	/** All six lifecycle states are distinct. */
	public function testStatusesAreDistinct(): void
	{
		$all = array(
			ProposalStatus::PENDING,
			ProposalStatus::APPROVED,
			ProposalStatus::REJECTED,
			ProposalStatus::BOOKED,
			ProposalStatus::REVERSED,
			ProposalStatus::EXCEPTION,
		);
		$this->assertSame($all, array_values(array_unique($all)));
	}

	/** Every value fits the varchar(16) column ('exception' is the longest at 9 chars). */
	public function testValuesFitColumnWidth(): void
	{
		$all = array(
			ProposalStatus::PENDING,
			ProposalStatus::APPROVED,
			ProposalStatus::REJECTED,
			ProposalStatus::BOOKED,
			ProposalStatus::REVERSED,
			ProposalStatus::EXCEPTION,
		);
		foreach ($all as $value) {
			$this->assertLessThanOrEqual(16, strlen($value));
		}
	}
}
