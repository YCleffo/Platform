-- Installation identity and locally verified entitlement snapshots.
-- Commercial issuance and private signing keys live outside this database.
CREATE SCHEMA platform_runtime;

CREATE TYPE platform_runtime.deployment_mode AS ENUM ('shared', 'dedicated', 'on_prem');
CREATE TYPE platform_runtime.environment AS ENUM ('development', 'test', 'staging', 'production');
CREATE TYPE platform_runtime.release_channel AS ENUM ('stable', 'beta', 'canary');
CREATE TYPE platform_runtime.installation_status AS ENUM ('provisioned', 'active', 'suspended', 'decommissioned');
CREATE TYPE platform_runtime.entitlement_status AS ENUM ('pending', 'applied', 'rejected');

CREATE TABLE platform_runtime.installations (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    singleton_key smallint NOT NULL DEFAULT 1 UNIQUE,
    deployment_mode platform_runtime.deployment_mode NOT NULL,
    environment platform_runtime.environment NOT NULL,
    application_version varchar(120) NOT NULL,
    release_id varchar(160) NOT NULL,
    release_channel platform_runtime.release_channel NOT NULL,
    status platform_runtime.installation_status NOT NULL DEFAULT 'provisioned',
    current_entitlement_sequence bigint NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    activated_at timestamptz,
    CONSTRAINT chk_installations_singleton CHECK (singleton_key = 1),
    CONSTRAINT chk_installations_version CHECK (btrim(application_version) <> '' AND application_version !~ '[[:cntrl:]]'),
    CONSTRAINT chk_installations_release CHECK (btrim(release_id) <> '' AND release_id !~ '[[:cntrl:]]'),
    CONSTRAINT chk_installations_entitlement_sequence CHECK (current_entitlement_sequence >= 0),
    CONSTRAINT chk_installations_activation CHECK (status <> 'active' OR activated_at IS NOT NULL)
);

CREATE FUNCTION platform_runtime.protect_installation_identity()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Installation identity cannot be deleted';
    END IF;
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.singleton_key IS DISTINCT FROM OLD.singleton_key
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Installation identity is immutable';
    END IF;
    IF NEW.current_entitlement_sequence < OLD.current_entitlement_sequence THEN
        RAISE EXCEPTION 'Entitlement sequence cannot decrease';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_installations_protect_identity
BEFORE UPDATE OR DELETE ON platform_runtime.installations
FOR EACH ROW EXECUTE FUNCTION platform_runtime.protect_installation_identity();

CREATE TABLE platform_runtime.entitlement_documents (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    installation_id uuid NOT NULL REFERENCES platform_runtime.installations(id) ON DELETE RESTRICT,
    document_version integer NOT NULL,
    sequence bigint NOT NULL,
    issued_at timestamptz NOT NULL,
    valid_from timestamptz NOT NULL,
    valid_until timestamptz,
    payload jsonb NOT NULL,
    signature bytea NOT NULL,
    signing_key_id varchar(160) NOT NULL,
    status platform_runtime.entitlement_status NOT NULL DEFAULT 'pending',
    applied_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_entitlement_documents_installation_sequence UNIQUE (installation_id, sequence),
    CONSTRAINT chk_entitlement_documents_version CHECK (document_version > 0),
    CONSTRAINT chk_entitlement_documents_sequence CHECK (sequence > 0),
    CONSTRAINT chk_entitlement_documents_validity CHECK (valid_until IS NULL OR valid_until > valid_from),
    CONSTRAINT chk_entitlement_documents_payload CHECK (jsonb_typeof(payload) = 'object'),
    CONSTRAINT chk_entitlement_documents_signature CHECK (octet_length(signature) = 64),
    CONSTRAINT chk_entitlement_documents_key_id CHECK (signing_key_id ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_entitlement_documents_applied_at CHECK ((status = 'applied') = (applied_at IS NOT NULL))
);

CREATE INDEX idx_entitlement_documents_status_sequence
    ON platform_runtime.entitlement_documents (installation_id, status, sequence DESC);
CREATE INDEX idx_entitlement_documents_valid_until
    ON platform_runtime.entitlement_documents (valid_until)
    WHERE valid_until IS NOT NULL;

CREATE FUNCTION platform_runtime.protect_entitlement_document()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    last_sequence bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Entitlement snapshots cannot be deleted';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF (NEW.id, NEW.installation_id, NEW.document_version, NEW.sequence,
            NEW.issued_at, NEW.valid_from, NEW.valid_until, NEW.payload,
            NEW.signature, NEW.signing_key_id, NEW.created_at)
           IS DISTINCT FROM
           (OLD.id, OLD.installation_id, OLD.document_version, OLD.sequence,
            OLD.issued_at, OLD.valid_from, OLD.valid_until, OLD.payload,
            OLD.signature, OLD.signing_key_id, OLD.created_at) THEN
            RAISE EXCEPTION 'Signed entitlement snapshot is immutable';
        END IF;
        IF OLD.status <> 'pending' THEN
            RAISE EXCEPTION 'Applied or rejected entitlement is immutable';
        END IF;
        IF OLD.status = 'pending' AND NEW.status = 'pending' AND NEW.applied_at IS DISTINCT FROM OLD.applied_at THEN
            RAISE EXCEPTION 'Pending entitlement cannot have an application time';
        END IF;
    ELSIF NEW.status <> 'pending' THEN
        RAISE EXCEPTION 'New entitlement must be pending';
    END IF;

    IF NEW.status = 'applied' AND (TG_OP = 'INSERT' OR OLD.status <> 'applied') THEN
        SELECT current_entitlement_sequence INTO last_sequence
        FROM platform_runtime.installations WHERE id = NEW.installation_id FOR UPDATE;
        IF NEW.sequence <= last_sequence THEN
            RAISE EXCEPTION 'Entitlement sequence % is not newer than %', NEW.sequence, last_sequence;
        END IF;
        UPDATE platform_runtime.installations
        SET current_entitlement_sequence = NEW.sequence, updated_at = now()
        WHERE id = NEW.installation_id;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_entitlement_documents_protect
BEFORE INSERT OR UPDATE OR DELETE ON platform_runtime.entitlement_documents
FOR EACH ROW EXECUTE FUNCTION platform_runtime.protect_entitlement_document();

COMMENT ON SCHEMA platform_runtime IS 'Local installation identity and signed entitlement snapshots; separate from frozen Core and the future Commercial database.';
COMMENT ON COLUMN platform_runtime.entitlement_documents.document_version IS 'Signed document format version; sequence orders snapshots for one installation.';
COMMENT ON COLUMN platform_runtime.entitlement_documents.signature IS 'Ed25519 signature bytes. The private signing key is never stored in this database.';
