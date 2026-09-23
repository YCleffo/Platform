-- ============================================================================
-- 015 — Core data hygiene and defensive database constraints
-- Enforces cheap, robust database invariants as a second line of defense.
-- Does NOT add or remove tables: Core schema remains strictly at 23 base tables.
-- ============================================================================

-- Persons
ALTER TABLE core.persons
    ADD CONSTRAINT chk_persons_middle_name_not_blank
        CHECK (middle_name IS NULL OR btrim(middle_name) <> ''),
    ADD CONSTRAINT chk_persons_display_name_not_blank
        CHECK (display_name IS NULL OR btrim(display_name) <> ''),
    ADD CONSTRAINT chk_persons_birth_date_bounds
        CHECK (birth_date IS NULL OR (birth_date <= CURRENT_DATE AND birth_date >= '1900-01-01'));

-- Person contacts
ALTER TABLE core.person_contacts
    ADD CONSTRAINT chk_person_contacts_label_not_blank
        CHECK (label IS NULL OR btrim(label) <> ''),
    ADD CONSTRAINT chk_person_contacts_normalized_email
        CHECK (contact_type <> 'email' OR (normalized_value LIKE '%@%' AND normalized_value = lower(normalized_value))),
    ADD CONSTRAINT chk_person_contacts_normalized_phone
        CHECK (contact_type <> 'phone' OR normalized_value ~ '^\+[1-9][0-9]{7,14}$');

-- Authentication identifiers
ALTER TABLE core.auth_identifiers
    ADD CONSTRAINT chk_auth_identifiers_normalized_email
        CHECK (identifier_type <> 'email' OR (normalized_value LIKE '%@%' AND normalized_value = lower(normalized_value))),
    ADD CONSTRAINT chk_auth_identifiers_normalized_phone
        CHECK (identifier_type <> 'phone' OR normalized_value ~ '^\+[1-9][0-9]{7,14}$'),
    ADD CONSTRAINT chk_auth_identifiers_normalized_username
        CHECK (identifier_type <> 'username' OR (normalized_value = lower(normalized_value) AND btrim(normalized_value) <> '' AND normalized_value ~ '^[a-z0-9._-]+$'));

-- Accounts
ALTER TABLE core.accounts
    ADD CONSTRAINT chk_accounts_label_not_blank
        CHECK (label IS NULL OR btrim(label) <> '');

-- Machine code invariants
ALTER TABLE core.roles
    ADD CONSTRAINT chk_roles_code_format
        CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

ALTER TABLE core.permissions
    ADD CONSTRAINT chk_permissions_code_format
        CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

ALTER TABLE core.modules
    ADD CONSTRAINT chk_modules_code_format
        CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

ALTER TABLE core.feature_flags
    ADD CONSTRAINT chk_feature_flags_code_format
        CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

ALTER TABLE core.system_settings
    ADD CONSTRAINT chk_system_settings_key_format
        CHECK (setting_key ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

ALTER TABLE core.module_settings
    ADD CONSTRAINT chk_module_settings_key_format
        CHECK (setting_key ~ '^[a-z0-9]+([._-][a-z0-9]+)*$');

COMMENT ON TABLE core.persons IS
    'Physical individuals separated from accounts. Core v1 base table (1 of 23). Data hygiene constraints applied.';
