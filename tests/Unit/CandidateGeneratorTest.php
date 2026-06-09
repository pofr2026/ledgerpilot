<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\CandidateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for the PURE half of LedgerPilot\CandidateGenerator: buildBooleanQuery().
 *
 * CandidateGenerator is the candidate-generation half of the L2 retriever (spec §4): given an
 * incoming normalized label it FULLTEXT-searches the knowledge corpus (normalized_label) to produce a
 * recall-oriented shortlist that AccountMatcher then reranks (the precision step). FULLTEXT is the
 * recall stage by design — the table comment says "the real precision is the PHP trigram/Jaccard
 * rerank, not FULLTEXT alone" — so this stage casts a wide net (OR semantics) and lets the rerank cut.
 *
 * The DB execution (generate(), MATCH … AGAINST in BOOLEAN MODE against llx_ledgerpilot_knowledge) is
 * Dolibarr-coupled and is verified by an integration SPIKE, not here. THIS file covers only the pure,
 * unit-testable part: turning a normalized label into the BOOLEAN-mode AGAINST expression.
 *
 * TWO DISTINCT LAYERS — do not conflate (the point of [B]):
 *   - buildBooleanQuery() strips FULLTEXT BOOLEAN operators ("+ - < > ( ) ~ * \" @"). This is
 *     FULLTEXT-SEMANTICS correctness: a stray operator would change how the parser reads the query, or
 *     (a lone operator) make MariaDB throw a syntax error at runtime. It is NOT an SQL-safety barrier.
 *   - SQL-injection safety is provided separately, at the query site, by $db->escape() on the built
 *     string (DoliDB does not bind "?" placeholders). Never paste this builder's output into SQL
 *     unescaped on the assumption that it is "sanitized".
 *
 * EMPTY-QUERY CONTRACT (the precondition): when no usable search term survives (an empty label, a lone
 * operator, an all-symbol label), buildBooleanQuery() returns '' — and generate() must then return no
 * candidates WITHOUT running a FULLTEXT query (a pointless query, and a parser-risk one). This decision
 * lives here in the pure builder, not discovered in the spike.
 */
final class CandidateGeneratorTest extends TestCase
{
	/**
	 * Tokens join with spaces, which is OR in BOOLEAN mode (recall: any token may hit; the rerank
	 * decides). A normal two-token label passes through as its two tokens.
	 */
	public function testJoinsTokensForOrSemantics(): void
	{
		$this->assertSame('acme gmbh', CandidateGenerator::buildBooleanQuery('acme gmbh'));
	}

	/**
	 * [A] FULLTEXT-semantics correctness: any BOOLEAN operator surviving normalization (the normalizer
	 * passes \p{S} symbols, so "+", "<", ">", "~" can reach us) is replaced by a SPACE — never glued,
	 * never left in — so the parser sees plain OR tokens and cannot mis-parse or error. "acme +gmbh ~co"
	 * → "acme gmbh co".
	 */
	public function testStripsSurvivingBooleanOperators(): void
	{
		$this->assertSame('acme gmbh co', CandidateGenerator::buildBooleanQuery('acme +gmbh ~co'));
	}

	/**
	 * Precondition: a label that is a lone operator carries no search term → '' (so generate() runs no
	 * SQL). Without this a lone "+" or '"' reaches the BOOLEAN parser and throws a runtime syntax error
	 * — exactly the green-but-brittle gap a pure test must close. (A '"' would normally be stripped by
	 * the normalizer, but the builder defends against it regardless of how clean its input is.)
	 */
	public function testReturnsEmptyForLoneOperator(): void
	{
		$this->assertSame('', CandidateGenerator::buildBooleanQuery('+'));
		$this->assertSame('', CandidateGenerator::buildBooleanQuery('"'));
	}

	/**
	 * Precondition: an all-symbol label has no FULLTEXT-meaningful token → ''. "$ = +" → the "+" is
	 * stripped as an operator and "$"/"=" are dropped as tokens with no letter or digit (they would
	 * only run a guaranteed-zero-match query). The keep test is Unicode-aware (\p{L}/\p{N}), see
	 * testPreservesAccentedLetters.
	 */
	public function testReturnsEmptyForAllSymbolLabel(): void
	{
		$this->assertSame('', CandidateGenerator::buildBooleanQuery('$ = +'));
	}

	/**
	 * Precondition: the empty label yields the empty query (→ no SQL, no candidates).
	 */
	public function testReturnsEmptyForEmptyLabel(): void
	{
		$this->assertSame('', CandidateGenerator::buildBooleanQuery(''));
	}

	/**
	 * Accented letters are preserved (the normalizer keeps accents; transliteration is parked), so the
	 * FULLTEXT query searches the real token. This also pins that the "is this a real token" keep-check
	 * is Unicode-aware (\p{L}/\p{N}), not ASCII [a-z0-9] which could drop a non-ASCII token.
	 */
	public function testPreservesAccentedLetters(): void
	{
		$this->assertSame('café zürich', CandidateGenerator::buildBooleanQuery('café zürich'));
	}
}
