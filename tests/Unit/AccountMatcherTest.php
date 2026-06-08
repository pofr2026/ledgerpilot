<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\AccountMatcher;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\AccountMatcher::decide().
 *
 * This is the L2 acceptance policy of the engine (spec §4 Step 2): given an incoming bank-line
 * label and a shortlist of corpus candidates, decide whether the label maps confidently enough to
 * a single accounting account, or whether to abstain (→ manual review, which then feeds the corpus).
 * It composes the two pure pieces already built: it normalizes nothing itself (the caller passes
 * LabelNormalizer output) and scores each candidate with LabelSimilarity (char-trigram Jaccard).
 *
 * Candidate generation (MariaDB FULLTEXT) is DB-coupled and lands later as integration; here the
 * candidate shortlist is INJECTED, keeping the matcher a pure, Dolibarr-free class.
 *
 * POLICY (confirmed):
 *   - A candidate VOTES only if its similarity to the query strictly exceeds the threshold
 *     ("similarity > threshold", spec §4). This single gate also settles the "too few candidates"
 *     case: a below-threshold candidate always ranks below every qualifying one, so it can only
 *     enter the top-K when fewer than K candidates qualify — i.e. exactly when we abstain anyway.
 *     "below threshold doesn't vote" and "need ≥K qualifying votes" are therefore ONE rule, not two.
 *   - Accept an account iff AT LEAST K candidates qualify AND the top-K qualifying candidates
 *     (highest scores) ALL name the same account_number (strict unanimity among the top-K — not a
 *     majority, and not across every qualifier). Otherwise abstain.
 *   - K and threshold are INJECTED (from llx_const upstream; threshold is validated there to lie in
 *     [0,1), so a negative threshold cannot admit 0.0 scores — not re-checked here).
 *   - Empty query label → abstain before any scoring (matcher-side guard, spec §4).
 *
 * Candidate shape mirrors the knowledge-table columns it projects (spec §5): each candidate is
 * ['normalized_label' => …, 'account_number' => …]. Using the real column names (not 'label' /
 * 'account' aliases) means a knowledge row IS a candidate once candidate-gen lands — SELECT
 * normalized_label, account_number … with zero remap — and 'normalized_label' self-documents the
 * "already normalized" precondition.
 *
 * PARKED (deliberately, like the normalizer's transliteration): knowledge rows also carry weight
 * (observation count) and last_seen (recency) earmarked for ranking (§5). When those are added to
 * the candidate shape the matcher gains a deterministic tie-break (weight desc, then last_seen desc
 * — most-confirmed / freshest wins), and only then a tie-break test. Until then top-K ties keep
 * input order (PHP's sort is stable since 8.0); we do NOT pin a tie-break-by-input-order test, which
 * would cement a contract the schema contradicts.
 *
 * Return: the accepted account_number (string) on accept, or null on abstain. (A richer result
 * carrying the score + candidate set as evidence for decision_log is a conscious later cycle.)
 */
final class AccountMatcherTest extends TestCase
{
	/**
	 * Happy path: the top-K qualifying candidates agree on one account and clear the threshold →
	 * that account is accepted. Two identical corpus labels score 1.0 each (> 0.5) and both name
	 * "6000".
	 */
	public function testAcceptsWhenTopKAgreeAndAboveThreshold(): void
	{
		$candidates = [
			['normalized_label' => 'abcd', 'account_number' => '6000'],
			['normalized_label' => 'abcd', 'account_number' => '6000'],
		];

		$this->assertSame('6000', AccountMatcher::decide('abcd', $candidates, 2, 0.5));
	}

	/**
	 * Disagreement inside the top-K blocks the accept: both candidates score 1.0 (qualify) but name
	 * different accounts, so there is no single confident answer → abstain.
	 */
	public function testAbstainsWhenTopKDisagreeOnAccount(): void
	{
		$candidates = [
			['normalized_label' => 'abcd', 'account_number' => '6000'],
			['normalized_label' => 'abcd', 'account_number' => '7000'],
		];

		$this->assertNull(AccountMatcher::decide('abcd', $candidates, 2, 0.5));
	}

	/**
	 * Threshold gate: a candidate whose similarity does not exceed the threshold does not qualify.
	 * "abcd" vs "wxyz" share no trigrams → 0.0, which is not > 0.5 → no qualifying candidate → abstain.
	 */
	public function testAbstainsWhenNoCandidateExceedsThreshold(): void
	{
		$candidates = [
			['normalized_label' => 'wxyz', 'account_number' => '6000'],
		];

		$this->assertNull(AccountMatcher::decide('abcd', $candidates, 1, 0.5));
	}

	/**
	 * Matcher-side empty-label guard (spec §4, enforced here): an empty query label abstains BEFORE
	 * scoring, even with a candidate that would self-score 1.0 against it and a zero threshold.
	 * Without this guard two unrelated all-punctuation lines (both normalize to "") would match
	 * perfectly on garbage. (The index side must likewise never store an empty normalized_label —
	 * tracked in spec §4.)
	 */
	public function testAbstainsOnEmptyQueryLabel(): void
	{
		$candidates = [
			['normalized_label' => '', 'account_number' => '6000'],
		];

		$this->assertNull(AccountMatcher::decide('', $candidates, 1, 0.0));
	}

	/**
	 * Agreement is checked among the TOP-K qualifying candidates, not across every qualifier: a
	 * lower-scoring candidate that qualifies but falls outside the top-K must not block the accept.
	 * The two "abcd" copies score 1.0 (top-2, both "6000"); the "abce" candidate scores 1/3 (≈0.333,
	 * > 0.3 so it qualifies) and names "7000" but ranks 3rd → ignored → accept "6000".
	 */
	public function testAgreementIsAmongTopKNotAllQualified(): void
	{
		$candidates = [
			['normalized_label' => 'abcd', 'account_number' => '6000'],
			['normalized_label' => 'abcd', 'account_number' => '6000'],
			['normalized_label' => 'abce', 'account_number' => '7000'],
		];

		$this->assertSame('6000', AccountMatcher::decide('abcd', $candidates, 2, 0.3));
	}

	/**
	 * The single threshold gate — the only situation where a below-threshold candidate can affect
	 * the outcome. K=2 but only "abcd" qualifies (1.0); "wxyz" scores 0.0 and is not a vote. Because
	 * a below-threshold candidate always ranks below every qualifier, it reaches the top-K only when
	 * fewer than K qualify — exactly here — which is precisely the abstain case. Counting it (because
	 * it happens to share account_number "6000") would be the looser policy we rejected: one strong
	 * match is insufficient when K demands two → abstain (null). [Precision-first, confirmed.]
	 */
	public function testAbstainsWhenFewerThanKCandidatesQualify(): void
	{
		$candidates = [
			['normalized_label' => 'abcd', 'account_number' => '6000'],
			['normalized_label' => 'wxyz', 'account_number' => '6000'],
		];

		$this->assertNull(AccountMatcher::decide('abcd', $candidates, 2, 0.5));
	}
}
