-- ============================================================================
-- 010 — International preferences and session security snapshot
-- Extends existing Core tables; no new tables are introduced.
-- ============================================================================

ALTER TABLE core.accounts
    ADD COLUMN locale varchar(35),
    ADD COLUMN timezone varchar(64),
    ADD CONSTRAINT chk_accounts_locale_canonical
        CHECK (
            locale IS NULL
            OR locale ~ '^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$'
        ),
    ADD CONSTRAINT chk_accounts_timezone_not_blank
        CHECK (timezone IS NULL OR btrim(timezone) <> '');

ALTER TABLE core.auth_sessions
    ADD COLUMN browser_name varchar(120),
    ADD COLUMN browser_version varchar(60),
    ADD COLUMN os_name varchar(120),
    ADD COLUMN os_version varchar(60),
    ADD COLUMN device_type varchar(40),
    ADD COLUMN device_model varchar(160),
    ADD COLUMN client_locale varchar(35),
    ADD COLUMN client_timezone varchar(64),
    ADD COLUMN client_instance_id uuid,
    ADD COLUMN device_fingerprint_hash bytea,
    ADD COLUMN geo_country_code char(2),
    ADD COLUMN geo_region varchar(160),
    ADD COLUMN geo_city varchar(160),
    ADD COLUMN geo_timezone varchar(64),
    ADD CONSTRAINT chk_auth_sessions_browser_name_not_blank
        CHECK (browser_name IS NULL OR btrim(browser_name) <> ''),
    ADD CONSTRAINT chk_auth_sessions_os_name_not_blank
        CHECK (os_name IS NULL OR btrim(os_name) <> ''),
    ADD CONSTRAINT chk_auth_sessions_device_type_not_blank
        CHECK (device_type IS NULL OR btrim(device_type) <> ''),
    ADD CONSTRAINT chk_auth_sessions_client_locale_canonical
        CHECK (
            client_locale IS NULL
            OR client_locale ~ '^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$'
        ),
    ADD CONSTRAINT chk_auth_sessions_client_timezone_not_blank
        CHECK (client_timezone IS NULL OR btrim(client_timezone) <> ''),
    ADD CONSTRAINT chk_auth_sessions_fingerprint_hash_length
        CHECK (
            device_fingerprint_hash IS NULL
            OR octet_length(device_fingerprint_hash) = 32
        ),
    ADD CONSTRAINT chk_auth_sessions_geo_country_code
        CHECK (
            geo_country_code IS NULL
            OR geo_country_code ~ '^[A-Z]{2}$'
        ),
    ADD CONSTRAINT chk_auth_sessions_geo_timezone_not_blank
        CHECK (geo_timezone IS NULL OR btrim(geo_timezone) <> '');

CREATE INDEX idx_auth_sessions_account_active
    ON core.auth_sessions(account_id, last_seen_at DESC)
    WHERE revoked_at IS NULL;

COMMENT ON COLUMN core.accounts.locale IS
    'Optional saved canonical BCP 47 locale preference.';
COMMENT ON COLUMN core.accounts.timezone IS
    'Optional saved IANA timezone preference.';
COMMENT ON COLUMN core.auth_sessions.client_instance_id IS
    'Optional random frontend installation UUID; not an authentication factor.';
COMMENT ON COLUMN core.auth_sessions.device_fingerprint_hash IS
    'HMAC-SHA-256 risk signal; never an authentication factor.';
COMMENT ON COLUMN core.auth_sessions.geo_country_code IS
    'Approximate ISO 3166-1 alpha-2 country inferred from IP.';
COMMENT ON COLUMN core.auth_sessions.geo_city IS
    'Approximate city inferred from IP; never exact location.';
