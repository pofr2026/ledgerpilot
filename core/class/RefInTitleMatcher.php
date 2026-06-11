<?php

namespace LedgerPilot;

/**
 * Pure core of the Step 0 ref-in-title fallback (spec §4): when the structured-reference lookup misses,
 * recognise a known OPEN-invoice ref inside the bank line's free-text title. Pure, Dolibarr-free; the DB
 * side (fetch the matched ref + verdict) is InvoiceByRefResolver, spike-verified.
 *
 * Build/probe split, mirroring ProcessorClearingMap (buildNameIndex / clearingForName):
 *   - buildRefIndex() runs ONCE per batch: the pre-flight index's raw open-invoice refs (spec §4, NOT a
 *     hardcoded ref-format regex — DRY with the planned index and addon-agnostic) become a
 *     {normalized ref → raw ref} map, with the discriminability guard (B) applied.
 *   - match() runs per line: a whole-token probe of the already-normalized title, returning the RAW ref
 *     (the uk_facture_ref key the DB layer fetches by) or null.
 *
 * Two load-bearing rules:
 *   - WHOLE-TOKEN (DQ1): the space-padded str_contains idiom from ProcessorClearingMap. A ref's
 *     normalized token sequence must appear contiguously on token boundaries, so '...0158' never matches
 *     inside '01580'. A hyphenated ref ('TC1-2605-0158') normalizes to 'tc1 2605 0158' (3 tokens),
 *     matched as a contiguous run.
 *   - DISCRIMINABILITY GUARD (B): without an fk_soc cross-check (unknown in v0.1) the ref is the only
 *     guard against a coincidental token. A ref is eligible only when its normalized form has >= 2 tokens
 *     OR a single token containing a letter (\p{L}); a lone letter-less token (a bare number like '2605',
 *     ubiquitous in bank titles — amounts, dates) is dropped, and that invoice falls through to manual
 *     (precision-first). Residual: a multi-token all-numeric ref ('2024 0001') stays eligible by the
 *     >= 2 rule; human-in-the-loop review (suggestion ≠ commit, spec §0) absorbs it.
 */
final class RefInTitleMatcher
{
	/**
	 * Turn the pre-flight index's raw open-invoice refs into a {normalized ref → raw ref} lookup,
	 * dropping refs that are empty-normalizing or non-discriminable (guard B). On a normalization
	 * collision the last raw ref wins (documented; refs are unique per uk_facture_ref, so this is a rare
	 * normalization clash, not a data error).
	 *
	 * @param  array<int, string> $knownRefs Raw open-invoice refs (from the §4 pre-flight index).
	 * @return array<string, string>          Map of normalized ref → raw ref.
	 */
	public static function buildRefIndex(array $knownRefs): array
	{
		$refIndex = [];
		foreach ($knownRefs as $rawRef) {
			$normalized = LabelNormalizer::normalize((string) $rawRef);
			if (self::isDiscriminable($normalized)) {
				$refIndex[$normalized] = (string) $rawRef;
			}
		}

		return $refIndex;
	}

	/**
	 * The raw ref whose normalized form appears as whole token(s) in the title, or null when none does.
	 *
	 * @param  string                $normalizedTitle The bank line title, ALREADY normalized
	 *                                               (LabelNormalizer output, single-spaced).
	 * @param  array<string, string> $refIndex        Output of buildRefIndex().
	 * @return string|null                            The raw ref to look up, or null to fall through.
	 */
	public static function match(string $normalizedTitle, array $refIndex): ?string
	{
		if ($normalizedTitle === '') {
			return null;
		}

		// Space-pad both sides so a ref only matches on whole-token boundaries: ' tc1 2605 0158 ' is in
		// ' ... tc1 2605 0158 merci ' but not in ' ... tc1 2605 0158x '. First hit in index order wins.
		$paddedTitle = ' '.$normalizedTitle.' ';
		foreach ($refIndex as $normalizedRef => $rawRef) {
			if (str_contains($paddedTitle, ' '.$normalizedRef.' ')) {
				return $rawRef;
			}
		}

		return null;
	}

	/**
	 * Guard B: a normalized ref is discriminable enough to match in free text when it has >= 2 tokens, or
	 * a single token containing a letter. An empty string or a lone letter-less token (a bare number) is
	 * rejected.
	 *
	 * @param  string $normalizedRef A LabelNormalizer-normalized ref (single-spaced, trimmed).
	 * @return bool
	 */
	private static function isDiscriminable(string $normalizedRef): bool
	{
		if ($normalizedRef === '') {
			return false;
		}

		$tokens = explode(' ', $normalizedRef);
		if (count($tokens) >= 2) {
			return true;
		}

		// Single token: keep it only if it carries a letter (drops a bare number like '2605').
		return preg_match('/\p{L}/u', $tokens[0]) === 1;
	}
}
