-- ============================================================================
-- Ephemeral review proposals (spec §5): one per categorization suggestion the
-- accountant will accept / correct / reject on the Dashboard. Deletable -- on a
-- terminal action the row is copied into the append-only decision_log and then
-- removed.
--
-- Dual mode, discriminated by layer:
--   - step0 (invoice): fk_facture / fk_facture_fourn set, proposed_account NULL.
--   - l1 / l2 (account): proposed_account set, fk_facture* NULL.
-- Do NOT read proposed_account for a step0 row. (Enforced in PHP, not a CHECK, to
-- keep the DDL portable across the versions the loader targets.)
--
-- candidate_set holds the engine's shortlist + per-candidate scores AT SUGGESTION
-- TIME (same mediumtext JSON-encoded shape as decision_log). It MUST live here:
-- the accountant acts later, when the engine's shortlist is gone, and the row is
-- deleted right after the log is written -- so without it offline-eval loses the
-- "was the correct answer in top-K?" signal (the whole point of decision_log),
-- for rejects too.
--
-- entity is explicit (the Dashboard queries this table standalone). The llx_
-- prefix is rewritten by the loader on (re)activation.
-- ============================================================================

CREATE TABLE llx_ledgerpilot_proposal(
	rowid				integer AUTO_INCREMENT PRIMARY KEY,
	entity				integer DEFAULT 1 NOT NULL,				-- multi-company scope (getEntity())
	fk_bank				integer NOT NULL,						-- llx_bank.rowid this proposal is for
	layer				varchar(16) NOT NULL,					-- step0|l1|l2|clearing (mode discriminator; ProposalLayer)
	status				varchar(16) DEFAULT 'pending' NOT NULL,	-- pending|approved|rejected|booked|reversed|exception (ProposalStatus)
	proposed_account	varchar(32),							-- account track: llx_accounting_account.account_number; NULL for step0
	fk_facture			integer,								-- invoice track (sales); NULL otherwise
	fk_facture_fourn	integer,								-- invoice track (purchase); NULL otherwise
	score				double,									-- confidence / similarity of the suggestion
	candidate_set		mediumtext,								-- shortlist + per-candidate scores at suggestion time (-> decision_log)
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
