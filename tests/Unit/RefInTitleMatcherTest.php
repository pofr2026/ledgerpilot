<?php

namespace LedgerPilot\Tests\Unit;

use LedgerPilot\RefInTitleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for LedgerPilot\RefInTitleMatcher — the PURE core of the Step 0 ref-in-title fallback
 * (spec §4): when the structured-reference lookup misses, try to recognise a known OPEN-invoice ref
 * inside the bank line's free-text title. Pure, Dolibarr-free; the DB side (fetch the matched ref +
 * verdict) is InvoiceByRefResolver, spike-verified.
 *
 * Build/probe split, mirroring ProcessorClearingMap (buildNameIndex / clearingForName):
 *   - buildRefIndex($knownRefs) runs ONCE per batch: it turns the pre-flight index's raw open-invoice
 *     refs (spec §4, NOT a hardcoded ref-format regex — DRY with the planned index, and addon-agnostic)
 *     into a {normalized ref → raw ref} map, applying the discriminability guard (B) below.
 *   - match($normalizedTitle, $refIndex) runs per line: whole-token probe of the already-normalized
 *     title, returning the RAW ref (the key the DB layer fetches by, uk_facture_ref) or null.
 *
 * Two load-bearing rules:
 *   - WHOLE-TOKEN (DQ1): the same space-padded str_contains idiom as ProcessorClearingMap. A ref's
 *     normalized token sequence must appear contiguously on token boundaries, so '...0158' never matches
 *     inside '01580'. The hyphen in 'TC1-2605-0158' normalizes to spaces → 'tc1 2605 0158' (3 tokens),
 *     matched as a contiguous run.
 *   - DISCRIMINABILITY GUARD (B): without an fk_soc cross-check (unknown in v0.1) a ref is the only
 *     guard against a coincidental token. A ref is eligible only when its normalized form has >= 2
 *     tokens OR a single token containing a letter (\p{L}); a lone letter-less token (a bare number like
 *     '2605', which is everywhere in bank titles — amounts, dates) is dropped from the index. Such an
 *     invoice simply falls through to manual (precision-first). Residual: a multi-token all-numeric ref
 *     ('2024 0001') stays eligible by the >= 2 rule; human-in-the-loop review (suggestion ≠ commit,
 *     spec §0) absorbs that.
 */
final class RefInTitleMatcherTest extends TestCase
{
	// --- buildRefIndex --------------------------------------------------------------------------------

	/**
	 * A normal ref (mod_facture_terre format) maps normalized → raw, ready for a whole-token probe.
	 */
	public function testBuildIndexMapsNormalizedToRaw(): void
	{
		$this->assertSame(
			['tc1 2605 0158' => 'TC1-2605-0158'],
			RefInTitleMatcher::buildRefIndex(['TC1-2605-0158'])
		);
	}

	/**
	 * Guard B: a lone letter-less token (a bare number) is too ambiguous to match in free text → it is
	 * dropped from the index entirely.
	 */
	public function testBuildIndexDropsBareNumericRef(): void
	{
		$this->assertSame([], RefInTitleMatcher::buildRefIndex(['2605']));
	}

	/**
	 * Guard B: a single token that contains a letter is discriminable enough → kept.
	 */
	public function testBuildIndexKeepsSingleTokenWithLetter(): void
	{
		$this->assertSame(['inv99' => 'INV99'], RefInTitleMatcher::buildRefIndex(['INV99']));
	}

	/**
	 * Guard B residual (pinned deliberately): a multi-token all-numeric ref stays eligible by the
	 * >= 2-token rule (Rule 1). Documents the accepted residual rather than silently drifting.
	 */
	public function testBuildIndexKeepsMultiTokenNumericRef(): void
	{
		$this->assertSame(['2024 0001' => '2024-0001'], RefInTitleMatcher::buildRefIndex(['2024-0001']));
	}

	/**
	 * A ref that normalizes to '' (all punctuation) is skipped, never indexed (it would space-pad into a
	 * match-everything probe — same reason ProcessorClearingMap skips empty names).
	 */
	public function testBuildIndexSkipsEmptyNormalizingRef(): void
	{
		$this->assertSame([], RefInTitleMatcher::buildRefIndex(['---']));
	}

	/**
	 * Two raw refs colliding on one normalized form: last wins (documented, like buildNameIndex). Refs
	 * are unique per uk_facture_ref so this is a rare normalization collision, not a data error.
	 */
	public function testBuildIndexCollisionLastWins(): void
	{
		$this->assertSame(
			['tc1 2605 0158' => 'TC1.2605.0158'],
			RefInTitleMatcher::buildRefIndex(['TC1-2605-0158', 'TC1.2605.0158'])
		);
	}

	// --- match ----------------------------------------------------------------------------------------

	/**
	 * Whole-token contiguous hit inside a normalized title returns the RAW ref (the DB lookup key).
	 */
	public function testMatchReturnsRawRefOnWholeTokenHit(): void
	{
		$index = RefInTitleMatcher::buildRefIndex(['TC1-2605-0158']);
		$this->assertSame(
			'TC1-2605-0158',
			RefInTitleMatcher::match('paiement facture tc1 2605 0158 merci', $index)
		);
	}

	/**
	 * Boundary (DQ1): the ref's last token must match on a boundary — 'tc1 2605 0158' must NOT match when
	 * the title's run is '... 0158x' (one token '0158x'), proving it is whole-token, not substring.
	 */
	public function testMatchRejectsNonBoundaryRun(): void
	{
		$index = RefInTitleMatcher::buildRefIndex(['TC1-2605-0158']);
		$this->assertNull(RefInTitleMatcher::match('paiement tc1 2605 0158x', $index));
	}

	/**
	 * No known ref present in the title → null (fall through to the next strategy / manual).
	 */
	public function testMatchReturnsNullWhenNoRefPresent(): void
	{
		$index = RefInTitleMatcher::buildRefIndex(['TC1-2605-0158']);
		$this->assertNull(RefInTitleMatcher::match('virement loyer juin', $index));
	}

	/**
	 * An empty title matches nothing.
	 */
	public function testMatchReturnsNullOnEmptyTitle(): void
	{
		$index = RefInTitleMatcher::buildRefIndex(['TC1-2605-0158']);
		$this->assertNull(RefInTitleMatcher::match('', $index));
	}

	/**
	 * When two indexed refs both appear in the title, the first in index order wins — deterministic for a
	 * given pre-flight index (insertion order), like clearingForName. The wiring keeps the index order
	 * stable (ORDER BY in the pre-flight query).
	 */
	public function testMatchFirstInIndexOrderWins(): void
	{
		$index = RefInTitleMatcher::buildRefIndex(['TC1-2605-0158', 'INV99']);
		$this->assertSame(
			'TC1-2605-0158',
			RefInTitleMatcher::match('inv99 et tc1 2605 0158', $index)
		);
	}
}
