<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\IbanPseudonymizer;
use LedgerPilot\OwnTransferFilter;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\OwnTransferFilter.
 *
 * First pre-filter of the pipeline (spec §4): a bank line whose counterparty is one of the
 * company's OWN bank accounts is an internal transfer → skip it (no categorisation / posting; the
 * v0.2 leg-pairing handles netting — v0.1 only filters). Decision §1.5: own-transfer filter is in v0.1.
 *
 * WORKS IN HMAC SPACE, not on raw IBANs. The keystone stores the counterparty IBAN as
 * counterparty_iban_hmac (privacy, §9) and the engine reads that HMAC by joining from llx_bank (§4);
 * the raw counterparty IBAN is only in the free-text note (not joinable, v0.2-protected). So the
 * filter compares the keystone HMAC against the company's OWN account IBANs (from llx_bank_account)
 * hashed with the SAME pepper — reusing LedgerPilot\IbanPseudonymizer (composition, like the matcher
 * reuses LabelSimilarity). It never sees a raw counterparty IBAN.
 *
 * ⚠ LOAD-BEARING (forward wiring): the pepper passed here MUST be the SAME conf.php
 * $dolibarr_main_bankimport_iban_pepper the keystone used. A different pepper silently fails to
 * recognise own transfers (the HMAC spaces never line up) — the same footgun class as the L1 pepper
 * mismatch. The cross-use of the real IbanPseudonymizer in testRecognizesOwnAccountByKeystoneHmac
 * pins that the filter hashes own IBANs exactly as the keystone hashes the counterparty.
 *
 * Shape (confirm before green): two static methods (consistent with the other pure classes) —
 *   - fingerprintOwnAccounts(rawIbans, pepper): array  — build the own-account HMAC set ONCE per
 *     batch (reuses IbanPseudonymizer, canonicalises, skips IBAN-less accounts); and
 *   - isOwnTransfer(?counterpartyHmac, ownAccountHmacs): bool — cheap membership, per line.
 */
final class OwnTransferFilterTest extends TestCase
{
	/** Fixed test pepper — stands in for conf.php $dolibarr_main_bankimport_iban_pepper. */
	private const PEPPER = 'test-pepper';

	/** Two classic example IBANs (we hash them, not validate checksums). */
	private const OWN_IBAN     = 'CH9300762011623852957';
	private const FOREIGN_IBAN = 'DE89370400440532013000';

	/**
	 * Core + same-pepper proof: an own account fingerprinted with the pepper is recognised when a
	 * line's counterparty HMAC is that account's IBAN hashed by the REAL IbanPseudonymizer under the
	 * same pepper (i.e. exactly as the keystone wrote it). This pins that fingerprintOwnAccounts and
	 * the keystone agree byte-for-byte, so own transfers are actually detected in production.
	 */
	public function testRecognizesOwnAccountByKeystoneHmac(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts([self::OWN_IBAN], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::OWN_IBAN, self::PEPPER);

		$this->assertTrue(OwnTransferFilter::isOwnTransfer($counterpartyHmac, $ownAccounts));
	}

	/**
	 * A counterparty that is NOT one of our accounts is not an own transfer → the line proceeds to
	 * categorisation. Foreign IBAN hashed under the same pepper is absent from the own set.
	 */
	public function testForeignCounterpartyIsNotOwnTransfer(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts([self::OWN_IBAN], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::FOREIGN_IBAN, self::PEPPER);

		$this->assertFalse(OwnTransferFilter::isOwnTransfer($counterpartyHmac, $ownAccounts));
	}

	/**
	 * No counterparty HMAC (the line had no IBAN, or the pepper is unset so the keystone stored NULL)
	 * → we cannot know it is an own transfer → false (let the line proceed). Locks the null guard.
	 */
	public function testNullCounterpartyHmacIsNotOwnTransfer(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts([self::OWN_IBAN], self::PEPPER);

		$this->assertFalse(OwnTransferFilter::isOwnTransfer(null, $ownAccounts));
	}

	/**
	 * An empty-string counterparty HMAC → false. The keystone provably writes NULL (never ''), so ''
	 * is not reachable from current data, but the HMAC arrives via a DB JOIN and defensive handling
	 * is cheap — so we test the guard rather than leave it held-but-undriven. Pairs with the null
	 * case to lock the strict (=== null || === '') guard, not a loose comparison.
	 */
	public function testEmptyStringCounterpartyHmacIsNotOwnTransfer(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts([self::OWN_IBAN], self::PEPPER);

		$this->assertFalse(OwnTransferFilter::isOwnTransfer('', $ownAccounts));
	}

	/**
	 * With no own accounts configured, nothing is ever an own transfer (membership in an empty set).
	 */
	public function testEmptyOwnAccountSetIsNeverOwnTransfer(): void
	{
		$counterpartyHmac = IbanPseudonymizer::hash(self::OWN_IBAN, self::PEPPER);

		$this->assertFalse(OwnTransferFilter::isOwnTransfer($counterpartyHmac, []));
	}

	/**
	 * fingerprintOwnAccounts must skip IBAN-less accounts without throwing: a cash account or one
	 * without an IBAN comes through as '' / whitespace, and IbanPseudonymizer::hash() throws on an
	 * empty IBAN by design. The filter drops those (catching that signal rather than duplicating the
	 * canonicalisation) and fingerprints only the usable ones — here just the one real IBAN, which is
	 * still recognised.
	 */
	public function testFingerprintSkipsEmptyOrInvalidOwnIbans(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts([self::OWN_IBAN, '', '   '], self::PEPPER);

		$this->assertCount(1, $ownAccounts);
		$this->assertTrue(
			OwnTransferFilter::isOwnTransfer(IbanPseudonymizer::hash(self::OWN_IBAN, self::PEPPER), $ownAccounts)
		);
	}

	/**
	 * Own IBANs in llx_bank_account are often stored grouped/spaced and in mixed case; recognition
	 * must not depend on formatting. fingerprintOwnAccounts canonicalises via IbanPseudonymizer, so a
	 * spaced, lowercased own IBAN still matches the same account's canonical keystone HMAC.
	 */
	public function testFingerprintCanonicalizesFormattingViaIbanPseudonymizer(): void
	{
		$ownAccounts = OwnTransferFilter::fingerprintOwnAccounts(['ch93 0076 2011 6238 5295 7'], self::PEPPER);
		$counterpartyHmac = IbanPseudonymizer::hash(self::OWN_IBAN, self::PEPPER);

		$this->assertTrue(OwnTransferFilter::isOwnTransfer($counterpartyHmac, $ownAccounts));
	}
}
