<?php

namespace LedgerPilot;

/**
 * The PURE attempts-cutoff verdict (spec §5/§7): given a queue row's attempt count, decide whether it
 * goes back to PENDING for another try or to DEAD (dead-letter). This class is the SINGLE owner of the
 * cutoff rule — like CommitDecision owns BALANCE_EPSILON — so its two consumers cannot drift:
 *   - failure-release: the engine threw on a claimed line; the worker calls decide() in PHP.
 *   - reap of a stale lease: QueueService's reap mirrors the rule in SQL, parametrized by the SAME
 *     max-attempts constant (the live claim/reap spike proves the two agree). decide() is the canonical
 *     statement of the rule; the SQL is its set-based mirror.
 *
 * `attempts` is incremented at CLAIM time (not on failure), so even a worker that crashes mid-processing
 * burns an attempt — that is what bounds a poison row. decide() reads the already-incremented count: at
 * maxAttempts the budget is spent (DEAD), below it one PENDING retry remains.
 *
 * Precondition: maxAttempts >= 1 (a 0/negative cutoff would dead-letter every line on its first claim).
 * The config edge floors LEDGERPILOT_MAX_ATTEMPTS to DEFAULT_MAX_ATTEMPTS; the pure verdict stays
 * undefended, mirroring AccountMatcher's documented K >= 1 precondition.
 */
final class RequeueDecision
{
	/**
	 * Default attempt budget when LEDGERPILOT_MAX_ATTEMPTS is unset/invalid. Three claims (incl. the
	 * crashing one) before dead-letter — tunable via llx_const at the worker edge, not inlined elsewhere.
	 */
	public const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * @param  int $attempts    The row's attempt count, already incremented by the claim.
	 * @param  int $maxAttempts The cutoff (>= 1; resolved from llx_const at the edge).
	 * @return string           QueueStatus::DEAD when the budget is spent (attempts >= maxAttempts),
	 *                          otherwise QueueStatus::PENDING (one more retry).
	 */
	public static function decide(int $attempts, int $maxAttempts): string
	{
		return $attempts >= $maxAttempts ? QueueStatus::DEAD : QueueStatus::PENDING;
	}
}
