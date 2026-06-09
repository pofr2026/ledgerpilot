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

	// --- Name-keyed recognition (the deferred half of pre-filter #2) -----------------------------
	//
	// Some processors are recognised by their counterparty NAME, not an IBAN. The match is WHOLE-TOKEN
	// (a processor name must appear as complete token[s] in the normalized label) so a name is never
	// matched as a substring of a longer word. clearingForName takes an ALREADY-normalized label
	// (LabelNormalizer output, single-spaced); buildNameIndex normalizes the config names into the same
	// space. Name-keying is a fallback to the IBAN path — the pipeline tries the IBAN first; the map
	// itself does not combine them.

	/**
	 * Whole-token name hit: the configured single-token processor name appears as a complete token in
	 * the normalized label → route to that processor's clearing account.
	 */
	public function testRoutesProcessorByWholeTokenName(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['twint' => self::CLEARING_ACCOUNT]);

		$this->assertSame(self::CLEARING_ACCOUNT, ProcessorClearingMap::clearingForName('twint ag 8005 zurich', $nameIndex));
	}

	/**
	 * THE load-bearing guard: a processor name must NOT match as a substring INSIDE a longer token.
	 * "twint" is a substring of "twinten" but not a whole token of "twinten gmbh" → no match. A naive
	 * str_contains/LIKE would mis-route the unrelated company "twinten" to TWINT clearing.
	 */
	public function testDoesNotMatchNameInsideALongerToken(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['twint' => self::CLEARING_ACCOUNT]);

		$this->assertNull(ProcessorClearingMap::clearingForName('twinten gmbh', $nameIndex));
	}

	/**
	 * Multi-token processor names work too (the whole-token rule applies to the token SEQUENCE): the
	 * configured "worldline saferpay" matches when those two tokens appear contiguously in the label.
	 */
	public function testRoutesMultiTokenProcessorName(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['worldline saferpay' => '1098']);

		$this->assertSame('1098', ProcessorClearingMap::clearingForName('worldline saferpay ag', $nameIndex));
	}

	/**
	 * Multi-token names require the tokens CONTIGUOUS and IN ORDER (the space-padded probe enforces
	 * both). A label that separates the configured tokens ("worldline xyz saferpay") or reverses them
	 * ("saferpay worldline") must NOT match. Regression guard: a future "optimisation" to test the
	 * tokens independently would start matching these and this pins against it.
	 */
	public function testMultiTokenNameRequiresContiguousAndOrderedTokens(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['worldline saferpay' => '1098']);

		$this->assertNull(ProcessorClearingMap::clearingForName('worldline xyz saferpay ag', $nameIndex));
		$this->assertNull(ProcessorClearingMap::clearingForName('saferpay worldline', $nameIndex));
	}

	/**
	 * A label with no configured processor name → no clearing routing (proceeds to categorisation).
	 */
	public function testLabelWithoutProcessorNameHasNoClearing(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['twint' => self::CLEARING_ACCOUNT]);

		$this->assertNull(ProcessorClearingMap::clearingForName('acme gmbh', $nameIndex));
	}

	/**
	 * Empty normalized label (e.g. an all-punctuation line) → no name match. Guarded explicitly rather
	 * than left to the space-padding to happen to return false.
	 */
	public function testEmptyLabelHasNoNameClearing(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['twint' => self::CLEARING_ACCOUNT]);

		$this->assertNull(ProcessorClearingMap::clearingForName('', $nameIndex));
	}

	/**
	 * With no processor names configured, nothing routes by name (empty index).
	 */
	public function testEmptyNameIndexHasNoClearing(): void
	{
		$this->assertNull(ProcessorClearingMap::clearingForName('twint ag', []));
	}

	/**
	 * buildNameIndex normalizes the config names into the label's space (the same-normalization
	 * counterpart of the IBAN path's same-pepper rule): a config name in mixed case with punctuation
	 * ("Twint.") must still match the normalized token "twint". Without this the config and the label
	 * would live in different spaces and never meet.
	 */
	public function testBuildNameIndexNormalizesConfigNames(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['Twint.' => self::CLEARING_ACCOUNT]);

		$this->assertSame(self::CLEARING_ACCOUNT, ProcessorClearingMap::clearingForName('twint ag', $nameIndex));
	}

	/**
	 * buildNameIndex skips config names that normalize to "" (a blank or all-punctuation entry),
	 * otherwise an empty name would space-pad to a match-everything probe. Only the real name survives.
	 * Here the count is the lock, not behaviour: a stray "" entry would be behaviourally inert anyway
	 * (it space-pads to '  ', which never occurs in a single-spaced label), so only assertCount reveals
	 * whether it was actually skipped rather than silently indexed.
	 */
	public function testBuildNameIndexSkipsEmptyOrPunctuationOnlyNames(): void
	{
		$nameIndex = ProcessorClearingMap::buildNameIndex(['twint' => self::CLEARING_ACCOUNT, '' => '1098', '...' => '1097']);

		$this->assertCount(1, $nameIndex);
		$this->assertSame(self::CLEARING_ACCOUNT, ProcessorClearingMap::clearingForName('twint ag', $nameIndex));
	}
}
