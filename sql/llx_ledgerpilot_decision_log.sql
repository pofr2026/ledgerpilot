-- ============================================================================
-- Append-only decision log (spec §5) -- the source of truth for offline
-- precision/recall. One row per terminal accountant action; knowledge is a
-- re-derivable projection of this.
--
-- APPEND-ONLY: rows are never updated or deleted, so there is no tms column. The
-- log MUST outlive the bank line it references: fk_bank is a SOFT historical
-- reference (no FK, no ON DELETE CASCADE), and any orphan-cleanup that prunes
-- queue / proposals must NOT touch this table (spec §5/§9).
--
-- Invoice / account symmetry (so the step0 invoice track is offline-evaluable
-- too): suggested_ / final_ carry BOTH an account_number AND fk_facture /
-- fk_facture_fourn; the account columns are NULL on the invoice track and the
-- invoice columns are NULL on the account track.
--
-- candidate_set is the engine shortlist + per-candidate scores at suggestion time
-- (mediumtext, JSON-encoded, NOT a native JSON type -- loader portability), copied
-- from the proposal before it is deleted.
--
-- action is the accountant's verdict as a short code (PHP constants): accept /
-- correct / reject_no_alt. entity is explicit (queried standalone for eval).
-- ============================================================================

CREATE TABLE llx_ledgerpilot_decision_log(
	rowid						integer AUTO_INCREMENT PRIMARY KEY,
	entity						integer DEFAULT 1 NOT NULL,		-- multi-company scope (getEntity())
	fk_bank						integer NOT NULL,				-- soft historical ref to llx_bank.rowid (no cascade)
	layer						varchar(16) NOT NULL,			-- step0|l1|l2 that produced the suggestion
	suggested_account			varchar(32),					-- account-track suggestion; NULL on invoice track
	suggested_fk_facture		integer,						-- invoice-track suggestion (sales)
	suggested_fk_facture_fourn	integer,						-- invoice-track suggestion (purchase)
	candidate_set				mediumtext,						-- shortlist + scores at suggestion time (from proposal)
	score						double,							-- suggestion confidence / similarity
	action						varchar(24) NOT NULL,			-- accept|correct|reject_no_alt (PHP constants)
	final_account				varchar(32),					-- account after the action; NULL on invoice track
	final_fk_facture			integer,						-- invoice after the action (sales)
	final_fk_facture_fourn		integer,						-- invoice after the action (purchase)
	fk_user						integer,						-- llx_user.rowid who acted
	date_creation				datetime NOT NULL				-- append timestamp (no tms: immutable)
) ENGINE=InnoDB;
