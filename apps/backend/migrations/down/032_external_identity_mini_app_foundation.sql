-- Rollback for 032_external_identity_mini_app_foundation.sql.
-- Manual rollback only; the forward migration runner never executes files here.

ALTER TABLE core.auth_external_identities
    DROP COLUMN IF EXISTS last_used_at,
    DROP COLUMN IF EXISTS updated_at,
    DROP COLUMN IF EXISTS created_at;

DROP INDEX IF EXISTS core.uq_auth_external_identities_account_provider;
