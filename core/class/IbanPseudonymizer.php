<?php

namespace LedgerPilot;

/**
 * Pure helper that pseudonymises a counterparty IBAN for L1 matching and the own-transfer filter.
 *
 * IBANs are PII and, being structured and low-entropy, are enumerable -- a plain SHA-256 of an IBAN is
 * brute-forceable. So the corpus compares HMAC-SHA256(IBAN, pepper) with the pepper held OUTSIDE the
 * database (conf.php), per spec §9/§11. The HMAC preserves exact-match equality, which is all L1 and the
 * transfer filter need: they compare hashes, never plaintext. The pepper is injected as an argument and
 * never read here, so the class stays pure, side-effect-free and unit-testable with a fixed pepper.
 *
 * DELIBERATE DUPLICATION (not a DRY violation): this mirrors bankimport's keystone IbanPseudonymizer
 * byte-for-byte on purpose. The module boundary is data, not code (spec §0), so LedgerPilot must NOT
 * require bankimport's class; it owns this small, standard HMAC primitive instead. Correctness is pinned
 * by a CROSS-CHECK unit test against the keystone's own vector -- if the canonicalisation or algorithm
 * here ever drifts from the keystone, L1 would silently stop matching the hashes the keystone wrote, and
 * that test catches it. For lines that already went through the keystone the engine REUSES the stored
 * hash (JOIN from llx_bank, §4); this class is only for the history bootstrap, which recomputes from
 * IBANs scraped out of free-text notes -- with the SAME pepper, naturally.
 */
final class IbanPseudonymizer
{
	/** HMAC algorithm. IBANs are enumerable, so a keyed hash (not a bare digest) is required (§9). */
	private const HASH_ALGO = 'sha256';

	/**
	 * Return HMAC-SHA256 (64 lowercase hex chars) of the canonicalised IBAN under the given pepper.
	 *
	 * @param  string $iban   The counterparty IBAN, in any spacing/case (canonicalised before hashing).
	 * @param  string $pepper The secret pepper, supplied by the caller from outside the database.
	 * @return string         The 64-hex HMAC-SHA256 digest.
	 * @throws \InvalidArgumentException if the IBAN is empty after canonicalisation -- hashing "" would
	 *         map every missing-IBAN case to one identical value and poison the L1 / transfer buckets.
	 *         The caller must skip null/absent IBANs.
	 */
	public static function hash(string $iban, string $pepper): string
	{
		$canonical = self::canonicalize($iban);
		if ($canonical === '') {
			throw new \InvalidArgumentException('IbanPseudonymizer::hash() received an empty IBAN.');
		}

		return hash_hmac(self::HASH_ALGO, $canonical, $pepper);
	}

	/**
	 * Batch-friendly sibling of hash(): return the HMAC of $iban, or null when the IBAN is empty after
	 * canonicalisation (where hash() would throw). This lets a caller fingerprinting a list of own /
	 * processor IBANs skip the IBAN-less entries without a try/catch at every call site — it is the one
	 * place the "hash an IBAN, skip it if unusable" logic lives, shared by OwnTransferFilter and
	 * ProcessorClearingMap (DRY). For a usable IBAN it is exactly hash() (same canonicalisation + HMAC).
	 *
	 * @param  string      $iban   The IBAN, in any spacing/case (canonicalised before hashing).
	 * @param  string      $pepper The secret pepper (see hash()).
	 * @return string|null         The 64-hex HMAC-SHA256 digest, or null if the IBAN is empty after
	 *                             canonicalisation.
	 */
	public static function tryHash(string $iban, string $pepper): ?string
	{
		try {
			return self::hash($iban, $pepper);
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * Canonicalise an IBAN so formatting never changes the hash: strip every non-alphanumeric character
	 * (spaces, non-breaking spaces, dots, dashes -- any separator) and uppercase. The IBAN alphabet is
	 * exactly [A-Z0-9], so this is lossless for a valid IBAN and one account maps to exactly one hash
	 * regardless of how the source formatted it. MUST stay byte-for-byte identical to the keystone's
	 * canonicalisation (see the class note); the cross-check test enforces it.
	 */
	private static function canonicalize(string $iban): string
	{
		return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban));
	}
}
