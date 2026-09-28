-- Durable evidence for authenticated Core Account consent. No browser cookie ledger.
CREATE TABLE core.account_consents (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    consent_code varchar(120) NOT NULL,
    document_version varchar(80) NOT NULL,
    document_sha256 bytea NOT NULL,
    locale varchar(35) NOT NULL,
    source varchar(80) NOT NULL,
    granted_at timestamptz NOT NULL DEFAULT now(),
    withdrawn_at timestamptz,
    request_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_account_consents_code CHECK (consent_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_account_consents_version CHECK (btrim(document_version) <> '' AND document_version !~ '[[:cntrl:]]'),
    CONSTRAINT chk_account_consents_hash CHECK (octet_length(document_sha256) = 32),
    CONSTRAINT chk_account_consents_locale CHECK (locale ~ '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$'),
    CONSTRAINT chk_account_consents_source CHECK (source ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_account_consents_withdrawal CHECK (withdrawn_at IS NULL OR withdrawn_at >= granted_at)
);

CREATE UNIQUE INDEX uq_account_consents_one_active
    ON core.account_consents (account_id, consent_code) WHERE withdrawn_at IS NULL;
CREATE INDEX idx_account_consents_history
    ON core.account_consents (account_id, consent_code, granted_at DESC, id DESC);

CREATE FUNCTION core.protect_account_consent()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Consent evidence cannot be deleted' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.account_id, NEW.consent_code, NEW.document_version,
        NEW.document_sha256, NEW.locale, NEW.source, NEW.granted_at,
        NEW.request_id, NEW.created_at)
       IS DISTINCT FROM
       (OLD.id, OLD.account_id, OLD.consent_code, OLD.document_version,
        OLD.document_sha256, OLD.locale, OLD.source, OLD.granted_at,
        OLD.request_id, OLD.created_at) THEN
        RAISE EXCEPTION 'Consent evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.withdrawn_at IS NOT NULL OR NEW.withdrawn_at IS NULL OR NEW.withdrawn_at < OLD.granted_at THEN
        RAISE EXCEPTION 'Only an active consent may be withdrawn once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_account_consents_protect
BEFORE UPDATE OR DELETE ON core.account_consents
FOR EACH ROW EXECUTE FUNCTION core.protect_account_consent();

COMMENT ON TABLE core.account_consents IS
    'Immutable authenticated consent evidence; withdrawal preserves history.';
