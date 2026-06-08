<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\IbanPseudonymizer;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\IbanPseudonymizer::hash().
 *
 * L1 matches an incoming bank line's counterparty IBAN against the corpus by HMAC (spec §5/§9): the
 * keystone (bankimport) already wrote counterparty_iban_hmac into llx_bankimport_line_ref, and the
 * history bootstrap recomputes hashes for IBANs it scrapes from free-text notes. For L1 to EVER match,
 * LedgerPilot's hash MUST be byte-for-byte identical to the keystone's for the same (IBAN, pepper).
 *
 * The module boundary forbids requiring bankimport's class (integration via data, not code — spec §0),
 * so LedgerPilot owns this small, pure HMAC primitive. That is a DELIBERATE, contract-pinned duplication,
 * not a DRY violation: the load-bearing test below is the CROSS-CHECK against the keystone's own vector
 * (= HMAC-SHA256 of the canonical IBAN, the published standard) — if the canonicalisation or algorithm
 * ever drifts from the keystone, that test goes red and L1 silently-broken-matching is caught here.
 */
final class IbanPseudonymizerTest extends TestCase
{
	/**
	 * Cross-module vector: HMAC-SHA256('CH9300762011623852957', 'pepper-123'), the EXACT value the
	 * bankimport keystone produces (and the HMAC-SHA256 standard, independently verified via hash_hmac).
	 * LedgerPilot must reproduce it or L1 never matches a hash the keystone wrote.
	 */
	private const KEYSTONE_VECTOR_PEPPER_123 = '8cc557a7ae0f9c0678d61c3a26a2eb8824181ce01e9feee8c07f94ec48fabd2e';

	/** HMAC-SHA256('CH9300762011623852957', 'other-pepper') — proves the pepper actually participates. */
	private const VECTOR_OTHER_PEPPER = '07abae46f6c3e4eb01961d14667c8857fdd533e39ef2e9456445345906ce5fad';

	/**
	 * THE cross-test: a canonical IBAN hashes to the keystone's vector (64 lowercase hex). This is the
	 * guard that LedgerPilot's L1 will match hashes written by the keystone.
	 */
	public function testHashMatchesTheKeystoneVectorForL1Interop(): void
	{
		$hash = IbanPseudonymizer::hash('CH9300762011623852957', 'pepper-123');

		$this->assertSame(self::KEYSTONE_VECTOR_PEPPER_123, $hash);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
	}

	/**
	 * Spacing and case must not change the hash (one account → one value), so the grouped lowercase form
	 * yields the keystone vector — exactly as the keystone canonicalises before hashing.
	 */
	public function testNormalisesSpacingAndCaseBeforeHashing(): void
	{
		$this->assertSame(
			self::KEYSTONE_VECTOR_PEPPER_123,
			IbanPseudonymizer::hash('ch93 0076 2011 6238 5295 7', 'pepper-123')
		);
	}

	/**
	 * Canonicalisation must strip EVERY non-alphanumeric separator (dots, dashes, a non-breaking space
	 * U+00A0), not just ASCII spaces — the §8 history bootstrap may hand over oddly-formatted IBANs.
	 * The messy form must still hash to the keystone vector.
	 */
	public function testCanonicalisationStripsAllNonAlphanumericSeparators(): void
	{
		$messy = "ch93-0076.2011\u{00A0}6238 5295 7";

		$this->assertSame(self::KEYSTONE_VECTOR_PEPPER_123, IbanPseudonymizer::hash($messy, 'pepper-123'));
	}

	/**
	 * The pepper participates: the same IBAN under a different pepper yields a different (known) hash, so
	 * a leaked corpus without the pepper cannot be correlated back to IBANs (spec §9).
	 */
	public function testDifferentPepperYieldsDifferentHash(): void
	{
		$hash = IbanPseudonymizer::hash('CH9300762011623852957', 'other-pepper');

		$this->assertSame(self::VECTOR_OTHER_PEPPER, $hash);
		$this->assertNotSame(self::KEYSTONE_VECTOR_PEPPER_123, $hash);
	}

	/**
	 * Defensive contract: an IBAN empty after canonicalisation must throw, not hash "" — otherwise every
	 * missing-IBAN case collapses to one value and poisons the L1 bucket. (Same contract as the keystone;
	 * the caller must skip absent IBANs.)
	 */
	public function testRejectsEmptyIbanAfterCanonicalisation(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		IbanPseudonymizer::hash('   ', 'pepper-123');
	}

	/**
	 * tryHash() is the batch-friendly sibling of hash(), added to DRY the "hash an IBAN, skip it if it
	 * has no usable IBAN" loop shared by OwnTransferFilter and ProcessorClearingMap. For a valid IBAN it
	 * returns exactly what hash() returns (same canonicalisation + HMAC → the keystone vector).
	 */
	public function testTryHashReturnsTheHashForAValidIban(): void
	{
		$this->assertSame(
			self::KEYSTONE_VECTOR_PEPPER_123,
			IbanPseudonymizer::tryHash('CH9300762011623852957', 'pepper-123')
		);
	}

	/**
	 * Where hash() THROWS (an IBAN empty after canonicalisation), tryHash() returns null instead — so a
	 * caller fingerprinting a list of own/processor IBANs can skip the IBAN-less ones without a
	 * try/catch at every call site (the actual DRY win). Both '' and whitespace-only canonicalise empty.
	 */
	public function testTryHashReturnsNullForAnEmptyIban(): void
	{
		$this->assertNull(IbanPseudonymizer::tryHash('', 'pepper-123'));
		$this->assertNull(IbanPseudonymizer::tryHash('   ', 'pepper-123'));
	}
}
