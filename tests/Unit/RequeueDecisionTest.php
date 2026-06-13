<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\QueueStatus;
use LedgerPilot\RequeueDecision;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\RequeueDecision::decide() — the PURE attempts-cutoff verdict that routes a
 * queue row that did NOT finish cleanly either back to PENDING (retry) or to DEAD (dead-letter), spec
 * §5/§7. Like CommitDecision owns BALANCE_EPSILON, this class is the SINGLE owner of the cutoff rule, so
 * the two consumers can never disagree:
 *   - failure-release: the engine threw on a claimed line → the worker calls decide() in PHP.
 *   - reap of a stale lease: the SQL reap mirrors the same rule, parametrized by the SAME max-attempts
 *     constant (the live spike proves the two paths agree); decide() is the canonical statement of it.
 *
 * `attempts` is incremented at CLAIM time (not on failure), so a worker that crashes mid-processing still
 * burns an attempt — that is what stops a poison row from looping forever. decide() therefore reads the
 * already-incremented count: at maxAttempts the budget is spent → DEAD; below it → one more PENDING try.
 *
 * Precondition: maxAttempts >= 1 (a 0/negative cutoff would dead-letter every line on its first claim).
 * Validated at the config edge (getDolGlobalInt floored to DEFAULT_MAX_ATTEMPTS), documented here, NOT
 * defended in the pure verdict — the same posture as AccountMatcher's K >= 1.
 */
final class RequeueDecisionTest extends TestCase
{
	/** Below the cutoff: a first failed/crashed attempt goes back to the queue for another try. */
	public function testBelowCutoffRequeuesPending(): void
	{
		$this->assertSame(QueueStatus::PENDING, RequeueDecision::decide(1, 3));
	}

	/** One short of the cutoff still retries (the cutoff is the spend boundary, not one before it). */
	public function testJustBelowCutoffRequeuesPending(): void
	{
		$this->assertSame(QueueStatus::PENDING, RequeueDecision::decide(2, 3));
	}

	/** AT the cutoff the attempt budget is spent → dead-letter. This boundary is the load-bearing case. */
	public function testAtCutoffDeadLetters(): void
	{
		$this->assertSame(QueueStatus::DEAD, RequeueDecision::decide(3, 3));
	}

	/** Above the cutoff (e.g. a max lowered by config after the fact) also dead-letters. */
	public function testAboveCutoffDeadLetters(): void
	{
		$this->assertSame(QueueStatus::DEAD, RequeueDecision::decide(5, 3));
	}

	/** The default cutoff satisfies the documented precondition (>= 1), so the edge default is always safe. */
	public function testDefaultMaxAttemptsIsAtLeastOne(): void
	{
		$this->assertGreaterThanOrEqual(1, RequeueDecision::DEFAULT_MAX_ATTEMPTS);
	}

	/** With maxAttempts = 1 a single spent attempt dead-letters immediately (the tightest valid cutoff). */
	public function testCutoffOfOneDeadLettersAfterFirstAttempt(): void
	{
		$this->assertSame(QueueStatus::DEAD, RequeueDecision::decide(1, 1));
	}
}
