<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\LabelSimilarity;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\LabelSimilarity::score().
 *
 * This is the PURE rerank metric of the L2 retriever (spec §4: "retriever candidate-gen →
 * rerank, MariaDB FULLTEXT for generation, PHP rerank trigram/Jaccard on the normalized
 * title"). Candidate generation (FULLTEXT) is DB-coupled and lands later as integration;
 * this class is only the rerank step — a similarity score in [0,1] between two labels that
 * have ALREADY been run through LabelNormalizer. It composes with the normalizer: the caller
 * normalizes once when indexing the corpus and once per query, and feeds the canonical forms
 * here. The scorer itself does NOT normalize (single responsibility, no double work).
 *
 * Metric (the contract these tests pin):
 *   - char n-gram Jaccard with n = 3 (trigrams): J = |A ∩ B| / |A ∪ B| over the SETS of
 *     character trigrams of each label. Char-level (not token-level) because bank labels are
 *     short (2-5 tokens) — token-trigrams would almost always be degenerate — and char-level
 *     is robust to typos/inflection (this is what pg_trgm uses). Set-based (presence, not
 *     multiplicity): repeated trigrams in a short label carry no extra signal.
 *   - Trigrams are extracted over the FULL normalized string, spaces included, with NO
 *     pg_trgm-style per-word padding (kept minimal for this first cycle — padding is a later
 *     refinement if recall proves poor, mirroring how the normalizer landed "good enough"
 *     then parked transliteration).
 *   - Extraction is codepoint-aware (Unicode), NOT byte-level: the normalizer preserves
 *     accents (é, ä, ü …), so byte-level windows would split a multibyte char and corrupt the
 *     trigrams.
 *
 * Laws: symmetric (score(a,b) == score(b,a)), identical inputs → 1.0, result always in [0,1].
 * Acceptance threshold and top-K agreement are NOT here — they belong to the MATCHER (next
 * step), with thresholds in llx_const (never hardcoded).
 */
final class LabelSimilarityTest extends TestCase
{
	/**
	 * Load-bearing arithmetic: pins the metric to char-trigram (n=3) SET Jaccard in one
	 * exact, hand-verifiable example.
	 *   trigrams("abc")  = {abc}            (length 3 → 1 trigram)
	 *   trigrams("abcd") = {abc, bcd}       (length 4 → 2 trigrams)
	 *   |∩| = |{abc}| = 1 ; |∪| = |{abc, bcd}| = 2 → 1/2 = 0.5 exactly.
	 * 0.5 is exactly representable, so assertSame on the float is safe.
	 */
	public function testScoresCharTrigramJaccard(): void
	{
		$this->assertSame(0.5, LabelSimilarity::score('abc', 'abcd'));
	}

	/**
	 * Identity law: two identical normalized labels are a perfect match. For a non-empty
	 * label this also falls out of the formula (A = B → ∩ = ∪), but locking it guards the
	 * contract callers rely on (the corpus stores normalized forms; an exact re-occurrence
	 * must score 1.0).
	 */
	public function testIdenticalLabelsScoreOne(): void
	{
		$this->assertSame(1.0, LabelSimilarity::score('acme gmbh', 'acme gmbh'));
	}

	/**
	 * DECISION-BEARING: two empty labels → 1.0 by the identity law.
	 * The raw Jaccard is 0/0 (both trigram sets empty) and must be resolved by a rule. We
	 * choose the identity law (a === b → 1.0) applied FIRST, which also makes identical
	 * sub-trigram labels ("ab" == "ab") score 1.0 — keeping the function total and free of a
	 * discontinuity. The alternative (empty/empty → 0.0) would break "identical ⇒ 1.0"; the
	 * real concern (empty labels matching as garbage) belongs to an UPSTREAM guard in the
	 * matcher, not to this pure scorer. Change this single assertion to 0.0 if you'd rather
	 * the scorer itself be defensive about empties.
	 */
	public function testEmptyLabelsScoreOneByIdentityLaw(): void
	{
		$this->assertSame(1.0, LabelSimilarity::score('', ''));
	}

	/**
	 * No shared trigrams → 0.0. trigrams("abc")={abc}, trigrams("xyz")={xyz} are disjoint,
	 * so the score floors at zero (lets the line fall through to the next layer / manual).
	 */
	public function testDisjointLabelsScoreZero(): void
	{
		$this->assertSame(0.0, LabelSimilarity::score('abc', 'xyz'));
	}

	/**
	 * Sub-n DIFFERENT labels → 0.0. A label shorter than n (3) yields ZERO trigrams, so two
	 * different sub-n labels ("ab" vs "ac") have an empty union and the raw Jaccard is again
	 * 0/0. Distinct from the disjoint case above (which floors via the formula, union NON-empty):
	 * here the union is EMPTY, so a separate guard must return 0.0 — there is no trigram signal,
	 * let the line fall through to the next layer / manual. This drives the union-empty branch
	 * (without it that branch is either undriven or divides by zero in green).
	 */
	public function testSubTrigramDifferentLabelsScoreZero(): void
	{
		$this->assertSame(0.0, LabelSimilarity::score('ab', 'ac'));
	}

	/**
	 * Symmetry: the order of the two labels must not change the score (indexing vs querying
	 * are interchangeable directions). Same 0.5 pair, swapped.
	 */
	public function testIsSymmetric(): void
	{
		$this->assertSame(
			LabelSimilarity::score('abc', 'abcd'),
			LabelSimilarity::score('abcd', 'abc')
		);
	}

	/**
	 * Codepoint-aware extraction, NOT byte-level. The normalizer preserves accents, so the
	 * scorer receives multibyte UTF-8. "café" vs "cafe" differ in one codepoint (é vs e):
	 *   codepoint trigrams("café") = {caf, afé} ; trigrams("cafe") = {caf, afe}
	 *   |∩|=1, |∪|=3 → 1/3.
	 * A byte-level window would instead split the 2-byte é and yield 1/4 — so this test
	 * fails unless extraction is Unicode-aware. (It also confirms accented vs unaccented are
	 * near-but-not-equal, consistent with the parked transliteration cycle.)
	 */
	public function testExtractsTrigramsByCodepointNotByte(): void
	{
		$this->assertEqualsWithDelta(1 / 3, LabelSimilarity::score('café', 'cafe'), 1e-9);
	}

	/**
	 * DECISION-BEARING: the scorer treats its inputs as ALREADY
	 * normalized and does NOT normalize them itself (composition with LabelNormalizer, not
	 * duplication of it). "ACME" and "acme" therefore compare case-SENSITIVELY — their
	 * trigram sets {ACM,CME} vs {acm,cme} are disjoint → 0.0. If the scorer secretly
	 * casefolded, this would be 1.0. Locks the "caller normalizes once" contract.
	 */
	public function testTreatsInputAsAlreadyNormalized(): void
	{
		$this->assertSame(0.0, LabelSimilarity::score('ACME', 'acme'));
	}
}
