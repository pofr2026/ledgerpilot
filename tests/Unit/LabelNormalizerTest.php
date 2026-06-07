<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\LabelNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of LedgerPilot\LabelNormalizer::normalize().
 *
 * The L2 retriever matches an incoming bank-line label against a corpus of past labels
 * (trigram/Jaccard scoring) to suggest an accounting account. Raw CAMT labels for the
 * SAME counterparty differ in cosmetic ways — case, spacing, surrounding whitespace,
 * non-breaking spaces — that must NOT count as differences, or the scorer fragments one
 * counterparty into many near-duplicates and the agreement threshold never trips.
 *
 * normalize() is the single, pure, Dolibarr-free function that produces the canonical
 * form used BOTH when indexing the corpus and when querying it (so the two always agree).
 * This first cycle locks the bedrock contract — Unicode-aware casefold + whitespace
 * collapse — before the noisier rules (punctuation, accent transliteration, volatile
 * date/ref/amount tokens) land in later red→green steps.
 */
final class LabelNormalizerTest extends TestCase
{
	/**
	 * Bedrock: a label that differs only in letter case and in leading / trailing /
	 * repeated internal whitespace (incl. tabs) normalizes to a lowercased, trimmed,
	 * single-spaced string.
	 */
	public function testCasefoldsAndCollapsesWhitespace(): void
	{
		$this->assertSame('acme gmbh', LabelNormalizer::normalize("  ACME   GmbH \t"));
	}

	/**
	 * (a) The corpus-fragmentation invariant from the class docblock made executable:
	 * two raw forms of the same counterparty that differ ONLY cosmetically must collapse
	 * to one identical canonical key.
	 */
	public function testSameCounterpartyVariantsCollapseToOneKey(): void
	{
		$this->assertSame(
			LabelNormalizer::normalize("ACME  GmbH"),
			LabelNormalizer::normalize("  acme\tGMBH ")
		);
	}

	/**
	 * (b) Accented CH/DE/FR capitals must fold with mb_strtolower (plain strtolower
	 * leaves É/Ä/Ü untouched → broken keys). The accent itself is PRESERVED here;
	 * stripping it (café → cafe) is a separate, later transliteration step.
	 */
	public function testLowercasesAccentedCapitalsKeepingTheAccent(): void
	{
		$this->assertSame('café müller', LabelNormalizer::normalize("CAFÉ  MÜLLER"));
	}

	/**
	 * (c) Bank exports embed non-breaking spaces (U+00A0); a Unicode-aware collapse
	 * ([\s\p{Z}]) must treat them like ordinary spaces, otherwise the NBSP survives as
	 * a distinct character and fragments the corpus.
	 */
	public function testCollapsesNonBreakingSpaces(): void
	{
		$this->assertSame('acme gmbh', LabelNormalizer::normalize("ACME\u{00A0}\u{00A0}GmbH"));
	}
}
