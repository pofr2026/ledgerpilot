<?php
/**
 * SPIKE — queue claim / reap concurrency contract (spec §5/§7).
 *
 * DURABLE regression-guard (spec §8, like candidate_gen_check.php / commit_reversal_atomicity_check.php):
 * it verifies live-MariaDB assumptions that MUST keep holding — that `UPDATE ... ORDER BY rowid LIMIT n`
 * claims the lowest-rowid pending rows (FIFO; MariaDB's UPDATE..LIMIT is otherwise unordered), that a
 * claim X-locks its rows so a concurrent claim cannot double-grab them, and the reap / dead-letter
 * semantics — so it stays in docs/spikes/ and is re-runnable.
 *
 * It DRIVES the production QueueService (no duplicated SQL skeleton — the spike is its live integration
 * test). The one exception is the exclusivity phase, which needs a SECOND raw connection to hold an
 * uncommitted claim while a competitor attempts one; that competitor runs the claim UPDATE raw (mirroring
 * QueueService) because it must run on the other connection.
 *
 * Isolation: every spike row uses a dedicated SPIKE_ENTITY, so claim/reap (entity-scoped) only ever see
 * spike rows and teardown is an exact `DELETE WHERE entity = SPIKE_ENTITY` — real queue rows are untouched.
 *
 * Run:
 *   docker exec -w /var/www/html/custom/ledgerpilot dolibarr-dev-app php docs/spikes/queue_claim_reap_check.php
 *   ... --phase=exclusivity        (single phase)
 */

// ---------------------------------------------------------------------------
// CLI bootstrap
// ---------------------------------------------------------------------------
if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "This spike must be run from the CLI.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';

// The production engine classes under test — this spike DRIVES them.
require_once __DIR__.'/../../core/class/QueueStatus.php';
require_once __DIR__.'/../../core/class/RequeueDecision.php';
require_once __DIR__.'/../../core/class/QueueService.php';

use LedgerPilot\QueueStatus;
use LedgerPilot\QueueService;

/** @var DoliDB $db */
/** @var Conf $conf */

// ---------------------------------------------------------------------------
// Fixture configuration
// ---------------------------------------------------------------------------
const SPIKE_ENTITY   = 990001;     // dedicated entity → claim/reap only ever see spike rows
const SPIKE_FK_BASE  = 990000000;  // synthetic fk_bank base (queue.fk_bank has no FK; far above real lines)
const LEASE_TIMEOUT  = 600;        // seconds; reap considers a lease older than this stale
const MAX_ATTEMPTS   = 3;          // dead-letter cutoff (mirrors RequeueDecision in the spike's assertions)
const BATCH          = 5;
const WORKER_ID      = 'spike-worker';

// ---------------------------------------------------------------------------
// Reporting / DB helpers
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

/** Delete every spike row (exact: only our dedicated entity). */
function teardown_spike()
{
	global $db;
	$db->query('DELETE FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE entity = '.SPIKE_ENTITY);
}

/** Enqueue $n pending rows (fk_bank = SPIKE_FK_BASE+1..+n) via the production enqueue; return their rowids, FIFO. */
function spike_enqueue_n($n)
{
	global $db;
	for ($i = 1; $i <= $n; $i++) {
		QueueService::enqueue($db, SPIKE_ENTITY, SPIKE_FK_BASE + $i);
	}
	return spike_all_rowids();
}

/** All spike rowids, ascending (rowid is AUTO_INCREMENT, so this is enqueue/FIFO order). */
function spike_all_rowids()
{
	global $db;
	$ids = array();
	$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE entity = '.SPIKE_ENTITY.' ORDER BY rowid');
	while ($res && ($o = $db->fetch_object($res))) {
		$ids[] = (int) $o->rowid;
	}
	return $ids;
}

function spike_status($rowid)
{
	return (string) db_scalar('SELECT status n FROM '.MAIN_DB_PREFIX.'ledgerpilot_queue WHERE rowid = '.((int) $rowid));
}

/** Rowids currently holding a given lease token, ascending. */
function rowids_by_token($token)
{
	global $db;
	$ids = array();
	$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX."ledgerpilot_queue WHERE lease_token = '".$db->escape($token)."' ORDER BY rowid");
	while ($res && ($o = $db->fetch_object($res))) {
		$ids[] = (int) $o->rowid;
	}
	return $ids;
}

// ---------------------------------------------------------------------------
// PHASES
// ---------------------------------------------------------------------------

/** claim() takes the BATCH lowest-rowid pending rows (FIFO), flips them to leased, increments attempts. */
function phase_fifo()
{
	global $db;
	teardown_spike();

	$all = spike_enqueue_n(10);
	check('enqueued 10 pending rows', count($all) === 10, 'n='.count($all));

	$claimed = QueueService::claim($db, SPIKE_ENTITY, WORKER_ID, BATCH);
	$claimedIds = array_map(static function ($r) { return $r['rowid']; }, $claimed);

	check('claimed exactly BATCH rows', count($claimed) === BATCH, 'n='.count($claimed));
	check('claimed the BATCH lowest rowids (FIFO)', $claimedIds === array_slice($all, 0, BATCH), 'got='.implode(',', $claimedIds));
	check('claimed rows are leased', spike_status($all[0]) === QueueStatus::LEASED, 'status='.spike_status($all[0]));
	check('un-claimed rows stay pending', spike_status($all[BATCH]) === QueueStatus::PENDING, 'status='.spike_status($all[BATCH]));
	check('attempts incremented at claim', (int) $claimed[0]['attempts'] === 1, 'attempts='.$claimed[0]['attempts']);
}

/** A second claim takes the NEXT lowest rows — disjoint from the first, FIFO preserved. */
function phase_disjoint()
{
	global $db;
	teardown_spike();

	$all = spike_enqueue_n(10);
	$first  = array_map(static function ($r) { return $r['rowid']; }, QueueService::claim($db, SPIKE_ENTITY, 'w1', BATCH));
	$second = array_map(static function ($r) { return $r['rowid']; }, QueueService::claim($db, SPIKE_ENTITY, 'w2', BATCH));

	check('first claim = lowest BATCH', $first === array_slice($all, 0, BATCH), implode(',', $first));
	check('second claim = next BATCH', $second === array_slice($all, BATCH, BATCH), implode(',', $second));
	check('the two claims are disjoint', array_intersect($first, $second) === array(), 'overlap='.implode(',', array_intersect($first, $second)));
}

/**
 * Exclusivity: while connection A holds an UNcommitted claim, a competitor B trying to claim the SAME
 * pending rows blocks on A's row locks (→ lock-wait timeout, errno 1205) — it cannot double-grab. After A
 * commits, B's retry sees them as leased and takes the next rows instead (disjoint). This is the InnoDB
 * guarantee QueueService::claim() relies on for its autocommit-only model.
 */
function phase_exclusivity()
{
	global $db;
	teardown_spike();

	$all = spike_enqueue_n(10);

	// Connection B: a second raw connection that will compete for the claim.
	mysqli_report(MYSQLI_REPORT_OFF);
	$b = @new mysqli($GLOBALS['dolibarr_main_db_host'], $GLOBALS['dolibarr_main_db_user'], $GLOBALS['dolibarr_main_db_pass'], $GLOBALS['dolibarr_main_db_name'], (int) $GLOBALS['dolibarr_main_db_port']);
	if ($b->connect_errno) {
		check('second connection opened', false, $b->connect_error);
		return;
	}
	$b->query('SET SESSION innodb_lock_wait_timeout = 1');
	$p = MAIN_DB_PREFIX;
	$claimSql = static function ($token) use ($p) {
		return 'UPDATE '.$p.'ledgerpilot_queue'
			." SET status = 'leased', lease_token = '".$token."', worker_id = 'B', claimed_at = NOW(), attempts = attempts + 1"
			." WHERE entity = ".SPIKE_ENTITY." AND status = 'pending' ORDER BY rowid LIMIT ".BATCH;
	};

	// A claims rows 1..BATCH inside an open tx (locks held, NOT committed).
	$db->begin();
	$aRows = array_map(static function ($r) { return $r['rowid']; }, QueueService::claim($db, SPIKE_ENTITY, 'A', BATCH));

	// B tries to claim concurrently → must block on A's locks (lock-wait timeout 1205, or deadlock-victim
	// 1213); either way it cannot double-grab the locked rows.
	$bBlocked = !$b->query($claimSql('tokB1')) && in_array($b->errno, array(1205, 1213), true);
	check('competing claim is blocked from the locked rows (errno 1205/1213)', $bBlocked, 'errno='.$b->errno);

	$db->commit(); // A's rows 1..BATCH are now leased + committed.

	// B retries → the locked rows are now leased (not pending) → B takes the next BATCH.
	$b->query($claimSql('tokB2'));
	$bRows = rowids_by_token('tokB2');

	check('A claimed the lowest BATCH', $aRows === array_slice($all, 0, BATCH), implode(',', $aRows));
	check('B retry claimed the next BATCH (disjoint)', $bRows === array_slice($all, BATCH, BATCH), implode(',', $bRows));
	check('no row was claimed by both', array_intersect($aRows, $bRows) === array(), 'overlap='.implode(',', array_intersect($aRows, $bRows)));

	$b->close();
}

/**
 * Reap: a stale lease (claimed_at older than the timeout) goes back to pending (attempts below the
 * cutoff) or to dead (at the cutoff); a FRESH lease is left alone. The dead-letter UPDATE runs before the
 * pending-return, so a just-deadded row is not re-touched.
 */
function phase_reap()
{
	global $db;
	teardown_spike();
	$p = MAIN_DB_PREFIX;

	$all = spike_enqueue_n(4);
	// Claim 3 of the 4 (rows 0..2 leased, attempts=1); row 3 stays pending.
	QueueService::claim($db, SPIKE_ENTITY, WORKER_ID, 3);

	// Make rows 0 and 1 STALE; leave row 2 fresh. Row 0 is at the attempts cutoff (→ dead-letter).
	$staleAt = "NOW() - INTERVAL ".(LEASE_TIMEOUT + 60)." SECOND";
	$db->query('UPDATE '.$p.'ledgerpilot_queue SET claimed_at = '.$staleAt.', attempts = '.MAX_ATTEMPTS.' WHERE rowid = '.$all[0]);
	$db->query('UPDATE '.$p.'ledgerpilot_queue SET claimed_at = '.$staleAt.' WHERE rowid = '.$all[1]);

	$res = QueueService::reap($db, SPIKE_ENTITY, LEASE_TIMEOUT, MAX_ATTEMPTS);

	check('reap dead-lettered the at-cutoff stale row', $res['dead'] === 1, 'dead='.$res['dead']);
	check('reap requeued the below-cutoff stale row', $res['requeued'] === 1, 'requeued='.$res['requeued']);
	check('at-cutoff stale row is now dead', spike_status($all[0]) === QueueStatus::DEAD, 'status='.spike_status($all[0]));
	check('below-cutoff stale row is back to pending', spike_status($all[1]) === QueueStatus::PENDING, 'status='.spike_status($all[1]));
	check('fresh lease is left leased', spike_status($all[2]) === QueueStatus::LEASED, 'status='.spike_status($all[2]));

	$lease = (string) db_scalar('SELECT COALESCE(lease_token, \'\') n FROM '.$p.'ledgerpilot_queue WHERE rowid = '.$all[1]);
	check('requeued row cleared its lease token', $lease === '', "token='$lease'");
}

/**
 * release(): a per-row failure-release routes the row to pending (retry) or dead (cutoff) via the SAME
 * RequeueDecision rule the reap uses; a requeued row clears its lease, a dead row keeps it as forensics.
 */
function phase_release()
{
	global $db;
	teardown_spike();
	$p = MAIN_DB_PREFIX;

	$all = spike_enqueue_n(2);
	QueueService::claim($db, SPIKE_ENTITY, WORKER_ID, 2); // both leased, attempts=1

	// Row 0: failed with attempts below the cutoff → back to pending, lease cleared.
	$s0 = QueueService::release($db, $all[0], 1, MAX_ATTEMPTS);
	check('release below cutoff → PENDING', $s0 === QueueStatus::PENDING, "status=$s0");
	check('released row is pending', spike_status($all[0]) === QueueStatus::PENDING);
	$tok0 = (string) db_scalar('SELECT COALESCE(lease_token, \'\') n FROM '.$p.'ledgerpilot_queue WHERE rowid = '.$all[0]);
	check('released-to-pending row cleared its lease', $tok0 === '', "token='$tok0'");

	// Row 1: failed at the cutoff → dead-letter, lease kept as forensics.
	$s1 = QueueService::release($db, $all[1], MAX_ATTEMPTS, MAX_ATTEMPTS);
	check('release at cutoff → DEAD', $s1 === QueueStatus::DEAD, "status=$s1");
	check('released row is dead', spike_status($all[1]) === QueueStatus::DEAD);
	$tok1 = (string) db_scalar('SELECT COALESCE(lease_token, \'\') n FROM '.$p.'ledgerpilot_queue WHERE rowid = '.$all[1]);
	check('dead row kept its lease token (forensics)', $tok1 !== '', "token='$tok1'");
}

/**
 * Idempotency: INSERT IGNORE under UNIQUE(fk_bank) enqueues a line at most once, and a done/dead tombstone
 * keeps it from being re-enqueued (never resurrected to pending) — so a processed line is not re-categorized.
 */
function phase_insert_ignore()
{
	global $db;
	teardown_spike();
	$p = MAIN_DB_PREFIX;
	$fk = SPIKE_FK_BASE + 1;

	$first = QueueService::enqueue($db, SPIKE_ENTITY, $fk);
	check('first enqueue inserts', $first === true);

	$second = QueueService::enqueue($db, SPIKE_ENTITY, $fk);
	check('duplicate enqueue is ignored', $second === false);
	check('still exactly one row for the fk_bank', (int) db_scalar('SELECT COUNT(*) n FROM '.$p.'ledgerpilot_queue WHERE fk_bank = '.$fk) === 1);

	// Tombstone it (engine finished the line) and try to re-enqueue.
	$db->query('UPDATE '.$p."ledgerpilot_queue SET status = 'done' WHERE fk_bank = ".$fk);
	$third = QueueService::enqueue($db, SPIKE_ENTITY, $fk);
	check('enqueue against a done tombstone is ignored', $third === false);
	check('tombstone not resurrected (still done)', spike_status(spike_all_rowids()[0]) === QueueStatus::DONE, 'status='.spike_status(spike_all_rowids()[0]));
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
	'fifo'          => 'phase_fifo',
	'disjoint'      => 'phase_disjoint',
	'exclusivity'   => 'phase_exclusivity',
	'reap'          => 'phase_reap',
	'release'       => 'phase_release',
	'insert-ignore' => 'phase_insert_ignore',
);

echo "=== SPIKE queue claim/reap  phase=$phaseArg ===\n";

// Refuse to run if spike rows linger from a crashed previous run (would skew FIFO assertions).
if (!empty(spike_all_rowids())) {
	teardown_spike();
}

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
		// Ensure no open tx leaks from the exclusivity phase, then clean our rows.
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
