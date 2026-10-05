-- ============================================================================
-- 032 — Provider-neutral verified external identity foundation
-- Reuses the established core.auth_external_identities relation. Existing OIDC
-- snapshots remain compatible; new Mini App adapters store only provider subject.
-- This repository's forward-only raw-SQL migration runner has no down-migration
-- convention; lifecycle is validated by applying this file to an isolated DB.
-- ============================================================================

ALTER TABLE core.auth_external_identities
    ADD COLUMN created_at timestamptz,
    ADD COLUMN updated_at timestamptz,
    ADD COLUMN last_used_at timestamptz;

UPDATE core.auth_external_identities
SET
    created_at = linked_at,
    updated_at = linked_at,
    last_used_at = last_login_at;

ALTER TABLE core.auth_external_identities
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN created_at SET DEFAULT now(),
    ALTER COLUMN updated_at SET NOT NULL,
    ALTER COLUMN updated_at SET DEFAULT now();

CREATE UNIQUE INDEX uq_auth_external_identities_account_provider
    ON core.auth_external_identities(account_id, provider_id);

COMMENT ON COLUMN core.auth_external_identities.created_at IS
    'Creation timestamp for the account-to-provider-subject link; mirrors linked_at for pre-existing rows.';
COMMENT ON COLUMN core.auth_external_identities.updated_at IS
    'Last local mutation of the external identity link; raw provider payloads are never stored.';
COMMENT ON COLUMN core.auth_external_identities.last_used_at IS
    'Last successful use after provider-side verification; mirrors last_login_at for login-based providers.';
