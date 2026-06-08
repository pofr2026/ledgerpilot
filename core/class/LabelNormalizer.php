<?php

namespace LedgerPilot;

/**
 * Canonicaliser for bank-line labels, used by the L2 retriever BOTH when indexing the
 * corpus and when querying it (so an incoming label and a stored one are compared in the
 * same normalized space). Pure, Dolibarr-free, unit-testable on its own.
 *
 * This bedrock step folds away the cosmetic differences that must NOT split one
 * counterparty into many near-duplicates:
 *
 *   - Letter case — via mb_strtolower (multibyte), so accented CH/DE/FR capitals
 *     (É, Ä, Ü, …) fold correctly; plain strtolower would leave them untouched and
 *     produce broken keys. The accent is PRESERVED here — removing it (café → cafe) is
 *     a separate, later transliteration step.
 *   - Whitespace — collapsed Unicode-aware ([\s\p{Z}]), so the non-breaking spaces
 *     (U+00A0) common in bank exports are treated like ordinary spaces instead of
 *     surviving as distinct characters that fragment the corpus.
 *   - Punctuation — every \p{P} run (incl. & and /, which act as separators in company
 *     names) becomes a SPACE, never deleted: deleting would glue adjacent tokens and
 *     diverge from the spaced form. \p{S} symbols (+ $ = …) are intentionally left alone.
 *
 * Accent transliteration (café → cafe) and removal of volatile date/ref/amount tokens are
 * deliberately out of scope for this step and land in later red→green cycles.
 */
final class LabelNormalizer
{
	/**
	 * Return the canonical form of a raw bank-line label: lowercased (multibyte-safe),
	 * with every run of whitespace — ASCII or Unicode separators incl. NBSP — collapsed
	 * to a single space and the ends trimmed.
	 *
	 * @param  string $raw Raw label as it arrives from the bank import.
	 * @return string      Canonical, comparison-ready label.
	 */
	public static function normalize(string $raw): string
	{
		$lower = mb_strtolower($raw, 'UTF-8');
		// Punctuation (\p{P}, incl. & and /) → SPACE, never deleted: deleting would glue
		// adjacent tokens ("ACME,GmbH" → "acmegmbh") and diverge from the spaced form,
		// fragmenting the corpus. The whitespace collapse then folds the runs to one space.
		// Each result is cast to string: a /u pattern returns null on malformed UTF-8, and
		// the cast keeps the following subject (and trim) a real string.
		$separated = (string) preg_replace('/\p{P}+/u', ' ', $lower);
		$collapsed = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $separated);

		return trim($collapsed);
	}
}
