<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\IbanAccountLookup;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for the PURE half of LedgerPilot\IbanAccountLookup: resolve().
 *
 * L1 of the engine (spec §4 Step 1): an exact counterparty-IBAN match in the corpus of approved
 * invoice-less postings. The line's counterparty_iban_hmac (written by the keystone, read by JOIN
 * from llx_bank — never recomputed) keys into llx_ledgerpilot_knowledge; the matching rows give the
 * account(s) that IBAN was historically posted to, each with a weight (observation count) and
 * last_seen (recency).
 *
 * The DB fetch (lookup(), entity-scoped query) is Dolibarr-coupled and is verified by an integration
 * spike. THIS file covers only the pure decision: given the knowledge rows for ONE IBAN, which account
 * (if any) to suggest.
 *
 * Policy: an IBAN may have been posted to more than one account over time (one row each). Pick the
 * dominant one — highest weight, then most recent (last_seen), then account_number ascending as a
 * final deterministic tie-break (so the choice never depends on DB row order). This is a strong signal
 * (exact IBAN) and a human approves it in the Dashboard, so L1 suggests whenever there is any history
 * rather than abstaining on ties. It is also the first use of the weight/last_seen ranking that the
 * matcher parked — and establishes the SHARED L1/L2 ranking
	 * precedent (weight desc, last_seen desc, account_number asc); when AccountMatcher unparks its
	 * tie-break it must adopt all three levels so L1 and L2 break ties identically.
 *
 * The row shape mirrors the knowledge columns: ['account_number' => string, 'weight' => int,
 * 'last_seen' => 'Y-m-d H:i:s'] (the datetime string sorts lexically = chronologically).
 */
final class IbanAccountLookupTest extends TestCase
{
	/**
	 * No history for this IBAN → no L1 suggestion (the line falls through to L2 / manual).
	 */
	public function testEmptyHistoryReturnsNull(): void
	{
		$this->assertNull(IbanAccountLookup::resolve([]));
	}

	/**
	 * A single historical account for the IBAN is suggested as-is.
	 */
	public function testSingleAccountIsReturned(): void
	{
		$rows = [
			['account_number' => '6000', 'weight' => 1, 'last_seen' => '2026-05-01 10:00:00'],
		];

		$this->assertSame('6000', IbanAccountLookup::resolve($rows));
	}

	/**
	 * When the IBAN maps to several accounts, the most-confirmed (highest weight) wins, regardless of
	 * input order. Here '6000' (weight 5) dominates '7000' (weight 1) even though '7000' is listed
	 * first and is more recent.
	 */
	public function testHigherWeightWins(): void
	{
		$rows = [
			['account_number' => '7000', 'weight' => 1, 'last_seen' => '2026-06-01 10:00:00'],
			['account_number' => '6000', 'weight' => 5, 'last_seen' => '2026-05-01 10:00:00'],
		];

		$this->assertSame('6000', IbanAccountLookup::resolve($rows));
	}

	/**
	 * A tie on weight is broken by recency (last_seen desc): equally-confirmed accounts → the one seen
	 * most recently. Both weight 3; '7000' is newer → '7000'.
	 */
	public function testWeightTieBrokenByRecency(): void
	{
		$rows = [
			['account_number' => '6000', 'weight' => 3, 'last_seen' => '2026-04-01 10:00:00'],
			['account_number' => '7000', 'weight' => 3, 'last_seen' => '2026-06-01 10:00:00'],
		];

		$this->assertSame('7000', IbanAccountLookup::resolve($rows));
	}

	/**
	 * A full tie on weight AND last_seen is broken deterministically by account_number ascending, so
	 * the result never depends on the order the DB returned the rows. Both weight 2, same last_seen →
	 * '6000' (< '7000').
	 */
	public function testFullTieBrokenByAccountNumberDeterministically(): void
	{
		$rows = [
			['account_number' => '7000', 'weight' => 2, 'last_seen' => '2026-06-01 10:00:00'],
			['account_number' => '6000', 'weight' => 2, 'last_seen' => '2026-06-01 10:00:00'],
		];

		$this->assertSame('6000', IbanAccountLookup::resolve($rows));
	}
}
