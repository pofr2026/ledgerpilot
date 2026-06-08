<?php

namespace LedgerPilot;

/**
 * Rerank similarity metric for the L2 retriever: a score in [0,1] between two bank-line labels
 * that have ALREADY been canonicalised by LabelNormalizer. Pure, Dolibarr-free, unit-testable.
 *
 * The retriever is candidate-gen → rerank (spec §4): MariaDB FULLTEXT generates a shortlist
 * (DB-coupled, lands later as integration), then THIS class reranks each candidate against the
 * query label. It is only the rerank step — the threshold and the "top-K agree on the account"
 * acceptance live in the MATCHER (next step), with thresholds in llx_const, never hardcoded.
 *
 * Metric: character n-gram **set Jaccard** with n = 3 (trigrams), J = |A ∩ B| / |A ∪ B| over the
 * SETS of character trigrams of each label.
 *   - Character-level (not token-level): bank labels are short (2-5 tokens), so token-trigrams
 *     would almost always be degenerate, and char-level is robust to typos / inflection (the same
 *     basis pg_trgm uses).
 *   - Set-based (presence, not multiplicity): a repeated trigram in a short label carries no extra
 *     signal.
 *   - Trigrams are taken over the FULL normalized string (its single internal spaces included),
 *     with NO pg_trgm-style per-word padding — kept minimal for this first cycle; padding is a
 *     later refinement if recall proves poor (mirroring how the normalizer landed "good enough"
 *     and parked transliteration).
 *   - Extraction is **codepoint-aware** (preg_split with /u), NOT byte-level: the normalizer
 *     preserves accents (é, ä, ü …), and a byte-level window would split a multibyte character and
 *     corrupt the trigrams.
 *
 * COMPOSITION (precondition): callers MUST pass LabelNormalizer output. This class does not
 * normalize — it compares case-sensitively in the canonical space, so the corpus is normalized
 * exactly once (when indexed) and once per query, and the two always agree. Feeding raw labels
 * here would make "ACME" and "acme" look disjoint.
 *
 * Laws: symmetric (score(a,b) == score(b,a)); identical inputs → 1.0; result always in [0,1].
 *
 * Empty / sub-n inputs: an empty or shorter-than-n label has ZERO trigrams, so the raw Jaccard is
 * 0/0 and must be resolved by rule. Two such labels that are IDENTICAL → 1.0 (identity law, applied
 * first); two DIFFERENT ones → 0.0 (no trigram signal — let the line fall through to manual). NOTE:
 * empty vs empty therefore scores 1.0, and an all-punctuation line normalizes to "" — so the
 * MATCHER must reject empty normalized labels before scoring (spec §4 matcher TODO). That guard is
 * the matcher's, deliberately not this pure scorer's.
 */
final class LabelSimilarity
{
	/** Character n-gram size for the Jaccard basis. n=3 is the pg_trgm sweet spot for short labels. */
	private const NGRAM = 3;

	/**
	 * Return the char-trigram set-Jaccard similarity of two ALREADY-NORMALIZED labels, in [0,1].
	 *
	 * @param  string $a First normalized label (LabelNormalizer output).
	 * @param  string $b Second normalized label (LabelNormalizer output).
	 * @return float     Similarity in [0,1]: 1.0 if identical, 0.0 if no trigram is shared (or both
	 *                   are shorter than n and differ), the Jaccard ratio otherwise.
	 */
	public static function score(string $a, string $b): float
	{
		// Identity law first: identical labels are a perfect match. Must precede trigram extraction
		// so empty and sub-n identical labels (whose trigram sets are empty) still score 1.0 rather
		// than hitting the empty-union guard below.
		if ($a === $b) {
			return 1.0;
		}

		$trigramsA = self::trigrams($a);
		$trigramsB = self::trigrams($b);

		// Set union via the array-union operator (keys are the trigrams); count = distinct trigrams.
		$unionCount = count($trigramsA + $trigramsB);
		if ($unionCount === 0) {
			// Both labels are shorter than n and not identical → no trigram signal at all. There is
			// nothing to compare, so report no similarity (the line falls through to the next layer).
			return 0.0;
		}

		$intersectionCount = count(array_intersect_key($trigramsA, $trigramsB));

		return $intersectionCount / $unionCount;
	}

	/**
	 * Extract the SET of character trigrams of a label as an associative array keyed by trigram
	 * (value is an unused sentinel) so set union / intersection are plain key operations and
	 * duplicates collapse for free.
	 *
	 * Splitting is codepoint-aware (preg_split with the /u flag) so multibyte characters stay
	 * whole; a label shorter than NGRAM yields an empty set.
	 */
	private static function trigrams(string $label): array
	{
		// One element per Unicode codepoint. preg_split returns FALSE on a /u error (e.g. malformed
		// UTF-8); the (array) cast then yields [false] — a 1-element array, harmless here because one
		// element is shorter than NGRAM and so produces no trigrams. Defensive depth only:
		// LabelNormalizer already folds malformed input to "" upstream, so this branch is unreachable
		// in practice.
		$chars = (array) preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
		$charCount = count($chars);

		$set = [];
		for ($i = 0; $i + self::NGRAM <= $charCount; $i++) {
			$trigram = implode('', array_slice($chars, $i, self::NGRAM));
			$set[$trigram] = true;
		}

		return $set;
	}
}
