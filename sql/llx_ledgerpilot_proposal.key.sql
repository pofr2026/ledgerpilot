-- ============================================================================
-- Keys / indexes for llx_ledgerpilot_proposal.
--   idx(entity,status) : the Dashboard listing of pending proposals.
--   idx(entity,fk_bank): look up the proposal for a given bank line.
-- ============================================================================

ALTER TABLE llx_ledgerpilot_proposal ADD INDEX idx_ledgerpilot_proposal_status(entity, status);
ALTER TABLE llx_ledgerpilot_proposal ADD INDEX idx_ledgerpilot_proposal_fk_bank(entity, fk_bank);
