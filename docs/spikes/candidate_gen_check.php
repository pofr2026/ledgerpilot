<?php
/**
 * Integration check for CandidateGenerator::generate() — the Dolibarr-coupled half that PHPUnit cannot
 * cover (it runs MATCH … AGAINST in BOOLEAN MODE against llx_ledgerpilot_knowledge). The pure
 * buildBooleanQuery() is unit-tested; this spike verifies the live FULLTEXT behaviour AND that the
 * real builder's output never makes the parser explode.
 *
 * It seeds a few knowledge rows, drives generate() + AccountMatcher::decide() for real, and tears down
 * every row it created (matched by source = 'spikeCG'). Throwaway (docs/spikes/), like the keystone
 * checks.
 *
 *   docker exec -w /var/www/html/custom/ledgerpilot dolibarr-dev-app \
 *     php /var/www/html/custom/ledgerpilot/docs/spikes/candidate_gen_check.php
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "CLI only.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/ledgerpilot/core/class/LabelNormalizer.php';
require_once DOL_DOCUMENT_ROOT.'/custom/ledgerpilot/core/class/LabelSimilarity.php';
require_once DOL_DOCUMENT_ROOT.'/custom/ledgerpilot/core/class/AccountMatcher.php';
require_once DOL_DOCUMENT_ROOT.'/custom/ledgerpilot/core/class/CandidateGenerator.php';

use LedgerPilot\AccountMatcher;
use LedgerPilot\CandidateGenerator;
use LedgerPilot\LabelNormalizer;

/** @var DoliDB $db */
/** @var Conf $conf */

const SOURCE = 'spikeCG';

$ASSERTIONS = array();
function check($name, $ok, $detail = '')
{
	global $ASSERTIONS;
	$ASSERTIONS[] = (bool) $ok;
	printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '');
}

function scalar($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		throw new Exception('Query failed: '.$db->lasterror()." -- $sql");
	}
	$obj = $db->fetch_object($res);
	return $obj ? $obj->n : null;
}

$p = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$otherEntity = $entity + 1;

/** Insert an L2 (label) knowledge row. */
function insLabel($label, $account, $entity)
{
	global $db, $p;
	$ok = $db->query(
		"INSERT INTO {$p}ledgerpilot_knowledge (entity, normalized_label, account_number, source, weight, last_seen, date_creation)"
		." VALUES (".((int) $entity).", '".$db->escape($label)."', '".$db->escape($account)."', '".SOURCE."', 1, NOW(), NOW())"
	);
	if (!$ok) {
		throw new Exception('insLabel failed: '.$db->lasterror());
	}
}

/** Insert an L1 (IBAN) knowledge row: normalized_label stays NULL. */
function insIban($hmac, $account, $entity)
{
	global $db, $p;
	$ok = $db->query(
		"INSERT INTO {$p}ledgerpilot_knowledge (entity, counterparty_iban_hmac, account_number, source, weight, last_seen, date_creation)"
		." VALUES (".((int) $entity).", '".$db->escape($hmac)."', '".$db->escape($account)."', '".SOURCE."', 1, NOW(), NOW())"
	);
	if (!$ok) {
		throw new Exception('insIban failed: '.$db->lasterror());
	}
}

$exit = 0;

try {
	// Clean any leftover from a prior aborted run, then seed.
	$db->query("DELETE FROM {$p}ledgerpilot_knowledge WHERE source = '".SOURCE."'");

	insLabel('spike alpha corp', '990001', $entity);
	insLabel('spike alpha trading', '990001', $entity);   // different label, SAME account → 2 agreeing votes
	insLabel('spike beta sa', '990002', $entity);          // shares only "spike"
	insIban(str_repeat('a', 64), '990009', $entity);       // L1 row: must be excluded (IS NOT NULL)
	insLabel('spike alpha gamma', '990003', $otherEntity); // other entity: must be excluded

	echo "  generate() scopes strictly to entity = $entity (\$conf->entity, PII isolation)\n";

	$query = LabelNormalizer::normalize('Spike Alpha');

	// --- 1. Candidate generation: OR on "spike"/"alpha", entity + IS NOT NULL filters ---------------
	$cands = CandidateGenerator::generate($db, $query, 10);
	$accounts = array_column($cands, 'account_number');
	check('gen: 3 candidates (alpha1, alpha2, beta via OR on "spike")', count($cands) === 3, 'count='.count($cands));
	check('gen: excludes the L1 IBAN row (normalized_label IS NOT NULL)', !in_array('990009', $accounts, true));
	check('gen: excludes the other-entity row', !in_array('990003', $accounts, true));

	// --- 2. Composition with the matcher: top-2 agree on 990001 -------------------------------------
	$decided = AccountMatcher::decide($query, $cands, 2, 0.3);
	check('matcher: top-2 qualifying agree → 990001', $decided === '990001', var_export($decided, true));

	// --- 3. [A] pathological labels through the REAL builder must not explode -----------------------
	check('[A] lone "+"        → [] (no SQL)',  CandidateGenerator::generate($db, '+', 10) === array());
	check('[A] all-symbol "$ = +" → []',        CandidateGenerator::generate($db, '$ = +', 10) === array());
	check('[A] lone double-quote → []',         CandidateGenerator::generate($db, '"', 10) === array());
	$opCands = CandidateGenerator::generate($db, 'spike +alpha', 10);  // operator stripped → real FULLTEXT query
	check('[A] stripped-operator query runs cleanly (no parser error)', count($opCands) === 3, 'count='.count($opCands));

	// --- 4. min_token caveat, verified live (innodb_ft_min_token_size = 3) --------------------------
	$agCands = CandidateGenerator::generate($db, LabelNormalizer::normalize('AG'), 10);
	check('min_token: only sub-3 token "ag" → 0 candidates, no error', count($agCands) === 0, 'count='.count($agCands));

	// --- 5. LIMIT honoured (injected as int) --------------------------------------------------------
	$limited = CandidateGenerator::generate($db, LabelNormalizer::normalize('Spike'), 1);
	check('LIMIT 1 honoured', count($limited) === 1, 'count='.count($limited));
} catch (Throwable $e) {
	echo "  EXCEPTION: ".$e->getMessage()."\n";
	$exit = 1;
} finally {
	$db->query("DELETE FROM {$p}ledgerpilot_knowledge WHERE source = '".SOURCE."'");
	$left = (int) scalar("SELECT COUNT(*) n FROM {$p}ledgerpilot_knowledge WHERE source = '".SOURCE."'");
	echo "  teardown done. leftover spikeCG rows: $left\n";
}

$failed = count(array_filter($ASSERTIONS, fn ($a) => !$a));
echo "=== candidate-gen: ".count($ASSERTIONS)." assertions, $failed failed ===\n";
exit($exit ?: ($failed > 0 ? 3 : 0));
