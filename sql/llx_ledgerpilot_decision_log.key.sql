-- ============================================================================
-- Keys / indexes for llx_ledgerpilot_decision_log.
--   idx(entity,fk_bank)      : all decisions for a given bank line.
--   idx(entity,date_creation): chronological scans for offline eval / retention.
-- ============================================================================

ALTER TABLE llx_ledgerpilot_decision_log ADD INDEX idx_ledgerpilot_decision_log_fk_bank(entity, fk_bank);
ALTER TABLE llx_ledgerpilot_decision_log ADD INDEX idx_ledgerpilot_decision_log_date(entity, date_creation);
