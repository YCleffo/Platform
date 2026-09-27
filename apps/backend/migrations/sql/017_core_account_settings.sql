-- Centralized, versioned personal settings. Locale/timezone intentionally remain
-- on core.accounts and continue to use /api/auth/me/preferences.
CREATE TABLE core.account_settings (
    account_id uuid PRIMARY KEY REFERENCES core.accounts(id) ON DELETE CASCADE,
    settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    schema_version integer NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_account_settings_object CHECK (jsonb_typeof(settings) = 'object'),
    CONSTRAINT chk_account_settings_schema_version CHECK (schema_version >= 1),
    CONSTRAINT chk_account_settings_size CHECK (octet_length(settings::text) <= 32768),
    CONSTRAINT chk_account_settings_timestamps CHECK (updated_at >= created_at)
);

COMMENT ON TABLE core.account_settings IS
    'Sparse personal setting overrides. Effective values are resolved by the versioned Core schema registry.';
COMMENT ON COLUMN core.account_settings.settings IS
    'Whitelisted sparse overrides only; null reset values are never persisted.';
COMMENT ON COLUMN core.account_settings.schema_version IS
    'Version of the settings registry used to validate the stored document.';
