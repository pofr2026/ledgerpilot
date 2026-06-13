<?php
/**
 * SPIKE — transaction / concurrency contract of the Step 0 payment commit & reversal (spec §6/§7).
 *
 * DURABLE regression-guard (spec §8): unlike an exploratory spike, this verifies assumptions that must
 * keep holding — the DoliDB depth-counter rollback behaviour (§2), READ COMMITTED scoping, and the exact
 * native bank_url shapes — so it stays in docs/spikes/ and is re-runnable, like candidate_gen_check.php.
 *
 * It DRIVES the production PaymentCommitService / PaymentReversalService end-to-end and asserts the
 * resulting DB state (the way bankimport's spike1_commit.php — a separate repo — exercised commit_ours):
 * there is no duplicated transaction skeleton, the spike IS the services' live integration test. The
 * commit/reverse adapters below delegate to the services; a fault injector (a callable thrown at a named
 * point inside a service's tx) lets the atomicity phases force a mid-transaction failure.
 *
 * Phases (each self-contained: setup → act → assert DB state → teardown to baseline):
 *   depth-counter §2 footgun: a NESTED rollback only decrements (row survives); the real ROLLBACK is at
 *                 the outer level (row gone) — why we never commit after a failed nested call.
 *   isolation     SET TRANSACTION ... READ COMMITTED before begin(), proven BEHAVIOURALLY (a 2nd
 *                 connection commits mid-tx): READ COMMITTED sees it, default REPEATABLE READ hides it —
 *                 so the SET took effect AND auto-reverted (per-tx scope, no pconnect/SESSION leak).
 *   commit-ok     commit() → COMMITTED; payment + both links on the existing line, invoice settled,
 *                 proposal flipped approved→booked (canonical lock order, guard-update first).
 *   commit-fail   inject a failure mid-tx (after create() + link #1) → outer rollback → DB clean (no
 *                 payment, no link, invoice still open) AND proposal NOT booked (still approved): the
 *                 depth-counter footgun handled (§2/§7) — never commit after a failed nested call.
 *   idempotency   commit the same proposal twice → second returns ALREADY_DONE (guard-update sees booked).
 *   invalid-state commit a non-approved (rejected) proposal → INVALID_STATE (distinct from ALREADY_DONE).
 *   backstop      a line that already carries a payment% bank_url link → ABORTED_SETTLED (per-line
 *                 anti-double-spend under FOR UPDATE on the line; replaces queue.UNIQUE(fk_bank)).
 *   sign-guard    a line whose sign contradicts the flow (sales flow on a debit line) → FAILED (D-C).
 *   reverse-ok    reverse a committed proposal → line-safe, payment + links gone, invoice reopened,
 *                 proposal booked→reversed (mirror guard).
 *   reverse-fail  inject a failure between delete() and the link cleanup → outer rollback → fully
 *                 untouched (payment + links survive, invoice still closed, proposal still booked): M2 —
 *                 reversal idempotency is sound ONLY because of the single outer tx (never an orphan state).
 *   drift         commit via the native "naive" addPaymentToBank() and assert the bank_url type/url it
 *                 writes equal PaymentFlow's constants (the durable drift oracle, §8).
 *
 * Run (all phases, both flows):
 *   docker exec -w /var/www/html/custom/ledgerpilot dolibarr-dev-app \
 *     php docs/spikes/commit_reversal_atomicity_check.php
 *   ... --phase=commit-fail --type=supplier      (single phase / flow)
 *
 * Like bankimport's spike1, the query patterns here are throwaway-spike convenience (entity filter
 * omitted on this single-entity dev DB; controlled inputs interpolated) and must NOT be ported verbatim
 * to the engine.
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
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';

// The production engine classes under test — this spike DRIVES them (no duplicated transaction skeleton).
require_once __DIR__.'/../../core/class/CommitDecision.php';
require_once __DIR__.'/../../core/class/PaymentFlow.php';
require_once __DIR__.'/../../core/class/ProposalStatus.php';
require_once __DIR__.'/../../core/class/CommitResult.php';
require_once __DIR__.'/../../core/class/ProposalGuard.php';
require_once __DIR__.'/../../core/class/PaymentCommitService.php';
require_once __DIR__.'/../../core/class/PaymentReversalService.php';

use LedgerPilot\CommitDecision;
use LedgerPilot\PaymentFlow;
use LedgerPilot\CommitResult;
use LedgerPilot\PaymentCommitService;
use LedgerPilot\PaymentReversalService;

/** @var DoliDB $db */
/** @var Conf $conf */

// ---------------------------------------------------------------------------
// Fixture configuration (mirror of spike1 — no magic numbers inline)
// ---------------------------------------------------------------------------
const TEST_ACCOUNT_ID   = 2;       // PostF-CHF (company currency = CHF, account currency = CHF)
const PAYMENT_MODE_ID   = 2;       // llx_c_paiement VIR
const PAYMENT_MODE_CODE = 'VIR';
const IMPORTED_LABEL    = 'LP-ATOMICITY-IMPORTED';

// Result aliases bound to the production enum, so the assertions follow CommitResult if its values change.
const R_COMMITTED       = CommitResult::COMMITTED;
const R_ALREADY_DONE    = CommitResult::ALREADY_DONE;
const R_ABORTED_SETTLED = CommitResult::ABORTED_SETTLED;
const R_ABORTED_OVERPAY = CommitResult::ABORTED_OVERPAY;
const R_INVALID_STATE   = CommitResult::INVALID_STATE;
const R_FAILED          = CommitResult::FAILED;
const R_REVERSED        = CommitResult::REVERSED;

// Per-flow fixtures: which open invoice each flow posts against (same as spike1).
function flow_fixture(PaymentFlow $flow)
{
	return $flow->isPurchase
		? ['invoice_id' => 5,   'inv_table' => 'facture_fourn']   // open supplier invoice SI2602-0001
		: ['invoice_id' => 240, 'inv_table' => 'facture'];        // open sales invoice TC1-2605-0158
}

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

function url_count($fk_bank, $typeExpr)
{
	$p = MAIN_DB_PREFIX;
	return (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank_url WHERE fk_bank = ".((int) $fk_bank)." AND ".$typeExpr);
}

// Registries so teardown removes exactly what a phase created.
$CREATED_BANK_LINES = array();
$CREATED_PAYMENTS   = array();   // [ ['class'=>..., 'id'=>...], ... ]
$CREATED_PROPOSALS  = array();

// ---------------------------------------------------------------------------
// Fixture builders
// ---------------------------------------------------------------------------

/** The synthetic "imported CAMT" bank line bankimport would have inserted. $signOverride flips the sign. */
function setup_imported_line(User $user, $amount, PaymentFlow $flow, $signOverride = null)
{
	global $db, $CREATED_BANK_LINES;

	$sign = $signOverride !== null ? $signOverride : $flow->bankSign;

	$acc = new Account($db);
	if ($acc->fetch(TEST_ACCOUNT_ID) <= 0) {
		throw new Exception('Cannot fetch test bank account: '.$acc->error);
	}
	$lineId = $acc->addline(dol_now(), PAYMENT_MODE_CODE, IMPORTED_LABEL, (float) ($sign * $amount), '', 0, $user);
	if ($lineId <= 0) {
		throw new Exception('addline failed: '.$acc->error);
	}
	$CREATED_BANK_LINES[] = (int) $lineId;
	return (int) $lineId;
}

/** Insert an approved proposal (the work item commit() receives). $status lets a phase force a state. */
function setup_proposal($fkBank, $invoiceId, PaymentFlow $flow, $status = 'approved')
{
	global $db, $conf, $CREATED_PROPOSALS;
	$p = MAIN_DB_PREFIX;

	$fkFac     = $flow->isPurchase ? 'NULL' : ((int) $invoiceId);
	$fkFacFour = $flow->isPurchase ? ((int) $invoiceId) : 'NULL';

	$sql = "INSERT INTO {$p}ledgerpilot_proposal "
		."(entity, fk_bank, layer, status, fk_facture, fk_facture_fourn, date_creation) VALUES "
		."(".((int) $conf->entity).", ".((int) $fkBank).", 'step0', '".$db->escape($status)."', "
		.$fkFac.", ".$fkFacFour.", '".$db->idate(dol_now())."')";
	if (!$db->query($sql)) {
		throw new Exception('proposal insert failed: '.$db->lasterror());
	}
	$id = (int) $db->last_insert_id($p.'ledgerpilot_proposal');
	$CREATED_PROPOSALS[] = $id;
	return $id;
}

/** Build + create() a payment for the invoice (amount in company currency). create() never touches bank. */
function make_payment(User $user, PaymentFlow $flow, $invoiceId, $amount)
{
	global $db, $CREATED_PAYMENTS;

	/** @var Paiement|PaiementFourn $paiement */
	$paiement = new ($flow->paymentClass)($db);
	$paiement->datepaye     = dol_now();
	$paiement->paiementid   = PAYMENT_MODE_ID;
	$paiement->paiementcode = PAYMENT_MODE_CODE;
	$paiement->num_payment  = '';
	$paiement->amounts      = array($invoiceId => (float) $amount);

	if ($flow->paymentClass === 'PaiementFourn') {
		$paiement->fk_account         = TEST_ACCOUNT_ID;
		$paiement->multicurrency_code = array($invoiceId => 'CHF');
		$paiement->multicurrency_tx   = array($invoiceId => 1);
	}

	$pid = $paiement->create($user);
	if ($pid <= 0) {
		throw new Exception($flow->paymentClass.'::create failed: '.$paiement->error.' | '.implode(' | ', (array) $paiement->errors));
	}
	$CREATED_PAYMENTS[] = array('class' => $flow->paymentClass, 'id' => (int) $pid);
	return $paiement;
}

// ---------------------------------------------------------------------------
// Adapters — the spike now DRIVES the production services (no duplicated transaction skeleton). $flow is
// kept in the signature only so the existing phase call sites stay unchanged; each service derives the
// flow, amount and direction from the proposal + bank line itself.
//
// The fault injector is a callable thrown at a named point inside the service's tx to prove the OUTER
// rollback (an injected throw and a native rc<=0 take the same rollback path to the same DB state).
// ---------------------------------------------------------------------------
function commit_prototype(User $user, $proposalId, PaymentFlow $flow, ?callable $faultInjector = null)
{
	global $db;
	return PaymentCommitService::commit($db, $user, (int) $proposalId, $faultInjector);
}

function reverse_prototype(User $user, $proposalId, PaymentFlow $flow, ?callable $faultInjector = null)
{
	global $db;
	return PaymentReversalService::reverse($db, $user, (int) $proposalId, $faultInjector);
}

// ---------------------------------------------------------------------------
// Teardown: remove exactly what the phase created; reopen both fixture invoices.
// ---------------------------------------------------------------------------
function teardown()
{
	global $db, $user, $CREATED_PAYMENTS, $CREATED_BANK_LINES, $CREATED_PROPOSALS;
	$p = MAIN_DB_PREFIX;

	foreach ($CREATED_PAYMENTS as $pay) {
		$pfTable = ($pay['class'] === 'PaiementFourn') ? 'paiementfourn_facturefourn' : 'paiement_facture';
		$pTable  = ($pay['class'] === 'PaiementFourn') ? 'paiementfourn' : 'paiement';
		$fkCol   = ($pay['class'] === 'PaiementFourn') ? 'fk_paiementfourn' : 'fk_paiement';
		$db->query("DELETE FROM {$p}{$pfTable} WHERE {$fkCol} = ".((int) $pay['id']));
		$db->query("DELETE FROM {$p}{$pTable} WHERE rowid = ".((int) $pay['id']));
	}
	foreach ($CREATED_BANK_LINES as $bid) {
		$db->query("DELETE FROM {$p}bank_url WHERE fk_bank = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank_class WHERE lineid = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank WHERE rowid = ".((int) $bid));
	}
	foreach ($CREATED_PROPOSALS as $pid) {
		$db->query("DELETE FROM {$p}ledgerpilot_proposal WHERE rowid = ".((int) $pid));
	}

	// Delete any payments posted against the fixture invoices. The service creates payments INTERNALLY
	// (not via CREATED_PAYMENTS), and setUnpaid() alone would leave them as phantom payments that keep
	// getRemainToPay()=0 — poisoning later phases. Baseline has none, so every payment on 240/5 is ours.
	$payMaps = array(
		array('link' => 'paiement_facture',              'pay' => 'paiement',      'fk' => 'fk_paiement',      'inv' => 'fk_facture',      'id' => 240),
		array('link' => 'paiementfourn_facturefourn',    'pay' => 'paiementfourn', 'fk' => 'fk_paiementfourn', 'inv' => 'fk_facturefourn', 'id' => 5),
	);
	foreach ($payMaps as $m) {
		$r = $db->query("SELECT DISTINCT {$m['fk']} AS pid FROM {$p}{$m['link']} WHERE {$m['inv']} = ".$m['id']);
		while ($r && ($o = $db->fetch_object($r))) {
			$db->query("DELETE FROM {$p}{$m['link']} WHERE {$m['fk']} = ".((int) $o->pid));
			$db->query("DELETE FROM {$p}{$m['pay']} WHERE rowid = ".((int) $o->pid));
		}
	}

	// Reopen both fixture invoices via the native setUnpaid() (idempotent if already open).
	foreach (array('Facture' => 240, 'FactureFournisseur' => 5) as $cls => $id) {
		$inv = new $cls($db);
		if ($inv->fetch($id) > 0 && ((int) $inv->paye !== 0 || (int) $inv->statut > 1)) {
			$inv->setUnpaid($user);
		}
	}

	$CREATED_PAYMENTS  = array();
	$CREATED_BANK_LINES = array();
	$CREATED_PROPOSALS = array();
}

/** Refuse to run if a fixture invoice is not at its known (paye=0, fk_statut=1) baseline. */
function assert_fixtures_or_abort()
{
	$p = MAIN_DB_PREFIX;
	foreach (array('facture' => 240, 'facture_fourn' => 5) as $tbl => $id) {
		$paye   = (int) db_scalar("SELECT paye n FROM {$p}{$tbl} WHERE rowid=".$id);
		$statut = (int) db_scalar("SELECT fk_statut n FROM {$p}{$tbl} WHERE rowid=".$id);
		if ($paye !== 0 || $statut !== 1) {
			echo "ABORT: fixture $tbl #$id not at baseline (paye=0,fk_statut=1) — found paye=$paye,fk_statut=$statut.\n";
			exit(2);
		}
	}
}

// ---------------------------------------------------------------------------
// PHASES
// ---------------------------------------------------------------------------

/**
 * Prove the DoliDB depth-counter footgun directly (§2): a NESTED rollback only DECREMENTS the counter (no
 * real ROLLBACK is issued); the real ROLLBACK happens only at the outermost level. This is WHY the
 * services must never commit after a failed nested call. The atomicity phases inject via a throw (and
 * add_url_line is a plain INSERT with no nested tx), so this is the only phase that exercises the
 * decrement-only behaviour — a flow-independent, DB-only check on a throwaway bank_url row.
 */
function phase_depth_counter()
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$marker = 'LP-DEPTH-'.uniqid();

	$db->begin();                          // depth 0 -> 1 (real BEGIN)
	$db->query("INSERT INTO {$p}bank_url (fk_bank, url_id, url, label, type) VALUES (0, 0, '', '".$db->escape($marker)."', 'LPDEPTH')");
	$id = (int) $db->last_insert_id("{$p}bank_url");
	$db->begin();                          // depth 1 -> 2 (NO real BEGIN)
	$db->rollback();                       // depth 2 -> 1 (DECREMENT ONLY — no real ROLLBACK)

	$present = (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank_url WHERE rowid=".$id);
	check('§2 nested rollback only decrements (row still present)', $present === 1, "present=$present");

	$db->rollback();                       // depth 1 -> 0 (real ROLLBACK)
	$gone = (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank_url WHERE rowid=".$id);
	check('§2 outer rollback really rolls back (row gone)', $gone === 0, "gone=".($gone === 0 ? 'yes' : 'no'));
}

/**
 * Prove the isolation contract BEHAVIOURALLY, not via @@tx_isolation. Finding: next-transaction
 * `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` (the production-correct scope — it auto-reverts after
 * one tx, so it cannot leak across Dolibarr's pconnect like SESSION would) does NOT change the session
 * variable @@tx_isolation, and information_schema.innodb_trx needs the PROCESS privilege the dolibarr
 * user lacks. So we prove it the only foolproof way: a second connection commits a change mid-tx, and we
 * observe whether the guarded tx sees it.
 *   - With READ COMMITTED: the guarded tx sees the concurrent commit (each statement reads the latest) →
 *     the §7 balance recheck would catch a competitor. This is what the commit prototype relies on.
 *   - WITHOUT the SET (default REPEATABLE READ): a snapshot is pinned at the first plain SELECT, so the
 *     concurrent commit is invisible — which also proves the SET genuinely takes effect AND that the next
 *     tx reverted to REPEATABLE READ (no SESSION leak).
 */
function phase_isolation(User $user)
{
	global $db;
	$p = MAIN_DB_PREFIX;

	$line = setup_imported_line($user, 100.0, PaymentFlow::sales());

	// Second connection (autocommit) to commit a change while our tx is open.
	mysqli_report(MYSQLI_REPORT_OFF);
	$b = @new mysqli($GLOBALS['dolibarr_main_db_host'], $GLOBALS['dolibarr_main_db_user'], $GLOBALS['dolibarr_main_db_pass'], $GLOBALS['dolibarr_main_db_name'], (int) $GLOBALS['dolibarr_main_db_port']);
	if ($b->connect_errno) {
		check('second connection opened', false, $b->connect_error);
		return;
	}

	// --- READ COMMITTED: the guarded tx must SEE a concurrent commit. ---
	$db->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
	$db->begin();
	$v1 = (float) price2num(db_scalar("SELECT amount n FROM {$p}bank WHERE rowid=".$line), 'MT');
	$b->query("UPDATE {$p}bank SET amount = ".($v1 + 777)." WHERE rowid = ".$line);
	$v2 = (float) price2num(db_scalar("SELECT amount n FROM {$p}bank WHERE rowid=".$line), 'MT');
	$db->rollback();
	check('READ COMMITTED sees the concurrent commit (recheck works)', abs($v2 - ($v1 + 777)) < 0.005, "v1=$v1 v2=$v2");

	// Reset for the contrast run.
	$b->query("UPDATE {$p}bank SET amount = ".$v1." WHERE rowid = ".$line);

	// --- Default REPEATABLE READ (no SET): the snapshot must HIDE the concurrent commit. ---
	$db->begin();
	$w1 = (float) price2num(db_scalar("SELECT amount n FROM {$p}bank WHERE rowid=".$line), 'MT'); // pins the snapshot
	$b->query("UPDATE {$p}bank SET amount = ".($w1 + 555)." WHERE rowid = ".$line);
	$w2 = (float) price2num(db_scalar("SELECT amount n FROM {$p}bank WHERE rowid=".$line), 'MT');
	$db->rollback();
	check('REPEATABLE READ hides it (SET took effect + auto-reverted, no SESSION leak)', abs($w2 - $w1) < 0.005, "w1=$w1 w2=$w2");

	$b->close();
}

function phase_commit_ok(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$res = commit_prototype($user, $prop, $flow);
	check('result == COMMITTED', $res === R_COMMITTED, "res=$res");

	$deltaPaymentLink = url_count($line, "type='".$flow->bankMode."'");
	$deltaCompanyLink = url_count($line, "type='company'");
	check('payment link on the existing line', $deltaPaymentLink === 1, "n=$deltaPaymentLink");
	check('company link on the existing line', $deltaCompanyLink === 1, "n=$deltaCompanyLink");

	$invoice = new ($flow->invoiceClass)($db);
	$invoice->fetch($fix['invoice_id']);
	check('invoice settled (getRemainToPay==0)', abs((float) $invoice->getRemainToPay()) < CommitDecision::BALANCE_EPSILON);

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal flipped approved->booked', $st === 'booked', "status=$st");
}

function phase_commit_fail(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$paymentsBefore = (int) db_scalar("SELECT COUNT(*) n FROM {$p}".($flow->isPurchase ? 'paiementfourn' : 'paiement'));

	// Inject a failure AFTER link #1 (create() + payment link already done) → the service's catch rolls the
	// whole tx back at the OUTER level. A native rc<=0 on link #2 takes the identical rollback path.
	$injector = function (string $point) {
		if ($point === PaymentCommitService::FAULT_BEFORE_LINK2) {
			throw new Exception('injected commit failure');
		}
	};
	$res = commit_prototype($user, $prop, $flow, $injector);
	check('result == FAILED (injected mid-tx, after create() + link #1)', $res === R_FAILED, "res=$res");

	// Atomicity: the whole tx rolled back at the OUTER level (depth-counter footgun handled).
	$paymentsAfter = (int) db_scalar("SELECT COUNT(*) n FROM {$p}".($flow->isPurchase ? 'paiementfourn' : 'paiement'));
	check('no payment row leaked (create() rolled back)', $paymentsAfter === $paymentsBefore, "before=$paymentsBefore after=$paymentsAfter");
	check('no payment link leaked (link #1 rolled back)', url_count($line, "type='".$flow->bankMode."'") === 0);
	check('no company link leaked', url_count($line, "type='company'") === 0);

	$invoice = new ($flow->invoiceClass)($db);
	$invoice->fetch($fix['invoice_id']);
	check('invoice still open (paye==0)', (int) $invoice->paye === 0);

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal NOT booked — guard rolled back to approved', $st === 'approved', "status=$st");
}

function phase_idempotency(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$res1 = commit_prototype($user, $prop, $flow);
	$res2 = commit_prototype($user, $prop, $flow);
	check('first commit == COMMITTED', $res1 === R_COMMITTED, "res=$res1");
	check('second commit == ALREADY_DONE (guard saw booked)', $res2 === R_ALREADY_DONE, "res=$res2");

	// No second payment was posted.
	check('still exactly one payment link', url_count($line, "type='".$flow->bankMode."'") === 1);
}

function phase_invalid_state(User $user, PaymentFlow $flow)
{
	$fix = flow_fixture($flow);
	$line = setup_imported_line($user, 10.0, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow, 'rejected');

	$res = commit_prototype($user, $prop, $flow);
	check('rejected proposal == INVALID_STATE (not ALREADY_DONE)', $res === R_INVALID_STATE, "res=$res");
}

function phase_backstop(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	// Pre-existing payment% link (as if posted natively or by a prior commit): the anti-double-spend guard.
	$acc = new Account($db);
	$acc->fetch(TEST_ACCOUNT_ID);
	$acc->add_url_line($line, 1, DOL_URL_ROOT.$flow->paymentUrlPath, '(preexisting)', $flow->bankMode);

	$prop = setup_proposal($line, $fix['invoice_id'], $flow);
	$res = commit_prototype($user, $prop, $flow);
	check('line already posted == ABORTED_SETTLED (per-line backstop)', $res === R_ABORTED_SETTLED, "res=$res");

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal NOT booked (guard rolled back)', $st === 'approved', "status=$st");
}

function phase_sign_guard(User $user, PaymentFlow $flow)
{
	$fix = flow_fixture($flow);
	// Build a line with the WRONG sign for this flow (sales on a debit, supplier on a credit).
	$line = setup_imported_line($user, 50.0, $flow, -$flow->bankSign);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$res = commit_prototype($user, $prop, $flow);
	check('sign mismatch == FAILED (D-C guard)', $res === R_FAILED, "res=$res");
}

/**
 * COV: exercise the CommitDecision::ABORT_OVERPAY verdict path INTEGRATIVELY (a line whose amount exceeds
 * the invoice balance) → the prototype maps it to R_ABORTED_OVERPAY and rolls back. Distinct from the
 * backstop, which aborts before decide() is reached.
 */
function phase_overpay(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$total = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $total * 2, $flow);   // amount = 2× balance → overpay
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$res = commit_prototype($user, $prop, $flow);
	check('amount > remain == ABORTED_OVERPAY (decide verdict path)', $res === R_ABORTED_OVERPAY, "res=$res");

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal NOT booked (rolled back)', $st === 'approved', "status=$st");
}

/**
 * COV: exercise the CommitDecision::ABORT_SETTLED verdict path INTEGRATIVELY. Settle the invoice fully
 * via one committed proposal (line A), then commit a SECOND line B against the same invoice — B carries
 * no payment link of its own, so the per-line backstop passes and the abort comes from decide() reading a
 * zero balance (remain <= ε), proving the decide() → R_ABORTED_SETTLED mapping (not the backstop).
 */
function phase_settled(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$total = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	// Line A fully settles the invoice.
	$lineA = setup_imported_line($user, $total, $flow);
	$propA = setup_proposal($lineA, $fix['invoice_id'], $flow);
	commit_prototype($user, $propA, $flow);

	// Line B against the now-settled invoice (no payment link on B → backstop passes → decide() aborts).
	$lineB = setup_imported_line($user, $total, $flow);
	$propB = setup_proposal($lineB, $fix['invoice_id'], $flow);
	$res = commit_prototype($user, $propB, $flow);
	check('settled invoice == ABORTED_SETTLED (decide verdict path, not backstop)', $res === R_ABORTED_SETTLED, "res=$res");

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$propB);
	check('second proposal NOT booked (rolled back)', $st === 'approved', "status=$st");
}

function phase_reverse_ok(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);
	commit_prototype($user, $prop, $flow);

	$res = reverse_prototype($user, $prop, $flow);
	check('result == REVERSED', $res === R_REVERSED, "res=$res");

	check('R1 imported line still exists', (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank WHERE rowid=".$line) === 1);
	check('R3 no orphan payment link', url_count($line, "type='".$flow->bankMode."'") === 0);
	check('R3 no orphan company link', url_count($line, "type='company'") === 0);

	$invoice = new ($flow->invoiceClass)($db);
	$invoice->fetch($fix['invoice_id']);
	check('R4 invoice reopened + fully owed', abs((float) $invoice->getRemainToPay() - $amount) < CommitDecision::BALANCE_EPSILON, "remain=".$invoice->getRemainToPay());

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal flipped booked->reversed', $st === 'reversed', "status=$st");
}

function phase_reverse_fail(User $user, PaymentFlow $flow)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);
	commit_prototype($user, $prop, $flow);
	$paymentId = (int) db_scalar("SELECT url_id n FROM {$p}bank_url WHERE fk_bank=".$line." AND type='".$flow->bankMode."'");

	$injector = function (string $point) {
		if ($point === PaymentReversalService::FAULT_BEFORE_CLEANUP) {
			throw new Exception('injected mid-reversal failure');
		}
	};
	$res = reverse_prototype($user, $prop, $flow, $injector);
	check('result == FAILED (injected between delete and cleanup)', $res === R_FAILED, "res=$res");

	// M2: single outer tx => fully untouched, NEVER an orphan state.
	$pTable = $flow->isPurchase ? 'paiementfourn' : 'paiement';
	check('payment survives (delete rolled back)', (int) db_scalar("SELECT COUNT(*) n FROM {$p}{$pTable} WHERE rowid=".$paymentId) === 1);
	check('payment link survives (no orphan window)', url_count($line, "type='".$flow->bankMode."'") === 1);
	check('company link survives', url_count($line, "type='company'") === 1);

	$invoice = new ($flow->invoiceClass)($db);
	$invoice->fetch($fix['invoice_id']);
	check('invoice still closed (setUnpaid rolled back)', (int) $invoice->paye === 1, "paye=".$invoice->paye);

	$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".$prop);
	check('proposal still booked (guard rolled back)', $st === 'booked', "status=$st");
}

function phase_drift(User $user, PaymentFlow $flow)
{
	global $db, $CREATED_BANK_LINES, $CREATED_PAYMENTS;
	$p = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);
	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	// Native "naive" path: addPaymentToBank() creates a NEW line + links => the drift oracle.
	$paiement = make_payment($user, $flow, $fix['invoice_id'], $amount);
	$newBankId = $paiement->addPaymentToBank($user, $flow->bankMode, '(drift)', TEST_ACCOUNT_ID, '', '');
	if ($newBankId <= 0) {
		check('addPaymentToBank succeeded', false, $paiement->error);
		return;
	}
	$CREATED_BANK_LINES[] = (int) $newBankId;

	$payType = db_scalar("SELECT type n FROM {$p}bank_url WHERE fk_bank=".((int) $newBankId)." AND type LIKE 'payment%'");
	$payUrl  = db_scalar("SELECT url  n FROM {$p}bank_url WHERE fk_bank=".((int) $newBankId)." AND type LIKE 'payment%'");
	$cmpType = db_scalar("SELECT type n FROM {$p}bank_url WHERE fk_bank=".((int) $newBankId)." AND type='company'");
	$cmpUrl  = db_scalar("SELECT url  n FROM {$p}bank_url WHERE fk_bank=".((int) $newBankId)." AND type='company'");

	check('payment link type == PaymentFlow->bankMode', $payType === $flow->bankMode, "native=$payType");
	check('payment link url == PaymentFlow->paymentUrlPath', $payUrl === DOL_URL_ROOT.$flow->paymentUrlPath, "native=$payUrl");
	check('company link type == PaymentFlow::COMPANY_LINK_TYPE', $cmpType === PaymentFlow::COMPANY_LINK_TYPE, "native=$cmpType");
	check('company link url == PaymentFlow->companyUrlPath', $cmpUrl === DOL_URL_ROOT.$flow->companyUrlPath, "native=$cmpUrl");
}

// ---------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------
$phaseArg = 'all';
$typeArg  = 'both';
foreach ($argv as $a) {
	if (strpos($a, '--phase=') === 0) {
		$phaseArg = substr($a, 8);
	}
	if (strpos($a, '--type=') === 0) {
		$typeArg = substr($a, 7);
	}
}

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

$ALL_PHASES = array(
	'commit-ok'     => 'phase_commit_ok',
	'commit-fail'   => 'phase_commit_fail',
	'idempotency'   => 'phase_idempotency',
	'invalid-state' => 'phase_invalid_state',
	'backstop'      => 'phase_backstop',
	'overpay'       => 'phase_overpay',
	'settled'       => 'phase_settled',
	'sign-guard'    => 'phase_sign_guard',
	'reverse-ok'    => 'phase_reverse_ok',
	'reverse-fail'  => 'phase_reverse_fail',
	'drift'         => 'phase_drift',
);

$flows = array();
if ($typeArg === 'both' || $typeArg === 'sales') {
	$flows['sales'] = PaymentFlow::sales();
}
if ($typeArg === 'both' || $typeArg === 'supplier') {
	$flows['supplier'] = PaymentFlow::purchase();
}

assert_fixtures_or_abort();

echo "=== SPIKE commit/reversal atomicity  phase=$phaseArg  type=$typeArg ===\n";

// The depth-counter and isolation phases are flow-independent — run each once when requested.
if ($phaseArg === 'all' || $phaseArg === 'depth-counter') {
	echo "  [phase depth-counter]\n";
	try {
		phase_depth_counter();
	} catch (Exception $e) {
		check('depth-counter phase no exception', false, $e->getMessage());
	}
}
if ($phaseArg === 'all' || $phaseArg === 'isolation') {
	echo "  [phase isolation]\n";
	try {
		phase_isolation($user);
	} catch (Exception $e) {
		check('isolation phase no exception', false, $e->getMessage());
	} finally {
		teardown();
	}
}

foreach ($flows as $flowName => $flow) {
	foreach ($ALL_PHASES as $phaseName => $fn) {
		if ($phaseArg !== 'all' && $phaseArg !== $phaseName) {
			continue;
		}
		echo "  [phase $phaseName / $flowName]\n";
		try {
			$fn($user, $flow);
		} catch (Exception $e) {
			check("$phaseName/$flowName no exception", false, $e->getMessage());
		} finally {
			teardown();
		}
	}
}

$failed = 0;
foreach ($ASSERTIONS as $a) {
	if (!$a['ok']) {
		$failed++;
	}
}
echo "=== result: ".count($ASSERTIONS)." assertions, $failed failed ===\n";
exit($failed > 0 ? 3 : 0);
