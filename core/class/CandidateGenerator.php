<?php

namespace LedgerPilot;

/**
 * Candidate-generation half of the L2 retriever (spec §4): given an incoming normalized label,
 * FULLTEXT-search the knowledge corpus (llx_ledgerpilot_knowledge.normalized_label) for a
 * recall-oriented shortlist of {normalized_label, account_number} rows that AccountMatcher then
 * reranks and decides on. FULLTEXT is the RECALL stage by design — the table comment states "the real
 * precision is the PHP trigram/Jaccard rerank, not FULLTEXT alone" — so this stage casts a wide net
 * (OR semantics) and the PHP rerank cuts it down.
 *
 * Split in two: buildBooleanQuery() is PURE and unit-tested; generate() is Dolibarr-coupled (runs the
 * MATCH … AGAINST in BOOLEAN MODE) and is verified by an integration spike, not unit tests.
 *
 * TWO DISTINCT LAYERS (do not conflate):
 *   - buildBooleanQuery() strips FULLTEXT BOOLEAN operators. This is FULLTEXT-SEMANTICS correctness: a
 *     stray operator changes how the parser reads the query, and a lone operator makes MariaDB throw a
 *     syntax error at runtime. It is NOT an SQL-safety mechanism.
 *   - SQL-injection safety is provided separately, in generate(), by $db->escape() on the built query
 *     string (DoliDB does not bind "?" placeholders). The two must not be confused: never feed this
 *     builder's output into SQL unescaped.
 */
final class CandidateGenerator
{
	/**
	 * Turn a normalized label into a BOOLEAN-mode AGAINST expression, or '' when it carries no usable
	 * search term.
	 *
	 * Every FULLTEXT BOOLEAN operator (+ - < > ( ) ~ * " @ and backslash) is replaced by a SPACE — not
	 * deleted (deleting would glue adjacent tokens) and not left in (a stray operator changes the parse;
	 * a lone one is a runtime syntax error). Tokens are then kept only if they carry a real letter or
	 * digit (Unicode-aware, so accented tokens survive); a pure-symbol token like "$" is
	 * FULLTEXT-meaningless and would only run a guaranteed-zero-match query. The surviving tokens are
	 * space-joined, which is OR in BOOLEAN mode (recall: any token may hit, the rerank decides).
	 *
	 * Empty result contract: when no usable token survives (an empty label, a lone operator, an
	 * all-symbol label) this returns '' and generate() must then run no SQL at all.
	 *
	 * This is FULLTEXT-semantics correctness only — SQL safety is generate()'s $db->escape().
	 *
	 * @param  string $normalizedLabel A label already canonicalised by LabelNormalizer.
	 * @return string                  The BOOLEAN-mode query, or '' if there is no usable term.
	 */
	public static function buildBooleanQuery(string $normalizedLabel): string
	{
		// FULLTEXT BOOLEAN operators → space (never glue, never leave in). Defensive: strip the whole
		// operator set regardless of how clean the input is, so none can ever reach the parser.
		$stripped = preg_replace('/[-+<>()~*"@\\\\]/u', ' ', $normalizedLabel);

		$tokens = [];
		foreach (preg_split('/\s+/u', (string) $stripped, -1, PREG_SPLIT_NO_EMPTY) as $token) {
			// Keep only tokens with a real letter or digit (Unicode-aware so "café"/"zürich" survive);
			// a pure-symbol token carries no FULLTEXT meaning.
			if (preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
				$tokens[] = $token;
			}
		}

		return implode(' ', $tokens);
	}

	/**
	 * Generate L2 candidates for a normalized label: the knowledge rows whose normalized_label
	 * FULLTEXT-matches it, as {normalized_label, account_number} arrays ready for AccountMatcher.
	 *
	 * Dolibarr-coupled (verified by the spike, not unit tests). Scopes to L2 rows only
	 * (normalized_label IS NOT NULL excludes the L1 IBAN rows that share this table) and to the CURRENT
	 * entity.
	 *
	 * Entity scope is STRICT $conf->entity, deliberately NOT getEntity(): the knowledge corpus is PII
	 * (pseudonymised IBANs, counterparty names), so it stays company-isolated by default. getEntity()
	 * honours the sharing config, which an admin could later widen — silently leaking the PII corpus
	 * cross-company. Cross-company sharing of this corpus must be a deliberate, RODO-reviewed decision,
	 * never a getEntity() side effect.
	 *
	 * SECURITY (two layers): the AGAINST literal is safe ONLY because its sole source is
	 * buildBooleanQuery(), which has already stripped the FULLTEXT BOOLEAN operators; $db->escape() then
	 * closes SQL injection. Do NOT feed any other string into this literal — an un-stripped label would
	 * re-introduce FULLTEXT-operator injection (a parser-level attack, distinct from SQL injection).
	 *
	 * When the built query is empty no SQL runs. LIMIT is injected as an int (DoliDB does not bind
	 * placeholders) and floored at 1. A query error is logged and yields no candidates (fail-soft: a
	 * retriever hiccup must not crash the batch — the line falls through to manual).
	 *
	 * @param  \DoliDB $db              Open Dolibarr database handle.
	 * @param  string  $normalizedLabel The incoming label, already normalized (LabelNormalizer output).
	 * @param  int     $limit           Max candidates to return (from llx_const; floored at 1).
	 * @return array<int, array{normalized_label: string, account_number: string}> The candidate rows.
	 */
	public static function generate(\DoliDB $db, string $normalizedLabel, int $limit): array
	{
		$query = self::buildBooleanQuery($normalizedLabel);
		if ($query === '') {
			return [];
		}

		global $conf;

		$limit = max(1, $limit);
		// Strict current-entity scope: the PII corpus stays company-isolated (see the method note), not
		// getEntity() whose sharing config an admin could later widen.
		$sql = 'SELECT normalized_label, account_number'
			.' FROM '.MAIN_DB_PREFIX.'ledgerpilot_knowledge'
			.' WHERE entity = '.((int) $conf->entity)
			.' AND normalized_label IS NOT NULL'
			." AND MATCH(normalized_label) AGAINST ('".$db->escape($query)."' IN BOOLEAN MODE)"
			.' LIMIT '.((int) $limit);

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog('LedgerPilot CandidateGenerator::generate query failed: '.$db->lasterror(), LOG_ERR);

			return [];
		}

		$candidates = [];
		while ($obj = $db->fetch_object($resql)) {
			$candidates[] = [
				'normalized_label' => $obj->normalized_label,
				'account_number'   => $obj->account_number,
			];
		}
		$db->free($resql);

		return $candidates;
	}
}
