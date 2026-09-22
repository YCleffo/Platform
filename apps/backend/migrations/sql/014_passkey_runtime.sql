-- ================================================================
-- Migration 014: Passkey / WebAuthn Level 3 Runtime Metadata
-- ================================================================

ALTER TABLE core.auth_passkeys
    ADD COLUMN IF NOT EXISTS backup_eligible boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS backup_state boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN core.auth_passkeys.backup_eligible IS 'WebAuthn Level 3 flag: credential is eligible for cloud synchronization.';
COMMENT ON COLUMN core.auth_passkeys.backup_state IS 'WebAuthn Level 3 flag: credential is currently backed up in cloud keychain.';
