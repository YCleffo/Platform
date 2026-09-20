-- ============================================================================
-- 005 — Module settings, feature flags and installation-wide settings
-- Secrets do NOT belong in these tables.
-- ============================================================================

CREATE TABLE core.module_settings (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    module_id uuid NOT NULL,

    setting_key varchar(160) NOT NULL,
    value jsonb NOT NULL,
    description text,

    updated_by_account_id uuid,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_module_settings_module
        FOREIGN KEY (module_id)
        REFERENCES core.modules(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_module_settings_updated_by
        FOREIGN KEY (updated_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_module_settings_key_not_blank CHECK (btrim(setting_key) <> '')
);

CREATE TABLE core.feature_flags (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    module_id uuid,

    code varchar(160) NOT NULL,
    enabled boolean NOT NULL DEFAULT false,
    rollout_percent smallint NOT NULL DEFAULT 100,

    config jsonb NOT NULL DEFAULT '{}'::jsonb,
    description text,
    updated_by_account_id uuid,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_feature_flags_module
        FOREIGN KEY (module_id)
        REFERENCES core.modules(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_feature_flags_updated_by
        FOREIGN KEY (updated_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_feature_flags_code_not_blank CHECK (btrim(code) <> ''),
    CONSTRAINT chk_feature_flags_rollout CHECK (rollout_percent BETWEEN 0 AND 100)
);

CREATE TABLE core.system_settings (
    id uuid PRIMARY KEY DEFAULT uuidv7(),

    setting_key varchar(160) NOT NULL,
    value jsonb NOT NULL,
    is_public boolean NOT NULL DEFAULT false,
    description text,

    updated_by_account_id uuid,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_system_settings_updated_by
        FOREIGN KEY (updated_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_system_settings_key_not_blank CHECK (btrim(setting_key) <> '')
);
