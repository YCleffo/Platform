CREATE TABLE core.data_subject_request_executions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    request_id uuid NOT NULL REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    execution_code varchar(120) NOT NULL,
    status varchar(20) NOT NULL,
    actor_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    contributor_code varchar(120),
    resource_type varchar(120),
    resource_id uuid,
    schema_version varchar(40),
    result_sha256 char(64),
    result_size_bytes bigint,
    changed_paths text[],
    error_code varchar(120),
    started_at timestamptz NOT NULL,
    completed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_dsar_execution_codes CHECK (
        execution_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND (contributor_code IS NULL OR contributor_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (resource_type IS NULL OR resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (error_code IS NULL OR error_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
    ),
    CONSTRAINT chk_dsar_execution_status CHECK (status IN ('started', 'completed', 'failed')),
    CONSTRAINT chk_dsar_execution_terminal CHECK ((status = 'started') = (completed_at IS NULL)),
    CONSTRAINT chk_dsar_execution_time CHECK (completed_at IS NULL OR completed_at >= started_at),
    CONSTRAINT chk_dsar_execution_result CHECK (
        (result_sha256 IS NULL AND result_size_bytes IS NULL) OR
        (status = 'completed' AND result_sha256 ~ '^[a-f0-9]{64}$' AND result_size_bytes >= 0)
    ),
    CONSTRAINT chk_dsar_execution_error CHECK (
        (status = 'failed' AND error_code IS NOT NULL) OR
        (status <> 'failed' AND error_code IS NULL)
    ),
    CONSTRAINT chk_dsar_execution_paths CHECK (
        changed_paths IS NULL OR
        (cardinality(changed_paths) BETWEEN 1 AND 32 AND array_to_string(changed_paths, ',') !~ '[^a-zA-Z0-9_,.-]')
    ),
    CONSTRAINT chk_dsar_execution_resource CHECK ((resource_type IS NULL) = (resource_id IS NULL))
);
CREATE INDEX idx_dsar_executions_request ON core.data_subject_request_executions (request_id, started_at DESC);
CREATE INDEX idx_dsar_executions_code_status ON core.data_subject_request_executions (execution_code, status);

CREATE FUNCTION core.protect_dsar_execution() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Execution evidence cannot be deleted' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status <> 'started' THEN
        RAISE EXCEPTION 'Terminal execution evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.request_id, NEW.execution_code, NEW.actor_account_id, NEW.contributor_code,
        NEW.resource_type, NEW.resource_id, NEW.started_at, NEW.created_at)
       IS DISTINCT FROM
       (OLD.id, OLD.request_id, OLD.execution_code, OLD.actor_account_id, OLD.contributor_code,
        OLD.resource_type, OLD.resource_id, OLD.started_at, OLD.created_at) THEN
        RAISE EXCEPTION 'Execution identity is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'started' THEN
        RAISE EXCEPTION 'Execution may only move to a terminal result' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_dsar_execution_protect BEFORE UPDATE OR DELETE ON core.data_subject_request_executions
FOR EACH ROW EXECUTE FUNCTION core.protect_dsar_execution();

-- Policy time comes from the shared application Clock and is stored in evidence.
CREATE OR REPLACE FUNCTION core.validate_legal_representation_verification()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'verified' AND OLD.status <> 'verified' THEN
        IF NEW.verified_at IS NULL OR NEW.valid_from > NEW.verified_at
           OR (NEW.valid_until IS NOT NULL AND NEW.valid_until < NEW.verified_at) THEN
            RAISE EXCEPTION 'Authority is outside its validity interval' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM core.legal_representation_scopes WHERE representation_id = NEW.id) THEN
            RAISE EXCEPTION 'Verified authority requires explicit scopes' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION core.validate_privacy_consent_insert()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor_person uuid;
BEGIN
    IF NEW.legacy_record THEN
        RAISE EXCEPTION 'New legacy consent evidence is forbidden' USING ERRCODE = 'check_violation';
    END IF;
    SELECT person_id INTO actor_person FROM core.accounts
    WHERE id = NEW.granted_by_account_id AND account_type = 'human';
    IF actor_person IS NULL OR actor_person <> NEW.granted_by_person_id THEN
        RAISE EXCEPTION 'Consent actor does not match human Account' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.subject_person_id <> NEW.granted_by_person_id AND NOT EXISTS (
        SELECT 1 FROM core.legal_representations r
        JOIN core.legal_representation_scopes s ON s.representation_id = r.id
        WHERE r.id = NEW.legal_representation_id
          AND r.subject_person_id = NEW.subject_person_id
          AND r.representative_person_id = NEW.granted_by_person_id
          AND s.scope_code = 'privacy.consent.grant'
          AND r.status = 'verified' AND r.verified_at IS NOT NULL AND r.revoked_at IS NULL
          AND r.valid_from <= NEW.granted_at
          AND (r.valid_until IS NULL OR r.valid_until >= NEW.granted_at)
    ) THEN
        RAISE EXCEPTION 'Consent grant scope is not active' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
