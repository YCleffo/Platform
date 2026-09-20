-- ============================================================================
-- 003 — Accounts and authentication
-- Account = system identity. Person != Account.
-- ============================================================================

CREATE TABLE core.accounts (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    person_id uuid,

    account_type core.account_type NOT NULL DEFAULT 'human',
    status core.account_status NOT NULL DEFAULT 'pending_verification',

    label varchar(160),
    security_stamp uuid NOT NULL DEFAULT uuidv4(),

    locked_until timestamptz,
    last_login_at timestamptz,

    created_by_account_id uuid,
    approved_by_account_id uuid,
    approved_at timestamptz,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    disabled_at timestamptz,
    archived_at timestamptz,

    CONSTRAINT fk_accounts_person
        FOREIGN KEY (person_id)
        REFERENCES core.persons(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_accounts_created_by
        FOREIGN KEY (created_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_accounts_approved_by
        FOREIGN KEY (approved_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_accounts_human_has_person
        CHECK (account_type <> 'human' OR person_id IS NOT NULL)
);

CREATE TABLE core.auth_identifiers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    identifier_type core.auth_identifier_type NOT NULL,
    value varchar(320) NOT NULL,
    normalized_value varchar(320) NOT NULL,

    is_primary boolean NOT NULL DEFAULT false,
    can_login boolean NOT NULL DEFAULT true,
    can_recover boolean NOT NULL DEFAULT false,
    verified_at timestamptz,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_auth_identifiers_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_identifiers_value_not_blank CHECK (btrim(value) <> ''),
    CONSTRAINT chk_auth_identifiers_normalized_not_blank CHECK (btrim(normalized_value) <> '')
);

CREATE TABLE core.auth_password_credentials (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    password_hash text NOT NULL,
    password_algorithm varchar(32) NOT NULL,

    must_change_password boolean NOT NULL DEFAULT false,
    password_changed_at timestamptz NOT NULL DEFAULT now(),

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_auth_password_credentials_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_password_hash_not_blank CHECK (length(password_hash) > 0),
    CONSTRAINT chk_auth_password_algorithm_not_blank CHECK (btrim(password_algorithm) <> '')
);

CREATE TABLE core.auth_providers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),

    code varchar(50) NOT NULL,
    protocol varchar(50) NOT NULL,
    display_name varchar(120) NOT NULL,

    enabled boolean NOT NULL DEFAULT false,
    allow_login boolean NOT NULL DEFAULT true,
    allow_registration boolean NOT NULL DEFAULT false,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT chk_auth_providers_code_not_blank CHECK (btrim(code) <> ''),
    CONSTRAINT chk_auth_providers_protocol_not_blank CHECK (btrim(protocol) <> ''),
    CONSTRAINT chk_auth_providers_name_not_blank CHECK (btrim(display_name) <> '')
);

CREATE TABLE core.auth_external_identities (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,
    provider_id uuid NOT NULL,

    provider_subject varchar(255) NOT NULL,

    email varchar(320),
    email_verified boolean NOT NULL DEFAULT false,
    display_name varchar(255),
    avatar_url text,
    claims jsonb NOT NULL DEFAULT '{}'::jsonb,

    linked_at timestamptz NOT NULL DEFAULT now(),
    last_login_at timestamptz,

    CONSTRAINT fk_external_identities_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_external_identities_provider
        FOREIGN KEY (provider_id)
        REFERENCES core.auth_providers(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_external_identities_subject_not_blank CHECK (btrim(provider_subject) <> '')
);

CREATE TABLE core.auth_passkeys (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    credential_id bytea NOT NULL,
    public_key bytea NOT NULL,
    sign_count bigint NOT NULL DEFAULT 0,

    aaguid uuid,
    transports text[] NOT NULL DEFAULT ARRAY[]::text[],
    label varchar(160),

    created_at timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz,
    revoked_at timestamptz,

    CONSTRAINT fk_auth_passkeys_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_passkeys_sign_count CHECK (sign_count >= 0),
    CONSTRAINT chk_auth_passkeys_credential_not_empty CHECK (octet_length(credential_id) > 0),
    CONSTRAINT chk_auth_passkeys_public_key_not_empty CHECK (octet_length(public_key) > 0)
);

CREATE TABLE core.auth_totp_factors (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    label varchar(160),
    secret_encrypted bytea NOT NULL,
    algorithm core.totp_algorithm NOT NULL DEFAULT 'sha1',
    digits smallint NOT NULL DEFAULT 6,
    period_seconds smallint NOT NULL DEFAULT 30,

    verified_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz,
    revoked_at timestamptz,

    CONSTRAINT fk_auth_totp_factors_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_totp_secret_not_empty CHECK (octet_length(secret_encrypted) > 0),
    CONSTRAINT chk_auth_totp_digits CHECK (digits BETWEEN 6 AND 8),
    CONSTRAINT chk_auth_totp_period CHECK (period_seconds > 0)
);

CREATE TABLE core.auth_recovery_codes (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    code_hash bytea NOT NULL,

    created_at timestamptz NOT NULL DEFAULT now(),
    used_at timestamptz,

    CONSTRAINT fk_auth_recovery_codes_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_recovery_code_hash_not_empty CHECK (octet_length(code_hash) > 0)
);

CREATE TABLE core.auth_sessions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,

    session_token_hash bytea NOT NULL,
    refresh_token_hash bytea,
    security_stamp uuid NOT NULL,

    authentication_method varchar(80) NOT NULL,
    mfa_verified_at timestamptz,

    client_type varchar(40) NOT NULL,
    device_name varchar(160),
    ip_address inet,
    user_agent text,

    created_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,

    revoked_at timestamptz,
    revoke_reason varchar(255),

    CONSTRAINT fk_auth_sessions_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_sessions_token_not_empty CHECK (octet_length(session_token_hash) > 0),
    CONSTRAINT chk_auth_sessions_auth_method_not_blank CHECK (btrim(authentication_method) <> ''),
    CONSTRAINT chk_auth_sessions_client_type_not_blank CHECK (btrim(client_type) <> ''),
    CONSTRAINT chk_auth_sessions_expiry CHECK (expires_at > created_at)
);

CREATE TABLE core.auth_challenges (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid,

    challenge_type varchar(80) NOT NULL,
    secret_hash bytea NOT NULL,
    target_normalized varchar(320),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,

    attempts smallint NOT NULL DEFAULT 0,
    max_attempts smallint NOT NULL DEFAULT 5,

    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    cancelled_at timestamptz,

    CONSTRAINT fk_auth_challenges_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_auth_challenges_type_not_blank CHECK (btrim(challenge_type) <> ''),
    CONSTRAINT chk_auth_challenges_secret_not_empty CHECK (octet_length(secret_hash) > 0),
    CONSTRAINT chk_auth_challenges_attempts CHECK (attempts >= 0),
    CONSTRAINT chk_auth_challenges_max_attempts CHECK (max_attempts > 0),
    CONSTRAINT chk_auth_challenges_expiry CHECK (expires_at > created_at)
);
