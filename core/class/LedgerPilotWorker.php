<?php

namespace LedgerPilot;

/**
 * The queue worker (spec §5/§7): one cron tick reaps stale leases, claims a batch of pending bank lines,
 * runs the engine on each, and writes a pending proposal (or records a skip / manual fall-through). It is
 * the producer side — the Dashboard later approves a proposal and PaymentCommitService posts it.
 *
 * Orchestration only: the per-line decision is delegated to an injected $categorizer (the engine facade
 * = a seam, so the worker is spike-testable with a fake that fabricates verdicts — and the real cascade
 * wiring lives elsewhere). The pure verdict→row mapping is ProposalPlan; the queue mechanics are
 * QueueService; the dead-letter cutoff is RequeueDecision. This class adds only the glue + the per-line
 * transaction.
 *
 * Per-line atomicity (the finding #4 invariant): the proposal INSERT and the queue→done UPDATE run in ONE
 * small transaction. So a worker that crashes mid-line either committed BOTH (line done, proposal exists)
 * or NEITHER (line still leased → reaped → reprocessed cleanly, with no orphan proposal). That is why the
 * worker needs no "delete a half-written proposal before reprocessing" step — the invariant makes one
 * unreachable. A failed line (the engine threw, or the tx failed) is released via QueueService::release →
 * pending (retry) or dead (cutoff).
 *
 * entity-scoped throughout (§8): the cron runs per entity; claim / reap / proposals all carry it.
 */
final class LedgerPilotWorker
{
	/** Per-line outcomes, tallied into the run summary. */
	public const OUTCOME_PROPOSED = 'proposed';
	public const OUTCOME_SKIPPED  = 'skipped';
	public const OUTCOME_MANUAL   = 'manual';
	public const OUTCOME_REQUEUED = 'requeued'; // engine/tx failed → back to pending for another try
	public const OUTCOME_DEAD     = 'dead';     // engine/tx failed at the attempts cutoff → dead-letter

	/** Test-only fault-injection point, fired inside the per-line tx AFTER the proposal INSERT, BEFORE queue→done. */
	public const FAULT_BEFORE_DONE = 'before-done';

	/**
	 * Run one worker tick: reap → claim → process each claimed line.
	 *
	 * @param  object $categorizer Engine facade: ->preflight(\DoliDB,int $entity) once, then
	 *                             ->categorize(\DoliDB,int $fkBank): array (a ProposalPlan verdict) per line.
	 * @return array<string,int>   Summary counts (reaped_dead, reaped_requeued, claimed, proposed, skipped,
	 *                             manual, requeued, dead).
	 */
	public static function run(\DoliDB $db, int $entity, object $categorizer, int $batchSize, int $leaseTimeoutSeconds, int $maxAttempts): array
	{
		$summary = array(
			'reaped_dead' => 0, 'reaped_requeued' => 0, 'claimed' => 0,
			'proposed' => 0, 'skipped' => 0, 'manual' => 0, 'requeued' => 0, 'dead' => 0,
		);

		$reap = QueueService::reap($db, $entity, $leaseTimeoutSeconds, $maxAttempts);
		$summary['reaped_dead']     = $reap['dead'];
		$summary['reaped_requeued'] = $reap['requeued'];

		$categorizer->preflight($db, $entity);

		$claimed = QueueService::claim($db, $entity, self::workerId(), $batchSize);
		$summary['claimed'] = count($claimed);

		$tally = array(
			self::OUTCOME_PROPOSED => 'proposed', self::OUTCOME_SKIPPED => 'skipped',
			self::OUTCOME_MANUAL => 'manual', self::OUTCOME_REQUEUED => 'requeued', self::OUTCOME_DEAD => 'dead',
		);
		foreach ($claimed as $row) {
			$outcome = self::processLine($db, $entity, $categorizer, $row, $maxAttempts);
			$summary[$tally[$outcome]]++;
		}

		return $summary;
	}

	/**
	 * Process ONE claimed line: categorize → ProposalPlan → write (proposal + queue→done) in one tx, or
	 * record a skip / manual (queue→done, no proposal). On any failure release the row for retry/dead-letter.
	 *
	 * @param  array         $row           A claimed row {rowid, fk_bank, attempts}.
	 * @param  callable|null $faultInjector TEST SEAM ONLY (null in production): called $faultInjector(
	 *                                       self::FAULT_BEFORE_DONE) inside the tx so a spike can throw
	 *                                       mid-transaction and prove the proposal INSERT is rolled back.
	 * @return string                        An OUTCOME_* constant.
	 */
	public static function processLine(\DoliDB $db, int $entity, object $categorizer, array $row, int $maxAttempts, ?callable $faultInjector = null): string
	{
		$fkBank      = (int) $row['fk_bank'];
		$queueRowid  = (int) $row['rowid'];

		try {
			$verdict = $categorizer->categorize($db, $fkBank);
			$plan    = ProposalPlan::fromVerdict($verdict);

			$db->begin();

			if ($plan['kind'] === ProposalPlan::PROPOSE && !self::insertProposal($db, $entity, $fkBank, $plan['row'])) {
				$db->rollback();

				return self::releaseOutcome($db, $row, $maxAttempts);
			}

			if ($faultInjector !== null) {
				$faultInjector(self::FAULT_BEFORE_DONE); // test seam: throw here to prove the proposal INSERT rolls back
			}

			// queue → done. WHERE status='leased' is the idempotency guard: only a row this worker holds
			// is flipped, so a concurrent reap that already requeued it cannot be clobbered.
			$done = $db->query(
				'UPDATE '.MAIN_DB_PREFIX."ledgerpilot_queue SET status = '".$db->escape(QueueStatus::DONE)."'"
				.' WHERE rowid = '.$queueRowid." AND status = '".$db->escape(QueueStatus::LEASED)."'"
			);
			if (!$done) {
				$db->rollback();

				return self::releaseOutcome($db, $row, $maxAttempts);
			}

			$db->commit();

			if ($plan['kind'] === ProposalPlan::PROPOSE) {
				return self::OUTCOME_PROPOSED;
			}

			return $plan['kind'] === ProposalPlan::SKIP ? self::OUTCOME_SKIPPED : self::OUTCOME_MANUAL;
		} catch (\Throwable $e) {
			if ($db->transaction_opened > 0) {
				$db->rollback();
			}
			dol_syslog('LedgerPilot worker: line fk_bank '.$fkBank.' failed: '.$e->getMessage(), LOG_ERR);

			return self::releaseOutcome($db, $row, $maxAttempts);
		}
	}

	/** INSERT a pending proposal from the ProposalPlan row (worker adds entity / fk_bank / status / date). */
	private static function insertProposal(\DoliDB $db, int $entity, int $fkBank, array $rowPlan): bool
	{
		$prefix = MAIN_DB_PREFIX;

		$proposedAccount = $rowPlan['proposed_account'] !== null ? "'".$db->escape((string) $rowPlan['proposed_account'])."'" : 'NULL';
		$fkFacture       = $rowPlan['fk_facture'] !== null ? (int) $rowPlan['fk_facture'] : 'NULL';
		$fkFactureFourn  = $rowPlan['fk_facture_fourn'] !== null ? (int) $rowPlan['fk_facture_fourn'] : 'NULL';
		$score           = $rowPlan['score'] !== null ? (float) $rowPlan['score'] : 'NULL';
		$candidateSet    = $rowPlan['candidate_set'] !== null ? "'".$db->escape((string) $rowPlan['candidate_set'])."'" : 'NULL';

		$res = $db->query(
			'INSERT INTO '.$prefix.'ledgerpilot_proposal'
			.' (entity, fk_bank, layer, status, proposed_account, fk_facture, fk_facture_fourn, score, candidate_set, date_creation)'
			.' VALUES ('.$entity.', '.$fkBank.", '".$db->escape((string) $rowPlan['layer'])."', '".$db->escape(ProposalStatus::PENDING)."', "
			.$proposedAccount.', '.$fkFacture.', '.$fkFactureFourn.', '.$score.', '.$candidateSet.", '".$db->idate(dol_now())."')"
		);
		if (!$res) {
			dol_syslog('LedgerPilot worker: proposal insert failed for fk_bank '.$fkBank.': '.$db->lasterror(), LOG_ERR);

			return false;
		}

		return true;
	}

	/** Release a failed line (pending or dead per RequeueDecision) and map the queue status to an outcome. */
	private static function releaseOutcome(\DoliDB $db, array $row, int $maxAttempts): string
	{
		$status = QueueService::release($db, (int) $row['rowid'], (int) $row['attempts'], $maxAttempts);

		return $status === QueueStatus::DEAD ? self::OUTCOME_DEAD : self::OUTCOME_REQUEUED;
	}

	/** Identifies the lease holder (host:pid), fitting worker_id varchar(64). */
	private static function workerId(): string
	{
		return (string) gethostname().':'.getmypid();
	}
}
