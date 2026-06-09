<?php

namespace LedgerPilot;

/**
 * Second pipeline pre-filter (spec §4, decision §1.4): a payout from a payment processor (TWINT,
 * Stripe, card acquirer, …) is an aggregated 1:N settlement → route it to that processor's configured
 * CLEARING account instead of categorising it line by line. The processor→clearing mapping is config
 * (llx_const), never hardcoded. Pure, Dolibarr-free.
 *
 * It recognises a processor two ways. (1) By IBAN — deterministic, mirroring OwnTransferFilter: in HMAC
 * space (the keystone counterparty_iban_hmac, read by JOIN from llx_bank) against the configured
 * processor IBANs hashed with the SAME keystone pepper (reuses IbanPseudonymizer); it never sees a raw
 * counterparty IBAN. (2) By counterparty NAME — whole-token matching of configured names against the
 * normalized label, so a name only matches as complete token(s) ("twint" never matches "twinten");
 * reuses LabelNormalizer to put the config names in the label's space. The IBAN path is the more
 * reliable one, so the pipeline tries it first and falls back to the name path; the map does not
 * combine them.
 *
 * Four static methods, two per path. IBAN: build() turns the {raw IBAN → clearing account} config into
 * an HMAC→account map ONCE per batch (keyed by HMAC, the same set-as-keys idiom as LabelSimilarity /
 * OwnTransferFilter) and clearingFor() is an O(1) lookup per line; the per-IBAN hashing reuses
 * IbanPseudonymizer::tryHash — the shared "hash an IBAN, skip if unusable" primitive (also used by
 * OwnTransferFilter), so that logic is not duplicated. NAME: buildNameIndex() normalizes the config
 * names once and clearingForName() probes the label for a whole-token name hit per line.
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

	/**
	 * Build a normalized-name → clearing-account index from the {processor name → clearing account}
	 * config. Each config name is normalized by LabelNormalizer into the SAME canonical space as the
	 * incoming label (the same-normalization counterpart of the IBAN path's same-pepper rule — otherwise
	 * the config name and the label would live in different spaces and never meet). A name that
	 * normalizes to "" (a blank or all-punctuation entry) is skipped, never indexed: it would otherwise
	 * space-pad to a match-everything probe.
	 *
	 * @param  array<string, string> $nameToClearing Map of processor name (any case/punctuation) →
	 *                                               clearing account number (config; from llx_const).
	 * @return array<string, string>                 Map of normalized processor name → clearing account.
	 */
	public static function buildNameIndex(array $nameToClearing): array
	{
		$nameIndex = [];
		foreach ($nameToClearing as $name => $clearingAccount) {
			$normalized = LabelNormalizer::normalize((string) $name);
			if ($normalized !== '') {
				$nameIndex[$normalized] = $clearingAccount;
			}
		}

		return $nameIndex;
	}

	/**
	 * The clearing account for a line whose normalized label contains a configured processor NAME, or
	 * null when none matches. The match is WHOLE-TOKEN: the normalized name must appear as complete
	 * token(s) in the label, never as a substring inside a longer word ("twint" must not match
	 * "twinten"). This is enforced by probing the SPACE-PADDED label for the SPACE-PADDED name, so a
	 * token boundary (a space, or the start/end via the padding) is required on both sides — and it
	 * covers multi-token names for free (the name's token sequence must appear contiguously).
	 *
	 * Fallback to the IBAN path: the pipeline calls clearingFor() (IBAN) first. If several configured
	 * names match (a pathological label naming two processors), the first in index order wins —
	 * deterministic for a given config (insertion order), though it depends on the processor config
	 * order, which the wiring should keep stable.
	 *
	 * @param  string                $normalizedLabel The incoming label, ALREADY normalized
	 *                                               (LabelNormalizer output, single-spaced).
	 * @param  array<string, string> $nameIndex       Output of buildNameIndex().
	 * @return string|null                            The clearing account number, or null to fall
	 *                                               through to normal categorisation.
	 */
	public static function clearingForName(string $normalizedLabel, array $nameIndex): ?string
	{
		if ($normalizedLabel === '') {
			return null;
		}

		// Space-pad both sides so a name only matches on whole-token boundaries: " twint " is in
		// " twint ag " but not in " twinten gmbh ".
		$paddedLabel = ' '.$normalizedLabel.' ';
		foreach ($nameIndex as $name => $clearingAccount) {
			if (str_contains($paddedLabel, ' '.$name.' ')) {
				return $clearingAccount;
			}
		}

		return null;
	}
}
