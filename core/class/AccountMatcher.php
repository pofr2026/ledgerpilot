<?php

namespace LedgerPilot;

/**
 * L2 acceptance policy of the engine (spec §4 Step 2): decide whether an incoming bank-line label
 * maps confidently to a single accounting account, or whether to abstain (→ manual review, which
 * then feeds the corpus). Pure, Dolibarr-free, unit-testable.
 *
 * It composes the two pure pieces already built: it normalizes nothing itself (the caller passes
 * LabelNormalizer output) and scores each candidate with LabelSimilarity (char-trigram Jaccard).
 * Candidate generation (MariaDB FULLTEXT) is DB-coupled and lands later as integration, so the
 * candidate shortlist is INJECTED here, keeping this class free of the database.
 *
 * Candidate shape mirrors the knowledge-table columns it projects (spec §5): each candidate is
 * ['normalized_label' => <corpus label>, 'account_number' => <account>]. Real column names (not
 * 'label'/'account' aliases) so a knowledge row IS a candidate once candidate-gen lands — SELECT
 * normalized_label, account_number … with zero remap.
 *
 * Policy:
 *   - A candidate VOTES only if its similarity to the query strictly exceeds the threshold
 *     ("similarity > threshold", §4). Because a below-threshold candidate always ranks below every
 *     qualifier, it can only enter the top-K when fewer than K candidates qualify — i.e. exactly the
 *     case we abstain on. "below threshold doesn't vote" and "need ≥K qualifying votes" are one rule.
 *   - Accept iff AT LEAST K candidates qualify AND the top-K qualifying candidates (highest scores)
 *     ALL name the same account_number (strict unanimity among the top-K). Otherwise abstain.
 *   - Empty query label → abstain before any scoring (an all-punctuation line normalizes to "" and
 *     would otherwise self-score 1.0 against an empty corpus label; spec §4).
 *
 * K and threshold are injected (from llx_const upstream, never hardcoded) and validated there, not
 * re-checked here: the threshold lies in [0,1) (so a negative threshold cannot admit a 0.0 score)
 * and K >= 1. K < 1 is a nonsensical config; in particular a negative K would make the array_slice
 * below take a negative length ("all but the last") and could then false-accept — so K is a
 * documented precondition of this method, validated at the config boundary.
 *
 * PARKED (spec §4): the knowledge rows also carry weight (observation count) and last_seen
 * (recency); when added to the candidate shape, top-K ties will break deterministically by weight
 * desc, then last_seen desc, then account_number asc — the SHARED L1/L2 ranking established by
 * IbanAccountLookup (adopt all three levels so L1 and L2 break ties identically). Until then, ties
 * keep input order (PHP's sort is stable since 8.0).
 */
final class AccountMatcher
{
	/**
	 * Decide the L2 account for a query label against an injected candidate shortlist.
	 *
	 * @param  string                                          $queryLabel Normalized incoming label
	 *                                                                     (LabelNormalizer output).
	 * @param  array<int, array{normalized_label: string, account_number: string}> $candidates
	 *                                                                     Corpus candidates (knowledge
	 *                                                                     projection), order need not
	 *                                                                     be sorted.
	 * @param  int    $topK      Number of agreeing votes required, >= 1 (validated in llx_const).
	 * @param  float  $threshold Minimum similarity a candidate must strictly exceed to vote (llx_const).
	 * @return string|null       The accepted account_number, or null to abstain (→ manual).
	 */
	public static function decide(string $queryLabel, array $candidates, int $topK, float $threshold): ?string
	{
		// Empty-label guard: an all-punctuation line normalizes to "", which the identity law would
		// self-score 1.0 — abstain before scoring so it can never auto-match.
		if ($queryLabel === '') {
			return null;
		}

		// Score each candidate exactly once (not inside the sort comparator) and keep only the ones
		// that strictly exceed the threshold — a below-threshold candidate does not vote.
		$qualified = [];
		foreach ($candidates as $candidate) {
			$score = LabelSimilarity::score($queryLabel, $candidate['normalized_label']);
			if ($score > $threshold) {
				$qualified[] = ['account_number' => $candidate['account_number'], 'score' => $score];
			}
		}

		// Need at least K qualifying votes; fewer is insufficient corroboration → abstain.
		if (count($qualified) < $topK) {
			return null;
		}

		// Rank the qualifiers by descending score and take the top K. usort is stable (PHP >= 8.0),
		// so equal scores keep input order until the parked weight/last_seen tie-break lands.
		usort($qualified, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
		$top = array_slice($qualified, 0, $topK);

		// Accept only on strict unanimity of the top-K on one account.
		$accounts = array_unique(array_column($top, 'account_number'));

		return count($accounts) === 1 ? reset($accounts) : null;
	}
}
