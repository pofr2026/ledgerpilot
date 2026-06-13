<?php
/**
 * SPIKE — queue worker orchestration + per-line atomicity (spec §5/§7).
 *
 * DURABLE regression-guard (spec §8): drives the production LedgerPilotWorker on the live dev DB with a
 * FAKE categorizer (the engine facade is an injected seam), so it proves the orchestration + the per-line
 * transaction without depending on the full engine cascade. It is the worker's live integration test.
 *
 * What it pins:
 *   - ProposalPlan → INSERT: a PROPOSE verdict writes exactly one pending proposal with the right columns
 *     (the dual-mode rule: account-track sets proposed_account; step0 sets fk_facture).
 *   - SKIP / MANUAL: queue → done, NO proposal.
 *   - Per-line atomicity (finding #4): a fault thrown AFTER the proposal INSERT but BEFORE queue→done
 *     rolls the INSERT back — the line is released (not done, no orphan proposal) and a reprocess writes
 *     exactly one proposal. This is why the worker needs no "delete a half-written proposal" step.
 *   - Failure routing: a line that fails at the attempts cutoff is dead-lettered, below it is requeued.
 *   - run(): reap + claim + per-line loop produce the right summary tally.
 *
 * Isolation: queue AND proposal rows use a dedicated SPIKE_ENTITY with synthetic fk_bank values, so
 * teardown is an exact DELETE and real data is untouched (the fake categorizer never reads llx_bank).
 *
 * Run:
 *   docker exec -w /var/www/html/custom/ledgerpilot dolibarr-dev-app php docs/spikes/worker_pipeline_check.php
 *   ... --phase=atomicity
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "This spike must be run from the CLI.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';

require_once __DIR__.'/../../core/class/QueueStatus.php';
require_once __DIR__.'/../../core/class/ProposalStatus.php';
require_once __DIR__.'/../../core/class/ProposalLayer.php';
require_once __DIR__.'/../../core/class/RequeueDecision.php';
require_once __DIR__.'/../../core/class/ProposalPlan.php';
require_once __DIR__.'/../../core/class/QueueService.php';
require_once __DIR__.'/../../core/class/LedgerPilotWorker.php';

use LedgerPilot\QueueStatus;
use LedgerPilot\ProposalStatus;
use LedgerPilot\ProposalLayer;
use LedgerPilot\ProposalPlan;
use LedgerPilot\QueueService;
use LedgerPilot\LedgerPilotWorker;

/** @var DoliDB $db */

const SPIKE_ENTITY  = 990002;
const SPIKE_FK_BASE = 990100000;
const LEASE_TIMEOUT = 600;
const MAX_ATTEMPTS  = 3;

/**
 * Fake engine facade: returns a canned verdict per fk_bank (or a default), and can be told to throw for a
 * given fk_bank (to exercise the engine-failure path). preflight is a no-op.
 */
class FakeCategorizer
{
	public $verdictByFk = array();
	public $default     = array('type' => 'none');
	public $throwForFk  = array();

	public function preflight(\DoliDB $db, int $entity): void
	{
	}

	public function categorize(\DoliDB $db, int $fkBank): array
	{
		if (in_array($fkBank, $this->throwForFk, true)) {
			throw new \Exception('injected engine failure for fk_bank '.$fkBank);
		}

		return $this->verdictByFk[$fkBank] ?? $this->default;
	}
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$ASSERTIONS = array();
function check($name, $ok, $detail = '')
{
	global $ASSERTIONS;
	$ASSERTIONS[] = array('ok' => (bool) $ok);
	printf("    [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '');
}

function db_scalar($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		throw new Exception('Query failed: '.$db->lasterror()." -- $sql");
	}
	$obj = $db->fetch_object($res);
	return $obj ? $obj->n : null;
}

function teardown_spike()
{
	global $db;
	$db->query('DELETE FROM '.MAIN_DB_PREFIX.'ledgerpilot_proposal WHERE entity = '.SPIKE_ENTITY);
	$db->query('DELETE FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE entity = '.SPIKE_ENTITY);
}

function queue_status($fkBank)
{
	return (string) db_scalar('SELECT status n FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE entity = '.SPIKE_ENTITY.' AND fk_bank = '.((int) $fkBank));
}

function queue_token($fkBank)
{
	return (string) db_scalar('SELECT COALESCE(lease_token, \'\') n FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE entity = '.SPIKE_ENTITY.' AND fk_bank = '.((int) $fkBank));
}

function proposal_count($fkBank)
{
	return (int) db_scalar('SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX.'ledgerpilot_proposal WHERE entity = '.SPIKE_ENTITY.' AND fk_bank = '.((int) $fkBank));
}

function proposal_field($fkBank, $field)
{
	return db_scalar('SELECT '.$field.' n FROM '.MAIN_DB_PREFIX.'ledgerpilot_proposal WHERE entity = '.SPIKE_ENTITY.' AND fk_bank = '.((int) $fkBank));
}

/** Enqueue one synthetic line and claim it; return the claimed row {rowid, fk_bank, attempts}. */
function enqueue_and_claim($fkBank)
{
	global $db;
	QueueService::enqueue($db, SPIKE_ENTITY, $fkBank);
	$claimed = QueueService::claim($db, SPIKE_ENTITY, 'spike', 1);
	return $claimed[0];
}

// ---------------------------------------------------------------------------
// PHASES
// ---------------------------------------------------------------------------

/** A PROPOSE (account-track) verdict writes one pending proposal with the right columns; queue → done. */
function phase_process_propose()
{
	global $db;
	teardown_spike();
	$fk  = SPIKE_FK_BASE + 1;
	$row = enqueue_and_claim($fk);

	$cat = new FakeCategorizer();
	$cat->verdictByFk[$fk] = array(
		'type' => ProposalPlan::VERDICT_ACCOUNT, 'layer' => ProposalLayer::L2,
		'account' => '6500', 'score' => 0.82, 'candidateSet' => '[{"account":"6500","score":0.82}]',
	);

	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS);

	check('outcome is PROPOSED', $outcome === LedgerPilotWorker::OUTCOME_PROPOSED, "outcome=$outcome");
	check('queue row is done', queue_status($fk) === QueueStatus::DONE, 'status='.queue_status($fk));
	check('exactly one proposal written', proposal_count($fk) === 1, 'n='.proposal_count($fk));
	check('proposal status is pending', (string) proposal_field($fk, 'status') === ProposalStatus::PENDING);
	check('proposal layer is l2', (string) proposal_field($fk, 'layer') === ProposalLayer::L2);
	check('proposed_account mapped', (string) proposal_field($fk, 'proposed_account') === '6500');
	check('score mapped', abs((float) proposal_field($fk, 'score') - 0.82) < 0.0001, 'score='.proposal_field($fk, 'score'));
	check('candidate_set mapped', (string) proposal_field($fk, 'candidate_set') === '[{"account":"6500","score":0.82}]');
	check('fk_facture is null (account track)', proposal_field($fk, 'fk_facture') === null);
}

/** A step0 invoice verdict writes the invoice track: fk_facture set, proposed_account NULL. */
function phase_process_invoice()
{
	global $db;
	teardown_spike();
	$fk  = SPIKE_FK_BASE + 2;
	$row = enqueue_and_claim($fk);

	$cat = new FakeCategorizer();
	$cat->verdictByFk[$fk] = array('type' => ProposalPlan::VERDICT_INVOICE, 'fkFacture' => 240, 'fkFactureFourn' => 0);

	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS);

	check('outcome is PROPOSED', $outcome === LedgerPilotWorker::OUTCOME_PROPOSED, "outcome=$outcome");
	check('proposal layer is step0', (string) proposal_field($fk, 'layer') === ProposalLayer::STEP0);
	check('fk_facture mapped', (int) proposal_field($fk, 'fk_facture') === 240);
	check('proposed_account is null (invoice track)', proposal_field($fk, 'proposed_account') === null);
}

/** An own-transfer verdict: queue → done, NO proposal (a deliberate skip). */
function phase_process_skip()
{
	global $db;
	teardown_spike();
	$fk  = SPIKE_FK_BASE + 3;
	$row = enqueue_and_claim($fk);

	$cat = new FakeCategorizer();
	$cat->verdictByFk[$fk] = array('type' => ProposalPlan::VERDICT_OWN_TRANSFER);

	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS);

	check('outcome is SKIPPED', $outcome === LedgerPilotWorker::OUTCOME_SKIPPED, "outcome=$outcome");
	check('queue row is done', queue_status($fk) === QueueStatus::DONE);
	check('no proposal written', proposal_count($fk) === 0, 'n='.proposal_count($fk));
}

/** A none verdict (cascade exhausted): queue → done, NO proposal (feeds the corpus manually later). */
function phase_process_manual()
{
	global $db;
	teardown_spike();
	$fk  = SPIKE_FK_BASE + 4;
	$row = enqueue_and_claim($fk);

	$cat = new FakeCategorizer(); // default verdict is 'none'

	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS);

	check('outcome is MANUAL', $outcome === LedgerPilotWorker::OUTCOME_MANUAL, "outcome=$outcome");
	check('queue row is done', queue_status($fk) === QueueStatus::DONE);
	check('no proposal written', proposal_count($fk) === 0, 'n='.proposal_count($fk));
}

/**
 * Per-line atomicity (finding #4): a fault AFTER the proposal INSERT but BEFORE queue→done rolls the
 * INSERT back — the line is released (pending, no orphan proposal). A reprocess then writes exactly one
 * proposal. This proves the proposal+done transaction is atomic and reprocessing is clean.
 */
function phase_atomicity()
{
	global $db;
	teardown_spike();
	$fk  = SPIKE_FK_BASE + 5;
	$row = enqueue_and_claim($fk); // attempts = 1

	$cat = new FakeCategorizer();
	$cat->verdictByFk[$fk] = array('type' => ProposalPlan::VERDICT_ACCOUNT, 'layer' => ProposalLayer::L1, 'account' => '6000');

	$fault = static function ($point) {
		throw new \Exception('injected fault at '.$point);
	};
	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS, $fault);

	check('failed line is requeued (attempts below cutoff)', $outcome === LedgerPilotWorker::OUTCOME_REQUEUED, "outcome=$outcome");
	check('proposal INSERT was rolled back (no orphan)', proposal_count($fk) === 0, 'n='.proposal_count($fk));
	check('queue row is back to pending', queue_status($fk) === QueueStatus::PENDING, 'status='.queue_status($fk));
	check('lease cleared on requeue', queue_token($fk) === '', "token='".queue_token($fk)."'");

	// Reprocess: claim again (attempts → 2) and let it succeed.
	$row2 = QueueService::claim($db, SPIKE_ENTITY, 'spike', 1)[0];
	$outcome2 = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row2, MAX_ATTEMPTS);

	check('reprocess proposes cleanly', $outcome2 === LedgerPilotWorker::OUTCOME_PROPOSED, "outcome=$outcome2");
	check('exactly one proposal after reprocess', proposal_count($fk) === 1, 'n='.proposal_count($fk));
	check('queue row is done after reprocess', queue_status($fk) === QueueStatus::DONE);
}

/** A line that fails AT the attempts cutoff is dead-lettered (not retried forever). */
function phase_failure_dead()
{
	global $db;
	teardown_spike();
	$fk = SPIKE_FK_BASE + 6;
	QueueService::enqueue($db, SPIKE_ENTITY, $fk);
	// Pre-age it to one short of the cutoff, so the claim's increment lands exactly at MAX_ATTEMPTS.
	$db->query('UPDATE '.MAIN_DB_PREFIX.'ledgerpilot_queue SET attempts = '.(MAX_ATTEMPTS - 1).' WHERE entity = '.SPIKE_ENTITY.' AND fk_bank = '.$fk);
	$row = QueueService::claim($db, SPIKE_ENTITY, 'spike', 1)[0];

	$cat = new FakeCategorizer();
	$cat->throwForFk = array($fk); // engine fails on this line

	$outcome = LedgerPilotWorker::processLine($db, SPIKE_ENTITY, $cat, $row, MAX_ATTEMPTS);

	check('at-cutoff failure is dead-lettered', $outcome === LedgerPilotWorker::OUTCOME_DEAD, "outcome=$outcome");
	check('queue row is dead', queue_status($fk) === QueueStatus::DEAD, 'status='.queue_status($fk));
	check('no proposal written', proposal_count($fk) === 0);
}

/** run(): reap + claim + per-line loop tally a mixed batch correctly. */
function phase_run_summary()
{
	global $db;
	teardown_spike();

	$cat = new FakeCategorizer();
	$cat->verdictByFk[SPIKE_FK_BASE + 1] = array('type' => ProposalPlan::VERDICT_ACCOUNT, 'layer' => ProposalLayer::L1, 'account' => '6000');
	$cat->verdictByFk[SPIKE_FK_BASE + 2] = array('type' => ProposalPlan::VERDICT_OWN_TRANSFER);
	$cat->verdictByFk[SPIKE_FK_BASE + 3] = array('type' => ProposalPlan::VERDICT_NONE);
	$cat->verdictByFk[SPIKE_FK_BASE + 4] = array('type' => ProposalPlan::VERDICT_ACCOUNT, 'layer' => ProposalLayer::L2, 'account' => '6500', 'score' => 0.7, 'candidateSet' => '[]');
	foreach (array(1, 2, 3, 4) as $i) {
		QueueService::enqueue($db, SPIKE_ENTITY, SPIKE_FK_BASE + $i);
	}

	$summary = LedgerPilotWorker::run($db, SPIKE_ENTITY, $cat, 10, LEASE_TIMEOUT, MAX_ATTEMPTS);

	check('claimed 4', $summary['claimed'] === 4, 'claimed='.$summary['claimed']);
	check('proposed 2', $summary['proposed'] === 2, 'proposed='.$summary['proposed']);
	check('skipped 1', $summary['skipped'] === 1, 'skipped='.$summary['skipped']);
	check('manual 1', $summary['manual'] === 1, 'manual='.$summary['manual']);
	check('two proposals persisted', (int) db_scalar('SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX.'ledgerpilot_proposal WHERE entity = '.SPIKE_ENTITY) === 2);
	check('all four queue rows done', (int) db_scalar('SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX."ledgerpilot_queue WHERE entity = ".SPIKE_ENTITY." AND status = 'done'") === 4);
}

// ---------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------
$phaseArg = 'all';
foreach ($argv as $a) {
	if (strpos($a, '--phase=') === 0) {
		$phaseArg = substr($a, 8);
	}
}

$ALL_PHASES = array(
	'process-propose' => 'phase_process_propose',
	'process-invoice' => 'phase_process_invoice',
	'process-skip'    => 'phase_process_skip',
	'process-manual'  => 'phase_process_manual',
	'atomicity'       => 'phase_atomicity',
	'failure-dead'    => 'phase_failure_dead',
	'run-summary'     => 'phase_run_summary',
);

echo "=== SPIKE worker pipeline  phase=$phaseArg ===\n";
teardown_spike();

foreach ($ALL_PHASES as $phaseName => $fn) {
	if ($phaseArg !== 'all' && $phaseArg !== $phaseName) {
		continue;
	}
	echo "  [phase $phaseName]\n";
	try {
		$fn();
	} catch (Exception $e) {
		check("$phaseName no exception", false, $e->getMessage());
	} finally {
		if ($db->transaction_opened > 0) {
			$db->rollback();
		}
		teardown_spike();
	}
}

$total  = count($ASSERTIONS);
$passed = count(array_filter($ASSERTIONS, static function ($a) { return $a['ok']; }));
echo "\n=== RESULT: $passed / $total assertions passed ===\n";
exit($passed === $total ? 0 : 1);
