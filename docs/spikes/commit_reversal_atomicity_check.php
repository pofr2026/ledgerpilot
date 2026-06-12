<?php
/**
 * SPIKE — transaction / concurrency contract of the Step 0 payment commit & reversal (spec §6/§7).
 *
 * DURABLE regression-guard (spec §8): unlike an exploratory spike, this verifies assumptions that must
 * keep holding — the DoliDB depth-counter rollback behaviour (§2), READ COMMITTED scoping, and the exact
 * native bank_url shapes — so it stays in docs/spikes/ and is re-runnable, like candidate_gen_check.php.
 *
 * It is the executable PROTOTYPE of PaymentCommitService / PaymentReversalService (the way bankimport's
 * spike1_commit.php — a separate repo — prototyped commit_ours): it implements the approved transaction
 * skeleton inline and
 * asserts the resulting DB state, so the production services can be written against a proven shape. It
 * consumes the real engine classes CommitDecision (pure verdict) and PaymentFlow (sales/supplier
 * asymmetry) — the same code the services will use.
 *
 * Phases (each self-contained: setup → act → assert DB state → teardown to baseline):
 *   isolation     SET TRANSACTION ... READ COMMITTED before begin(); @@tx_isolation == READ-COMMITTED
 *                 inside the tx, and reverts to REPEATABLE-READ after (per-tx scope, not a SESSION leak).
 *   commit-ok     full commit prototype → COMMITTED; payment + both links on the existing line, invoice
 *                 settled, proposal flipped approved→booked (canonical lock order, guard-update first).
 *   commit-fail   inject a real failure on add_url_line #2 → outer rollback → DB clean (no payment, no
 *                 link, invoice still open) AND proposal NOT booked (still approved): the depth-counter
 *                 footgun handled (§2/§7) — never commit after a failed nested call.
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

// The production engine classes under test (same ones the services will use).
require_once __DIR__.'/../../core/class/CommitDecision.php';
require_once __DIR__.'/../../core/class/PaymentFlow.php';

use LedgerPilot\CommitDecision;
use LedgerPilot\PaymentFlow;

/** @var DoliDB $db */
/** @var Conf $conf */

// ---------------------------------------------------------------------------
// Fixture configuration (mirror of spike1 — no magic numbers inline)
// ---------------------------------------------------------------------------
const TEST_ACCOUNT_ID   = 2;       // PostF-CHF (company currency = CHF, account currency = CHF)
const PAYMENT_MODE_ID   = 2;       // llx_c_paiement VIR
const PAYMENT_MODE_CODE = 'VIR';
const IMPORTED_LABEL    = 'LP-ATOMICITY-IMPORTED';

// CommitResult statuses (the prototype's return contract; the service will mint these as PHP constants).
const R_COMMITTED       = 'committed';
const R_ALREADY_DONE    = 'already_done';
const R_ABORTED_SETTLED = 'aborted_settled';
const R_ABORTED_OVERPAY = 'aborted_overpay';
const R_INVALID_STATE   = 'invalid_state';
const R_FAILED          = 'failed';
const R_REVERSED        = 'reversed';

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
// THE PROTOTYPE — commit a proposal in one outer tx (the §6/§7 contract).
// ---------------------------------------------------------------------------
function commit_prototype(User $user, $proposalId, PaymentFlow $flow, $injectFail = null)
{
	global $db, $conf;
	$p   = MAIN_DB_PREFIX;
	$fix = flow_fixture($flow);

	// Pre-read the proposal's work item (outside the tx; the guard-update below is the first tx statement).
	$prop = $db->query("SELECT fk_bank, fk_facture, fk_facture_fourn FROM {$p}ledgerpilot_proposal WHERE rowid=".((int) $proposalId));
	$prow = $db->fetch_object($prop);
	if (!$prow) {
		return R_INVALID_STATE;
	}
	$fkBank    = (int) $prow->fk_bank;
	$invoiceId = $flow->isPurchase ? (int) $prow->fk_facture_fourn : (int) $prow->fk_facture;

	// D-C: amount + sign come from the existing bank line (reuse, no hardcoded VIR/total_ttc).
	$lineAmount = (float) price2num(db_scalar("SELECT amount n FROM {$p}bank WHERE rowid=".$fkBank), 'MT');
	$amount     = abs($lineAmount);

	// READ COMMITTED for the guarded block, per-tx scope, BEFORE begin() (§7 / D-G).
	$db->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
	$db->begin();

	try {
		// --- Idempotency guard: FIRST statement. proposal(rowid), approved -> booked. ---
		$gres = $db->query("UPDATE {$p}ledgerpilot_proposal SET status='booked' WHERE rowid=".((int) $proposalId)." AND status='approved'");
		if (!$gres) {
			$db->rollback();
			return R_FAILED;
		}
		if ((int) $db->affected_rows($gres) === 0) {
			// 0 rows is ambiguous: already booked (idempotent re-entry) vs not-approved (UI/logic error).
			$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".((int) $proposalId));
			$db->rollback();
			return ($st === 'booked') ? R_ALREADY_DONE : R_INVALID_STATE;
		}

		// --- Canonical lock #2: the bank line (anti-double-spend per line). ---
		if (!$db->query("SELECT rowid FROM {$p}bank WHERE rowid=".$fkBank." FOR UPDATE")) {
			$db->rollback();
			return R_FAILED;
		}

		// Sign guard (D-C): the line's sign must match the flow direction.
		if (($lineAmount <=> 0.0) !== $flow->bankSign) {
			$db->rollback();
			return R_FAILED;
		}

		// Per-line backstop UNDER the line lock: a payment% link means the line is already posted.
		if (url_count($fkBank, "type LIKE 'payment%'") > 0) {
			$db->rollback();
			return R_ABORTED_SETTLED;
		}

		// --- Canonical lock #3: the invoice (overpayment race), then a fresh balance. ---
		if (!$db->query("SELECT rowid FROM {$p}{$fix['inv_table']} WHERE rowid=".$invoiceId." FOR UPDATE")) {
			$db->rollback();
			return R_FAILED;
		}
		$invoice = new ($flow->invoiceClass)($db);
		$invoice->fetch($invoiceId);
		$remain = (float) $invoice->getRemainToPay();

		$verdict = CommitDecision::decide($remain, $amount);
		if ($verdict === CommitDecision::ABORT_SETTLED) {
			$db->rollback();
			return R_ABORTED_SETTLED;
		}
		if ($verdict === CommitDecision::ABORT_OVERPAY) {
			$db->rollback();
			return R_ABORTED_OVERPAY;
		}

		// --- Native posting: create() + both links on the EXISTING line (NOT addPaymentToBank). ---
		$paiement = make_payment($user, $flow, $invoiceId, $amount);

		$acc = new Account($db);
		$acc->fetch(TEST_ACCOUNT_ID);

		// Link #1: payment / payment_supplier.
		$r1 = $acc->add_url_line($fkBank, $paiement->id, DOL_URL_ROOT.$flow->paymentUrlPath, PaymentFlow::PAYMENT_LABEL, $flow->bankMode);
		if ($r1 <= 0) {
			$db->rollback();
			return R_FAILED;
		}

		// Link #2: company. INJECT FAIL HERE — an over-length type (>24) trips STRICT_TRANS_TABLES so the
		// real native add_url_line returns -1 (a genuine "second native call failed", not a simulated one).
		$invoice->fetch_thirdparty();
		$companyType = ($injectFail === 'link2') ? str_repeat('X', 30) : PaymentFlow::COMPANY_LINK_TYPE;
		$r2 = $acc->add_url_line($fkBank, $invoice->thirdparty->id, DOL_URL_ROOT.$flow->companyUrlPath, (string) $invoice->thirdparty->name, $companyType);
		if ($r2 <= 0) {
			$db->rollback();
			return R_FAILED;
		}

		// Settle the invoice when the amount covers the balance.
		if ($verdict === CommitDecision::PROCEED_FULL) {
			if ($invoice->setPaid($user) <= 0) {
				$db->rollback();
				return R_FAILED;
			}
		}

		$db->commit();
		return R_COMMITTED;
	} catch (Exception $e) {
		$db->rollback();
		echo "      (commit_prototype exception: ".$e->getMessage().")\n";
		return R_FAILED;
	}
}

// ---------------------------------------------------------------------------
// THE PROTOTYPE — reverse a booked proposal in one outer tx (the §6 SPIKE #2 order, M2 rigor).
// ---------------------------------------------------------------------------
function reverse_prototype(User $user, $proposalId, PaymentFlow $flow, $injectFail = null)
{
	global $db;
	$p = MAIN_DB_PREFIX;

	$prop = $db->query("SELECT fk_bank, fk_facture, fk_facture_fourn FROM {$p}ledgerpilot_proposal WHERE rowid=".((int) $proposalId));
	$prow = $db->fetch_object($prop);
	if (!$prow) {
		return R_INVALID_STATE;
	}
	$fkBank    = (int) $prow->fk_bank;
	$invoiceId = $flow->isPurchase ? (int) $prow->fk_facture_fourn : (int) $prow->fk_facture;

	// The payment to reverse = the url_id on our payment link (reuse the native trace, like the backstop).
	$paymentId = (int) db_scalar("SELECT url_id n FROM {$p}bank_url WHERE fk_bank=".$fkBank." AND type='".$db->escape($flow->bankMode)."'");

	$db->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
	$db->begin();

	try {
		// Mirror guard: booked -> reversed, FIRST. per-proposal idempotency without "does the payment exist?".
		$gres = $db->query("UPDATE {$p}ledgerpilot_proposal SET status='reversed' WHERE rowid=".((int) $proposalId)." AND status='booked'");
		if (!$gres) {
			$db->rollback();
			return R_FAILED;
		}
		if ((int) $db->affected_rows($gres) === 0) {
			$st = db_scalar("SELECT status n FROM {$p}ledgerpilot_proposal WHERE rowid=".((int) $proposalId));
			$db->rollback();
			return ($st === 'reversed') ? R_ALREADY_DONE : R_INVALID_STATE;
		}

		// setUnpaid() FIRST — native delete() refuses on a closed/paid invoice (§6 SPIKE #2 order).
		$inv = new ($flow->invoiceClass)($db);
		$inv->fetch($invoiceId);
		if ($inv->setUnpaid($user) <= 0) {
			$db->rollback();
			return R_FAILED;
		}

		// delete() our payment (fetched fresh; fk_bank=NULL/0 => the imported line is line-safe).
		$pay = new ($flow->paymentClass)($db);
		$pay->fetch($paymentId);
		if ($pay->delete($user) <= 0) {
			$db->rollback();
			return R_FAILED;
		}

		// INJECT FAIL between delete() and the link cleanup (M2's critical window).
		if ($injectFail === 'before-cleanup') {
			throw new Exception('injected mid-reversal failure');
		}

		// Manually remove our bank_url links — delete() never touches them.
		$del = $db->query("DELETE FROM {$p}bank_url WHERE fk_bank=".$fkBank." AND type IN ('".$db->escape($flow->bankMode)."','company')");
		if (!$del) {
			$db->rollback();
			return R_FAILED;
		}

		$db->commit();
		return R_REVERSED;
	} catch (Exception $e) {
		$db->rollback();
		echo "      (reverse_prototype exception: ".$e->getMessage().")\n";
		return R_FAILED;
	}
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

	// Durable-assumption guard: the injection (an over-length bank_url.type) only raises a real error
	// under STRICT_TRANS_TABLES; without it MariaDB would truncate (warning) and add_url_line would pass.
	// The phase would then FAIL loudly (never a false green), but make the dependency explicit.
	$mode = (string) db_scalar("SELECT @@sql_mode n");
	check('STRICT_TRANS_TABLES active (injection relies on it)', strpos($mode, 'STRICT_TRANS_TABLES') !== false, "sql_mode=$mode");

	$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$fix['inv_table']} WHERE rowid=".$fix['invoice_id']), 'MT');

	$line = setup_imported_line($user, $amount, $flow);
	$prop = setup_proposal($line, $fix['invoice_id'], $flow);

	$paymentsBefore = (int) db_scalar("SELECT COUNT(*) n FROM {$p}".($flow->isPurchase ? 'paiementfourn' : 'paiement'));

	$res = commit_prototype($user, $prop, $flow, 'link2');
	check('result == FAILED (injected on add_url_line #2)', $res === R_FAILED, "res=$res");

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

	$res = reverse_prototype($user, $prop, $flow, 'before-cleanup');
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

// The isolation phase is flow-independent — run it once when requested.
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
