-- ============================================================================
-- Materialized corpus projection for matching (spec §5) -- a fast-to-query
-- aggregate, NOT an observation log (that is decision_log, from which this whole
-- table is re-derivable). Each row maps ONE key to an account_number:
--   - L1: counterparty_iban_hmac -> account_number (exact IBAN match).
--   - L2: normalized_label       -> account_number (retriever candidate-gen via FULLTEXT).
-- Exactly one of the two key columns is non-NULL per row (see the dual UNIQUE in
-- .key.sql). Keys are separate columns (not a generic key_type/key_value) so the
-- FULLTEXT index covers only the label, never the opaque HMAC.
--
-- Aggregate, not append: an accepted observation UPSERTs
-- (ON DUPLICATE KEY UPDATE weight = weight + 1, last_seen = now) so the row count
-- stays bounded and FULLTEXT stays fast. weight is the observation count; last_seen
-- the recency the scorer reads.
--
-- No fk_bank: the projection is keyed on IBAN / label, not on a bank line, so it
-- naturally survives bank-line deletion (spec §5/§9 retention).
--
-- counterparty_iban_hmac is char(64) to match the keystone exactly (HMAC-SHA256
-- hex). For lines that went through the keystone the engine REUSES that hash
-- (JOIN from llx_bank, §4) instead of recomputing; only the history bootstrap
-- recomputes, with the same pepper and IBAN normalization (downstream
-- LedgerPilot\IbanPseudonymizer, cross-tested against a known keystone value).
--
-- normalized_label is utf8mb4: the normalizer preserves accents (transliteration
-- is deferred) and passes \p{S} symbols through, so a narrower charset would break
-- the column and its FULLTEXT index. Server dependency: innodb_ft_min_token_size=3
-- -- candidate-gen must tolerate labels shorter than the min token; the real
-- precision is the PHP trigram/Jaccard rerank, not FULLTEXT alone.
--
-- last_seen vs tms is DELIBERATE, not redundant: last_seen is the semantic recency
-- signal the scorer consumes; tms is the mechanical Dolibarr row-modification stamp.
-- Both move on UPSERT -- do not "simplify" one of them away.
--
-- ROW_FORMAT=DYNAMIC is pinned explicitly: the UNIQUE(entity, normalized_label,
-- account_number) key (see .key.sql) is ~1150 bytes on utf8mb4(255), over the
-- 767-byte InnoDB key-prefix limit of the legacy ROW_FORMAT=COMPACT. Modern MariaDB
-- defaults to DYNAMIC (3072-byte limit) so dev never trips it, but an older target
-- (MySQL 5.6/5.7 with innodb_large_prefix=OFF, or a COMPACT instance) would reject
-- the ALTER with "key too long" -- pinning DYNAMIC keeps the module portable when
-- distributed. counterparty_iban_hmac stays a bare char(64) (inherits the table
-- charset) to match the keystone column byte-for-byte, so the L1 path can compare
-- the two without a collation mismatch.
-- ============================================================================

CREATE TABLE llx_ledgerpilot_knowledge(
	rowid					integer AUTO_INCREMENT PRIMARY KEY,
	entity					integer DEFAULT 1 NOT NULL,			-- multi-company scope (getEntity())
	counterparty_iban_hmac	char(64),							-- L1 key: HMAC-SHA256 of IBAN (= keystone); NULL for label rows
	normalized_label		varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,	-- L2 key (FULLTEXT); NULL for IBAN rows
	account_number			varchar(32) NOT NULL,				-- mapped llx_accounting_account.account_number
	source					varchar(16) NOT NULL,				-- bootstrap|accepted (PHP constants)
	weight					integer DEFAULT 1 NOT NULL,			-- observation count (UPSERT increments)
	last_seen				datetime NOT NULL,					-- semantic recency the scorer reads
	date_creation			datetime NOT NULL,
	tms						timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
