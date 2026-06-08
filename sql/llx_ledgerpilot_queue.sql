-- ============================================================================
-- Work queue: one row per llx_bank line awaiting categorization (spec §5/§7).
--
-- A cron worker claims rows atomically with a locking UPDATE ... ORDER BY rowid
-- LIMIT n. rowid is AUTO_INCREMENT, so ORDER BY rowid is FIFO and deterministic
-- (MariaDB ordering in UPDATE..LIMIT is otherwise undefined). lease_token /
-- worker_id / claimed_at mark the claim so a stuck lease can be reaped; attempts
-- drives the dead-letter cutoff.
--
-- entity is carried explicitly (DEFAULT 1): unlike the keystone side-table (read
-- only via JOIN from llx_bank), the queue is queried standalone, so every query
-- must filter getEntity() or a worker in entity X would claim entity Y's rows
-- (spec §8). entity leads the claim/reap indexes (see .key.sql).
--
-- UNIQUE(fk_bank) is the idempotency guard (spec §5/§7: a line is enqueued once);
-- it needs no entity prefix because fk_bank = llx_bank.rowid is globally unique
-- across entities.
--
-- The llx_ prefix is rewritten to the instance prefix by the table loader on
-- module (re)activation.
-- ============================================================================

CREATE TABLE llx_ledgerpilot_queue(
	rowid			integer AUTO_INCREMENT PRIMARY KEY,			-- FIFO order for the claim's ORDER BY
	entity			integer DEFAULT 1 NOT NULL,					-- multi-company scope (getEntity() on every query)
	fk_bank			integer NOT NULL,							-- llx_bank.rowid to categorize
	status			varchar(16) DEFAULT 'pending' NOT NULL,		-- pending|leased|done|dead (PHP constants)
	lease_token		varchar(40),								-- claim token; NULL while unleased
	worker_id		varchar(64),								-- worker holding the lease
	claimed_at		datetime,									-- when leased (NULL while unleased); drives the reap
	attempts		integer DEFAULT 0 NOT NULL,					-- retry count -> dead-letter past the cutoff
	date_creation	datetime NOT NULL,
	tms				timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
