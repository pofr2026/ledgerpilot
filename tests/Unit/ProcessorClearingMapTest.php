<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\IbanPseudonymizer;
use LedgerPilot\ProcessorClearingMap;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\ProcessorClearingMap.
 *
 * Second pipeline pre-filter (spec §4, decision §1.4): a payout from a payment processor (TWINT,
 * Stripe, card acquirer, …) is an aggregated 1:N settlement → route it to that processor's
 * configured CLEARING account instead of categorising it line-by-line. The processor→clearing
 * mapping is config (llx_const), never hardcoded. Pure, Dolibarr-free.
 *
 * Scope of THIS class (v0.1): recognise the processor by IBAN — deterministic, mirroring
 * OwnTransferFilter. It works in HMAC space (the keystone counterparty_iban_hmac, read by JOIN from
 * llx_bank), comparing against the configured processor IBANs hashed with the SAME keystone pepper
 * (reuses IbanPseudonymizer). Recognising a processor by counterparty NAME is a separate, later
 * cycle (it needs whole-token matching to avoid false positives like "twinten" ⊃ "twint") — tracked
 * in spec §4, not silently dropped.
 *
 * Shape: build() turns the {raw IBAN → clearing account} config into an HMAC→account map ONCE per
 * batch; clearingFor() is an O(1) lookup per line. Same fingerprint/lookup split as OwnTransferFilter,
 * and the per-IBAN hashing reuses IbanPseudonymizer::tryHash (shared with OwnTransferFilter — DRY).
 *
 * ⚠ Pepper parity + empty-pepper handling are the SAME wiring obligation as OwnTransferFilter (spec
 * §4): build() must be fed the one keystone-namespaced pepper, and the filter skipped when it is
 * unset. Validating that the clearing account exists / is active (llx_accounting_account) is also a
 * wiring/config concern, not this pure map's job.
 */
final class ProcessorClearingMapTest extends TestCase
{
	/** Fixed test pepper — stands in for conf.php $dolibarr_main_bankimport_iban_pepper. */
	private const PEPPER = 'test-pepper';

	/** A processor's payout IBAN and an unrelated third-party IBAN (we hash, not validate checksums). */
	private const PROCESSOR_IBAN = 'CH9300762011623852957';
	private const OTHER_IBAN      = 'DE89370400440532013000';

	/** The clearing account number that processor settles into (config value, mirrors account_number). */
	private const CLEARING_ACCOUNT = '1099';

	/**
	 * Core: a line whose counterparty HMAC is a configured processor's IBAN (hashed by the REAL
	 * IbanPseudonymizer under the same pepper, i.e. as the keystone wrote it) routes to that
	 * processor's clearing account. Pins build()↔keystone parity end to end.
	 */
	public function testRoutesProcessorIbanToItsClearingAccount(): void
	{
		$map = ProcessorClearingMap::build([self::PROCESSOR_IBAN => self::CLEARING_ACCOUNT], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::PROCESSOR_IBAN, self::PEPPER);

		$this->assertSame(self::CLEARING_ACCOUNT, ProcessorClearingMap::clearingFor($counterpartyHmac, $map));
	}

	/**
	 * A counterparty that is not a configured processor has no clearing account → the line proceeds to
	 * normal categorisation (invoice / L1 / L2).
	 */
	public function testForeignCounterpartyHasNoClearingAccount(): void
	{
		$map = ProcessorClearingMap::build([self::PROCESSOR_IBAN => self::CLEARING_ACCOUNT], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::OTHER_IBAN, self::PEPPER);

		$this->assertNull(ProcessorClearingMap::clearingFor($counterpartyHmac, $map));
	}

	/**
	 * No counterparty HMAC (line had no IBAN, or pepper unset at import → keystone stored NULL) → no
	 * clearing routing. Strict null guard (mirrors OwnTransferFilter).
	 */
	public function testNullCounterpartyHasNoClearingAccount(): void
	{
		$map = ProcessorClearingMap::build([self::PROCESSOR_IBAN => self::CLEARING_ACCOUNT], self::PEPPER);

		$this->assertNull(ProcessorClearingMap::clearingFor(null, $map));
	}

	/**
	 * Empty-string counterparty HMAC → no routing. The keystone provably writes NULL not '', but the
	 * HMAC arrives via a DB JOIN, so the strict (=== null || === '') guard is tested rather than held
	 * untested (same reasoning as OwnTransferFilter).
	 */
	public function testEmptyStringCounterpartyHasNoClearingAccount(): void
	{
		$map = ProcessorClearingMap::build([self::PROCESSOR_IBAN => self::CLEARING_ACCOUNT], self::PEPPER);

		$this->assertNull(ProcessorClearingMap::clearingFor('', $map));
	}

	/**
	 * With no processors configured, nothing routes to clearing (lookup in an empty map).
	 */
	public function testEmptyMapHasNoClearingAccount(): void
	{
		$counterpartyHmac = IbanPseudonymizer::hash(self::PROCESSOR_IBAN, self::PEPPER);

		$this->assertNull(ProcessorClearingMap::clearingFor($counterpartyHmac, []));
	}

	/**
	 * build() must skip processor config entries with no usable IBAN (a misconfigured row, a blank
	 * field) instead of throwing — via IbanPseudonymizer::tryHash returning null. Here only the real
	 * IBAN survives and routes; the '' / whitespace entries are dropped.
	 */
	public function testBuildSkipsProcessorEntriesWithoutIban(): void
	{
		$map = ProcessorClearingMap::build(
			[self::PROCESSOR_IBAN => self::CLEARING_ACCOUNT, '' => '1098', '   ' => '1097'],
			self::PEPPER
		);

		$this->assertCount(1, $map);
		$this->assertSame(
			self::CLEARING_ACCOUNT,
			ProcessorClearingMap::clearingFor(IbanPseudonymizer::hash(self::PROCESSOR_IBAN, self::PEPPER), $map)
		);
	}

	/**
	 * Processor IBANs may be configured grouped/spaced and in mixed case; routing must not depend on
	 * formatting. build() canonicalises via IbanPseudonymizer, so a spaced, lowercased processor IBAN
	 * still routes the same account's canonical keystone HMAC.
	 */
	public function testBuildCanonicalizesProcessorIbanFormatting(): void
	{
		$map = ProcessorClearingMap::build(['ch93 0076 2011 6238 5295 7' => self::CLEARING_ACCOUNT], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::PROCESSOR_IBAN, self::PEPPER);

		$this->assertSame(self::CLEARING_ACCOUNT, ProcessorClearingMap::clearingFor($counterpartyHmac, $map));
	}
}
