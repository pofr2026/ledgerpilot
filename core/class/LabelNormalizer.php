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
 *
 * Punctuation stripping, accent transliteration and removal of volatile date/ref/amount
 * tokens are deliberately out of scope for this step and land in later red→green cycles.
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
		$collapsed = preg_replace('/[\s\p{Z}]+/u', ' ', $lower);

		return trim((string) $collapsed);
	}
}
