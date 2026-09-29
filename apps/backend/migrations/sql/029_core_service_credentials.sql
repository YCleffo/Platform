CREATE TABLE core.service_credentials (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    credential_id uuid NOT NULL UNIQUE DEFAULT uuidv4(),
    secret_hash bytea NOT NULL CHECK (octet_length(secret_hash) = 32),
    status varchar(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'rotated', 'revoked')),
    scopes text[] NOT NULL CHECK (cardinality(scopes) > 0 AND array_position(scopes, NULL) IS NULL),
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz,
    last_used_at timestamptz,
    rotated_at timestamptz,
    revoked_at timestamptz,
    created_by_account_id uuid REFERENCES core.accounts(id) ON DELETE SET NULL,
    CONSTRAINT chk_service_credentials_expiry CHECK (expires_at IS NULL OR expires_at > created_at),
    CONSTRAINT chk_service_credentials_status CHECK (
        (status = 'active' AND rotated_at IS NULL AND revoked_at IS NULL)
        OR (status = 'rotated' AND rotated_at IS NOT NULL AND revoked_at IS NULL)
        OR (status = 'revoked' AND revoked_at IS NOT NULL)
    )
);
CREATE INDEX idx_service_credentials_account ON core.service_credentials (account_id, created_at DESC);
CREATE INDEX idx_service_credentials_cleanup ON core.service_credentials (COALESCE(revoked_at, rotated_at, expires_at)) WHERE status <> 'active' OR expires_at IS NOT NULL;

CREATE FUNCTION core.enforce_service_credential_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM core.accounts WHERE id = NEW.account_id AND account_type IN ('service', 'system') FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Service credential requires a machine account' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER service_credential_account_guard BEFORE INSERT OR UPDATE OF account_id
    ON core.service_credentials FOR EACH ROW EXECUTE FUNCTION core.enforce_service_credential_account();
CREATE FUNCTION core.prevent_human_service_credentials() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.account_type = 'human' AND EXISTS (SELECT 1 FROM core.service_credentials WHERE account_id = NEW.id) THEN
        RAISE EXCEPTION 'Machine credentials cannot belong to a human account' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER service_credential_account_type_guard BEFORE UPDATE OF account_type
    ON core.accounts FOR EACH ROW EXECUTE FUNCTION core.prevent_human_service_credentials();

INSERT INTO core.permissions (code, name, description, is_system) VALUES
    ('core.service_credentials.read', 'Read service credentials', 'List machine credential metadata.', true),
    ('core.service_credentials.manage', 'Manage service credentials', 'Create, rotate and revoke machine credentials.', true)
ON CONFLICT (code) DO NOTHING;
