-- ============================================================================
-- Keys / indexes for llx_ledgerpilot_queue.
--   uk(fk_bank)                  : idempotency -- a bank line is enqueued once.
--   idx(entity,status,rowid)     : the claim (WHERE entity/status, ORDER BY rowid LIMIT).
--   idx(entity,status,claimed_at): the reap of stale leases (status, claimed_at < cutoff).
-- entity leads the composite indexes so the standalone queries stay entity-scoped.
-- ============================================================================

ALTER TABLE llx_ledgerpilot_queue ADD UNIQUE INDEX uk_ledgerpilot_queue_fk_bank(fk_bank);
ALTER TABLE llx_ledgerpilot_queue ADD INDEX idx_ledgerpilot_queue_claim(entity, status, rowid);
ALTER TABLE llx_ledgerpilot_queue ADD INDEX idx_ledgerpilot_queue_reap(entity, status, claimed_at);
