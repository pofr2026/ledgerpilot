<?php

namespace LedgerPilot;

/**
 * First pre-filter of the pipeline (spec §4): recognise a bank line whose counterparty is one of the
 * company's OWN bank accounts (an internal transfer) so it can be skipped — no categorisation /
 * posting (v0.1 only filters; pairing the two legs is v0.2). Pure, Dolibarr-free, unit-testable.
 *
 * WORKS IN HMAC SPACE, not on raw IBANs. The keystone stores the counterparty IBAN as
 * counterparty_iban_hmac (privacy, §9) and the engine reads that HMAC by joining from llx_bank (§4);
 * the raw counterparty IBAN lives only in the free-text note (not joinable, v0.2-protected). So this
 * filter compares the keystone HMAC against the company's OWN account IBANs (from llx_bank_account)
 * hashed with the SAME pepper — reusing LedgerPilot\IbanPseudonymizer (composition, like
 * AccountMatcher reuses LabelSimilarity). It never sees a raw counterparty IBAN.
 *
 * Two static methods: fingerprintOwnAccounts() builds the own-account HMAC set ONCE per batch (own
 * accounts are a handful); isOwnTransfer() is a cheap O(1) membership check per line. The set is
 * keyed BY the HMAC (array<string,true>), the same set-as-keys idiom as LabelSimilarity's trigrams,
 * giving O(1) lookup and free de-duplication.
 *
 * ⚠ PEPPER PARITY is a WIRING obligation, not enforced here (spec §4): the pepper passed in MUST be
 * the SAME conf.php $dolibarr_main_bankimport_iban_pepper the keystone used to write the counterparty
 * HMAC. A different pepper yields hashes that never line up → own transfers silently go unrecognised;
 * an EMPTY pepper produces wrong-but-valid-looking hashes (hash_hmac does not reject an empty key) →
 * detection silently off. The cross-use test pins ALGORITHM parity (same class, same canonicalisation)
 * but is blind to the pepper VALUE, so the wiring must (a) read the one keystone-namespaced pepper and
 * (b) mirror the keystone's !empty($pepper) guard — skip the filter entirely when the pepper is unset,
 * rather than fingerprinting garbage. This class stays pure (pepper injected, unvalidated, like
 * IbanPseudonymizer); the guard belongs at the conf edge.
 */
final class OwnTransferFilter
{
	/**
	 * Build the set of HMACs of the company's own bank-account IBANs, keyed by HMAC for O(1) lookup.
	 *
	 * Each IBAN is canonicalised and hashed by IbanPseudonymizer (so formatting — grouped spaces,
	 * mixed case — never changes the hash, and the hash matches what the keystone wrote for the same
	 * account). IBAN-less accounts (a cash account, a blank field) surface as '' / whitespace, and
	 * IbanPseudonymizer::hash() throws on an empty IBAN by design — we treat that exception as the
	 * "unusable IBAN, skip it" signal rather than duplicating the canonicalisation to detect it.
	 *
	 * @param  array<int, string> $rawIbans Own bank-account IBANs (any formatting; from
	 *                                      llx_bank_account, entity-scoped at the wiring layer).
	 * @param  string             $pepper   The keystone-namespaced pepper (see the class note).
	 * @return array<string, true>          Set of own-account HMACs, keyed by the HMAC.
	 */
	public static function fingerprintOwnAccounts(array $rawIbans, string $pepper): array
	{
		$ownHmacs = [];
		foreach ($rawIbans as $iban) {
			try {
				$ownHmacs[IbanPseudonymizer::hash($iban, $pepper)] = true;
			} catch (\InvalidArgumentException) {
				// IBAN-less account → not a comparable own account, skip it.
				continue;
			}
		}

		return $ownHmacs;
	}

	/**
	 * Is this line's counterparty one of the company's own accounts (→ internal transfer, skip)?
	 *
	 * @param  string|null         $counterpartyHmac The line's counterparty_iban_hmac (keystone, via
	 *                                               JOIN from llx_bank); null when the line had no
	 *                                               IBAN or the pepper was unset at import.
	 * @param  array<string, true> $ownAccountHmacs  Output of fingerprintOwnAccounts().
	 * @return bool                                  True only if the counterparty HMAC is a known own
	 *                                               account; false (proceed to categorisation) when it
	 *                                               is unknown, null or empty.
	 */
	public static function isOwnTransfer(?string $counterpartyHmac, array $ownAccountHmacs): bool
	{
		// No usable counterparty hash → we cannot claim it is an own transfer; let the line proceed.
		// Strict comparisons state the intent exactly: for a ?string the only non-usable inputs are
		// null and the empty string, and nothing else is treated as "no hash".
		if ($counterpartyHmac === null || $counterpartyHmac === '') {
			return false;
		}

		return isset($ownAccountHmacs[$counterpartyHmac]);
	}
}
