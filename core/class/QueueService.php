<?php

namespace LedgerPilot;

/**
 * The DB-coupled half of the queue worker (spec §5/§7): enqueue / claim / reap / release on
 * llx_ledgerpilot_queue. The pure verdict (retry vs dead-letter) lives in RequeueDecision; the live-DB
 * concurrency contract is proven by docs/spikes/queue_claim_reap_check.php, which drives THIS class (no
 * duplicated SQL skeleton — the spike is its live integration test, like commit_reversal_atomicity_check
 * drives PaymentCommitService). Split: pure decision here is RequeueDecision, DB-coupled claim is spike-
 * verified, mirroring IbanAccountLookup (pure resolve + spike-verified lookup).
 *
 * Concurrency model (§7): every operation is a single statement in AUTOCOMMIT — NOT inside an outer
 * transaction. A claim's `UPDATE ... WHERE status='pending' ORDER BY rowid LIMIT n` takes InnoDB
 * row-locks on exactly the rows it flips and commits immediately, so two concurrent workers serialize on
 * those locks: the loser re-evaluates `status='pending'` (now false for the taken rows) and never
 * double-claims. This is a different problem from the §6 commit's READ COMMITTED block (a long tx with a
 * balance recheck); the queue needs no isolation gymnastics because each claim is its own committed
 * statement. ORDER BY rowid makes the claim FIFO and gives a deterministic lock order, so two concurrent
 * claims — or two concurrent reaps — acquire their row locks in the same order and cannot deadlock among
 * themselves; a claim and a reap never contend at all, since they target disjoint statuses (pending vs
 * leased). MariaDB's UPDATE..LIMIT is otherwise unordered.
 *
 * entity is passed explicitly and filters every query: unlike the keystone side-table (read only via JOIN
 * from llx_bank), the queue is queried standalone, so a worker in entity X must not touch entity Y's rows.
 */
final class QueueService
{
	/** Default lease lifetime before a claimed row is considered stuck and reapable (llx_const override). */
	public const DEFAULT_LEASE_TIMEOUT_SECONDS = 600;

	/** Default claim batch size per worker run (llx_const override). */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Default grace (minutes) before a line WITHOUT a keystone line_ref row is swept anyway. The keystone
	 * writes line_ref best-effort AFTER the import commit, so a just-imported line may not have its
	 * structured ref yet; enqueuing it immediately would tombstone it (UNIQUE(fk_bank)) and lock it out of
	 * Step 0 forever. The grace lets the ref land first; once it expires we enqueue regardless (a line
	 * whose keystone write failed entirely still has to be processed — it just goes to L1/L2/manual).
	 */
	public const DEFAULT_ENQUEUE_GRACE_MINUTES = 10;

	/**
	 * Enqueue one bank line, idempotently. INSERT IGNORE under UNIQUE(fk_bank) makes a line enqueued at
	 * most once: a duplicate (incl. a done/dead tombstone) is silently skipped, so a processed line is
	 * never re-categorized.
	 *
	 * @return bool true if a new row was inserted, false if it already existed (ignored).
	 */
	public static function enqueue(\DoliDB $db, int $entity, int $fkBank): bool
	{
		$prefix = MAIN_DB_PREFIX;
		$res = $db->query(
			'INSERT IGNORE INTO '.$prefix.'ledgerpilot_queue (entity, fk_bank, status, attempts, date_creation)'
			." VALUES (".$entity.', '.$fkBank.", '".$db->escape(QueueStatus::PENDING)."', 0, '".$db->idate(dol_now())."')"
		);
		if (!$res) {
			dol_syslog('LedgerPilot QueueService::enqueue failed for fk_bank '.$fkBank.': '.$db->lasterror(), LOG_ERR);

			return false;
		}

		return (int) $db->affected_rows($res) === 1;
	}

	/**
	 * Atomically claim up to $batchSize pending rows for $entity, FIFO. A single UPDATE flips them to
	 * leased with a fresh lease token + worker id + claimed_at, and increments attempts (at CLAIM time, so
	 * a worker that crashes mid-processing still burns an attempt — that bounds a poison row). The follow-
	 * up SELECT by the unique token returns exactly this worker's rows.
	 *
	 * @param  string $workerId  Identifies the holder of the lease (e.g. host:pid).
	 * @return array<int, array{rowid:int, fk_bank:int, attempts:int}> Claimed rows, FIFO; [] if none/error.
	 */
	public static function claim(\DoliDB $db, int $entity, string $workerId, int $batchSize): array
	{
		$prefix = MAIN_DB_PREFIX;
		$token  = bin2hex(random_bytes(16)); // 32 hex chars, fits lease_token varchar(40)

		$claimed = $db->query(
			'UPDATE '.$prefix.'ledgerpilot_queue'
			." SET status = '".$db->escape(QueueStatus::LEASED)."', lease_token = '".$db->escape($token)."',"
			." worker_id = '".$db->escape($workerId)."', claimed_at = '".$db->idate(dol_now())."', attempts = attempts + 1"
			." WHERE entity = ".$entity." AND status = '".$db->escape(QueueStatus::PENDING)."'"
			.' ORDER BY rowid LIMIT '.max(0, $batchSize)
		);
		if (!$claimed) {
			dol_syslog('LedgerPilot QueueService::claim UPDATE failed: '.$db->lasterror(), LOG_ERR);

			return array();
		}

		$rows = array();
		$sel = $db->query(
			'SELECT rowid, fk_bank, attempts FROM '.$prefix.'ledgerpilot_queue'
			." WHERE lease_token = '".$db->escape($token)."' ORDER BY rowid"
		);
		while ($sel && ($o = $db->fetch_object($sel))) {
			$rows[] = array('rowid' => (int) $o->rowid, 'fk_bank' => (int) $o->fk_bank, 'attempts' => (int) $o->attempts);
		}

		return $rows;
	}

	/**
	 * Reap stale leases for $entity: a row leased longer than $leaseTimeoutSeconds is either dead-lettered
	 * (attempts at the cutoff) or returned to pending for another worker. The dead-letter UPDATE runs
	 * FIRST so the pending-return UPDATE (still WHERE status='leased') cannot re-touch a just-deadded row;
	 * both order by rowid (deterministic lock order vs the claim). Dead rows keep their lease columns as
	 * forensics; requeued rows clear them. The cutoff matches RequeueDecision (attempts >= maxAttempts).
	 *
	 * @return array{dead:int, requeued:int} How many rows moved to dead / back to pending.
	 */
	public static function reap(\DoliDB $db, int $entity, int $leaseTimeoutSeconds, int $maxAttempts): array
	{
		$prefix = MAIN_DB_PREFIX;
		$stale  = "claimed_at < (NOW() - INTERVAL ".max(0, $leaseTimeoutSeconds)." SECOND)";
		$leased = "entity = ".$entity." AND status = '".$db->escape(QueueStatus::LEASED)."' AND ".$stale;

		$deadRes = $db->query(
			'UPDATE '.$prefix.'ledgerpilot_queue'
			." SET status = '".$db->escape(QueueStatus::DEAD)."'"
			.' WHERE '.$leased.' AND attempts >= '.$maxAttempts.' ORDER BY rowid'
		);
		$dead = $deadRes ? (int) $db->affected_rows($deadRes) : 0;

		$reqRes = $db->query(
			'UPDATE '.$prefix.'ledgerpilot_queue'
			." SET status = '".$db->escape(QueueStatus::PENDING)."', lease_token = NULL, worker_id = NULL, claimed_at = NULL"
			.' WHERE '.$leased.' ORDER BY rowid'
		);
		$requeued = $reqRes ? (int) $db->affected_rows($reqRes) : 0;

		if (!$deadRes || !$reqRes) {
			dol_syslog('LedgerPilot QueueService::reap UPDATE failed: '.$db->lasterror(), LOG_ERR);
		}

		return array('dead' => $dead, 'requeued' => $requeued);
	}

	/**
	 * Release ONE row after the engine failed (or skipped) it: route it to pending (retry) or dead
	 * (cutoff) via the SAME pure rule the reap mirrors, so the two paths cannot diverge. A requeued row
	 * clears its lease; a dead row keeps it as forensics — identical to reap().
	 *
	 * @return string The new QueueStatus (PENDING or DEAD).
	 */
	public static function release(\DoliDB $db, int $rowid, int $attempts, int $maxAttempts): string
	{
		$prefix = MAIN_DB_PREFIX;
		$status = RequeueDecision::decide($attempts, $maxAttempts);

		if ($status === QueueStatus::DEAD) {
			$db->query(
				'UPDATE '.$prefix."ledgerpilot_queue SET status = '".$db->escape(QueueStatus::DEAD)."' WHERE rowid = ".$rowid
			);
		} else {
			$db->query(
				'UPDATE '.$prefix.'ledgerpilot_queue'
				." SET status = '".$db->escape(QueueStatus::PENDING)."', lease_token = NULL, worker_id = NULL, claimed_at = NULL"
				.' WHERE rowid = '.$rowid
			);
		}

		return $status;
	}

	/**
	 * Sweep new bank lines into the queue — the enqueue source. A line is enqueued exactly once (INSERT
	 * IGNORE under UNIQUE(fk_bank) + the anti-join below excludes anything already enqueued, incl. a
	 * done/dead tombstone). Conditions (spec §4, finding B):
	 *   - entity scope comes from llx_bank_account (llx_bank has no entity column), so a worker only
	 *     enqueues its own company's lines.
	 *   - datec >= $enqueueFromDate: a floor so first activation does not backfill the whole bank history.
	 *   - line_ref present OR datec older than the grace window: the keystone writes line_ref AFTER the
	 *     import commit, so a fresh line may lack its structured ref; the grace lets it land before we
	 *     enqueue (else the line is tombstoned out of Step 0). A ref-less line past the grace is enqueued
	 *     regardless (it still needs processing — it just falls to L1/L2/manual).
	 *   - not already settled: skip lines that already carry a payment%-family bank_url link.
	 * The keystone side-table is read by joining FROM llx_bank (the §4 read contract), never the reverse.
	 *
	 * @param  string $enqueueFromDate 'YYYY-MM-DD HH:MM:SS' floor (LEDGERPILOT_ENQUEUE_FROM_DATE).
	 * @param  int    $graceMinutes    Grace before a ref-less line is swept (DEFAULT_ENQUEUE_GRACE_MINUTES).
	 * @return int                     How many lines were newly enqueued.
	 */
	public static function sweep(\DoliDB $db, int $entity, string $enqueueFromDate, int $graceMinutes): int
	{
		$prefix = MAIN_DB_PREFIX;

		$res = $db->query(
			'INSERT IGNORE INTO '.$prefix.'ledgerpilot_queue (entity, fk_bank, status, attempts, date_creation)'
			." SELECT ba.entity, b.rowid, '".$db->escape(QueueStatus::PENDING)."', 0, '".$db->idate(dol_now())."'"
			.' FROM '.$prefix.'bank b'
			.' INNER JOIN '.$prefix.'bank_account ba ON b.fk_account = ba.rowid'
			.' LEFT JOIN '.$prefix.'ledgerpilot_queue q ON q.fk_bank = b.rowid'
			.' LEFT JOIN '.$prefix.'bankimport_line_ref r ON r.fk_bank = b.rowid'
			.' WHERE ba.entity = '.$entity
			.' AND q.fk_bank IS NULL'
			." AND b.datec >= '".$db->escape($enqueueFromDate)."'"
			.' AND (r.fk_bank IS NOT NULL OR b.datec < (NOW() - INTERVAL '.max(0, $graceMinutes).' MINUTE))'
			.' AND NOT EXISTS (SELECT 1 FROM '.$prefix.'bank_url u WHERE u.fk_bank = b.rowid'
			." AND u.type LIKE 'payment%')"
		);
		if (!$res) {
			dol_syslog('LedgerPilot QueueService::sweep failed: '.$db->lasterror(), LOG_ERR);

			return 0;
		}

		return (int) $db->affected_rows($res);
	}
}
