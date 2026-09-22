-- ============================================================================
-- 013 — External identity and MFA runtime
-- Extends existing authentication tables only; Core remains at 23 tables.
-- ============================================================================

ALTER TABLE core.auth_totp_factors
    ADD COLUMN last_used_counter bigint,
    ADD COLUMN secret_key_version smallint NOT NULL DEFAULT 1,
    ADD COLUMN setup_expires_at timestamptz,
    ADD CONSTRAINT chk_auth_totp_last_used_counter
        CHECK (last_used_counter IS NULL OR last_used_counter >= 0),
    ADD CONSTRAINT chk_auth_totp_secret_key_version
        CHECK (secret_key_version > 0),
    ADD CONSTRAINT chk_auth_totp_setup_expiry
        CHECK (setup_expires_at IS NULL OR setup_expires_at > created_at);

ALTER TABLE core.auth_recovery_codes
    ADD COLUMN revoked_at timestamptz;

CREATE UNIQUE INDEX uq_auth_totp_active_verified_account
    ON core.auth_totp_factors(account_id)
    WHERE verified_at IS NOT NULL AND revoked_at IS NULL;

CREATE INDEX idx_auth_totp_pending_expiry
    ON core.auth_totp_factors(setup_expires_at)
    WHERE verified_at IS NULL AND revoked_at IS NULL;

CREATE INDEX idx_auth_recovery_codes_available
    ON core.auth_recovery_codes(account_id)
    WHERE used_at IS NULL AND revoked_at IS NULL;

INSERT INTO core.auth_providers (
    code,
    protocol,
    display_name,
    enabled,
    allow_login,
    allow_registration
)
VALUES
    ('google', 'oidc', 'Google', false, true, false),
    ('telegram', 'oidc', 'Telegram', false, true, false)
ON CONFLICT (code) DO NOTHING;

COMMENT ON COLUMN core.auth_totp_factors.last_used_counter IS
    'Last accepted RFC 6238 time-step counter. A counter may be accepted only once.';
COMMENT ON COLUMN core.auth_totp_factors.secret_key_version IS
    'Version of the external encryption key used for secret_encrypted.';
COMMENT ON COLUMN core.auth_totp_factors.setup_expires_at IS
    'Expiry of a pending, unverified TOTP setup.';
COMMENT ON COLUMN core.auth_recovery_codes.revoked_at IS
    'Invalidation timestamp used by regeneration, MFA disable, and recovery reset.';
