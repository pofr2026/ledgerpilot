-- ============================================================================
-- Keys / indexes for llx_ledgerpilot_knowledge.
--   uk(entity,counterparty_iban_hmac,account_number) : UPSERT target + L1 lookup.
--   uk(entity,normalized_label,account_number)        : UPSERT target for L2 labels.
--   ft(normalized_label)                              : L2 candidate generation.
-- Each row has exactly one non-NULL key column; MariaDB treats NULLs in a UNIQUE
-- as distinct, so the "other" key's NULL never collides and ON DUPLICATE KEY
-- UPDATE hits exactly one unique (never two at once -- the one case where ODKU is
-- undefined). The unique on (entity, iban_hmac, account) doubles as the L1 lookup
-- index, so no extra plain index is needed.
-- ============================================================================

ALTER TABLE llx_ledgerpilot_knowledge ADD UNIQUE INDEX uk_ledgerpilot_knowledge_iban(entity, counterparty_iban_hmac, account_number);
ALTER TABLE llx_ledgerpilot_knowledge ADD UNIQUE INDEX uk_ledgerpilot_knowledge_label(entity, normalized_label, account_number);
ALTER TABLE llx_ledgerpilot_knowledge ADD FULLTEXT INDEX ft_ledgerpilot_knowledge_label(normalized_label);
