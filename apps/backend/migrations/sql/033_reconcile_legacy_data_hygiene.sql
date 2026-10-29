-- ============================================================================
-- 033 — Reconcile older installations of 015 with the current Core contract.
-- CORE-01/02: identical identifier/text invariants on fresh and upgraded DBs.
-- Some installations recorded 015 before its current checks were present.
-- Never edit the old migration history or normalize/delete existing rows here.
-- The runner wraps this whole file plus its history row in one transaction.
-- Invalid legacy values therefore ABORT the upgrade and preserve the old state.
-- ============================================================================

ALTER TABLE core.accounts
    DROP CONSTRAINT IF EXISTS chk_accounts_label_not_blank,
    ADD CONSTRAINT chk_accounts_label_not_blank
        CHECK (label IS NULL OR (btrim(label) <> '' AND label !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.person_contacts
    DROP CONSTRAINT IF EXISTS chk_person_contacts_label_not_blank,
    ADD CONSTRAINT chk_person_contacts_label_not_blank
        CHECK (label IS NULL OR (btrim(label) <> '' AND label !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.auth_identifiers
    DROP CONSTRAINT IF EXISTS chk_auth_identifiers_normalized_username,
    ADD CONSTRAINT chk_auth_identifiers_normalized_username
        CHECK (
            identifier_type <> 'username'
            OR (
                value = normalized_value
                AND char_length(normalized_value) BETWEEN 3 AND 60
                AND normalized_value ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
            )
        ) NOT VALID;

ALTER TABLE core.auth_passkeys
    DROP CONSTRAINT IF EXISTS chk_auth_passkeys_label_text,
    ADD CONSTRAINT chk_auth_passkeys_label_text
        CHECK (label IS NULL OR (btrim(label) <> '' AND label !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.auth_totp_factors
    DROP CONSTRAINT IF EXISTS chk_auth_totp_factors_label_text,
    ADD CONSTRAINT chk_auth_totp_factors_label_text
        CHECK (label IS NULL OR (btrim(label) <> '' AND label !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.module_settings
    DROP CONSTRAINT IF EXISTS chk_module_settings_description_text,
    ADD CONSTRAINT chk_module_settings_description_text
        CHECK (description IS NULL OR (btrim(description) <> '' AND char_length(description) <= 1000 AND description !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.feature_flags
    DROP CONSTRAINT IF EXISTS chk_feature_flags_description_text,
    ADD CONSTRAINT chk_feature_flags_description_text
        CHECK (description IS NULL OR (btrim(description) <> '' AND char_length(description) <= 1000 AND description !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.system_settings
    DROP CONSTRAINT IF EXISTS chk_system_settings_description_text,
    ADD CONSTRAINT chk_system_settings_description_text
        CHECK (description IS NULL OR (btrim(description) <> '' AND char_length(description) <= 1000 AND description !~ '[[:cntrl:]]')) NOT VALID;

ALTER TABLE core.accounts VALIDATE CONSTRAINT chk_accounts_label_not_blank;
ALTER TABLE core.person_contacts VALIDATE CONSTRAINT chk_person_contacts_label_not_blank;
ALTER TABLE core.auth_identifiers VALIDATE CONSTRAINT chk_auth_identifiers_normalized_username;
ALTER TABLE core.auth_passkeys VALIDATE CONSTRAINT chk_auth_passkeys_label_text;
ALTER TABLE core.auth_totp_factors VALIDATE CONSTRAINT chk_auth_totp_factors_label_text;
ALTER TABLE core.module_settings VALIDATE CONSTRAINT chk_module_settings_description_text;
ALTER TABLE core.feature_flags VALIDATE CONSTRAINT chk_feature_flags_description_text;
ALTER TABLE core.system_settings VALIDATE CONSTRAINT chk_system_settings_description_text;
