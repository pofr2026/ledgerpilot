<?php

namespace LedgerPilot;

/**
 * Second pipeline pre-filter (spec §4, decision §1.4): a payout from a payment processor (TWINT,
 * Stripe, card acquirer, …) is an aggregated 1:N settlement → route it to that processor's configured
 * CLEARING account instead of categorising it line by line. The processor→clearing mapping is config
 * (llx_const), never hardcoded. Pure, Dolibarr-free.
 *
 * v0.1 recognises the processor by IBAN — deterministic, mirroring OwnTransferFilter. It works in HMAC
 * space (the keystone counterparty_iban_hmac, read by JOIN from llx_bank), comparing against the
 * configured processor IBANs hashed with the SAME keystone pepper (reuses IbanPseudonymizer). It never
 * sees a raw counterparty IBAN. Recognising a processor by counterparty NAME is a separate, later
 * cycle (it needs whole-token matching to avoid false positives such as "twinten" containing "twint")
 * — tracked in spec §4, not silently dropped.
 *
 * Two static methods: build() turns the {raw IBAN → clearing account} config into an HMAC→account map
 * ONCE per batch (keyed by HMAC, the same set-as-keys idiom as LabelSimilarity / OwnTransferFilter);
 * clearingFor() is an O(1) lookup per line. The per-IBAN hashing reuses IbanPseudonymizer::tryHash —
 * the shared "hash an IBAN, skip if unusable" primitive (also used by OwnTransferFilter), so the
 * skip-the-IBAN-less-entry logic is not duplicated.
 *
 * ⚠ PEPPER PARITY is the SAME wiring obligation as OwnTransferFilter (spec §4): build() must be fed the
 * one keystone-namespaced conf.php $dolibarr_main_bankimport_iban_pepper, and the filter skipped when
 * the pepper is unset (hash_hmac does not reject an empty pepper, and the unit test only pins algorithm
 * parity, so it is blind to a pepper-value mismatch). Validating that the clearing account exists / is
 * active (llx_accounting_account) is a wiring/config concern, not this pure map's job.
 */
final class ProcessorClearingMap
{
	/**
	 * Turn the {raw IBAN → clearing account} processor config into an HMAC→clearing-account lookup map.
	 *
	 * Each configured IBAN is canonicalised and hashed by IbanPseudonymizer (so formatting never
	 * changes the key, and the key matches the keystone HMAC for that account). A config entry with no
	 * usable IBAN (a blank field, a misconfigured row) hashes to null via tryHash and is skipped, not
	 * fatal.
	 *
	 * @param  array<string, string> $ibanToClearing Map of processor IBAN (any formatting) → clearing
	 *                                               account number (config; from llx_const).
	 * @param  string                $pepper         The keystone-namespaced pepper (see the class note).
	 * @return array<string, string>                 Map of processor HMAC → clearing account number.
	 */
	public static function build(array $ibanToClearing, string $pepper): array
	{
		$hmacToClearing = [];
		foreach ($ibanToClearing as $iban => $clearingAccount) {
			// Cast guards the case where PHP coerced an all-numeric IBAN key to int (real IBANs start
			// with a country code, so this is belt-and-braces); tryHash skips IBAN-less entries.
			$hmac = IbanPseudonymizer::tryHash((string) $iban, $pepper);
			if ($hmac !== null) {
				$hmacToClearing[$hmac] = $clearingAccount;
			}
		}

		return $hmacToClearing;
	}

	/**
	 * The clearing account for this line's counterparty, or null when it is not a configured processor.
	 *
	 * @param  string|null           $counterpartyHmac The line's counterparty_iban_hmac (keystone, via
	 *                                                 JOIN from llx_bank); null when the line had no
	 *                                                 IBAN or the pepper was unset at import.
	 * @param  array<string, string> $hmacToClearing   Output of build().
	 * @return string|null                             The clearing account number, or null to fall
	 *                                                 through to normal categorisation.
	 */
	public static function clearingFor(?string $counterpartyHmac, array $hmacToClearing): ?string
	{
		// No usable counterparty hash → not a recognisable processor; let the line proceed. Strict
		// comparisons: for a ?string the only non-usable inputs are null and the empty string.
		if ($counterpartyHmac === null || $counterpartyHmac === '') {
			return null;
		}

		return $hmacToClearing[$counterpartyHmac] ?? null;
	}
}
