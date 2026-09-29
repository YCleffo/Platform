CREATE TABLE core.privacy_erasure_cases (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    source_request_id uuid UNIQUE REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    reason_code varchar(120) NOT NULL CHECK (reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    status varchar(32) NOT NULL CHECK (status IN
        ('planned', 'in_progress', 'partially_completed', 'completed', 'blocked', 'failed')),
    created_at timestamptz NOT NULL,
    started_at timestamptz,
    completed_at timestamptz,
    updated_at timestamptz NOT NULL,
    created_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    CONSTRAINT chk_erasure_case_time CHECK (started_at IS NULL OR started_at >= created_at),
    CONSTRAINT chk_erasure_case_complete CHECK ((status = 'completed') = (completed_at IS NOT NULL))
);
CREATE INDEX idx_erasure_cases_subject ON core.privacy_erasure_cases(subject_person_id, created_at DESC);
CREATE INDEX idx_erasure_cases_status ON core.privacy_erasure_cases(status, updated_at);

CREATE TABLE core.privacy_erasure_items (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    case_id uuid NOT NULL REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT,
    resource_type varchar(120) NOT NULL CHECK (resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    resource_id uuid NOT NULL,
    policy_code varchar(120) NOT NULL CHECK (policy_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    planned_action varchar(24) CHECK (planned_action IN ('delete','anonymize','pseudonymize','custom')),
    status varchar(24) NOT NULL CHECK (status IN ('planned','in_progress','completed','blocked','failed')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    started_at timestamptz,
    completed_at timestamptz,
    failure_code varchar(120) CHECK (failure_code IS NULL OR failure_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    UNIQUE (case_id, resource_type, resource_id),
    CONSTRAINT chk_erasure_item_complete CHECK ((status = 'completed') = (completed_at IS NOT NULL)),
    CONSTRAINT chk_erasure_item_time CHECK (started_at IS NULL OR started_at >= created_at)
);
CREATE INDEX idx_erasure_items_case_status ON core.privacy_erasure_items(case_id, status, id);

CREATE TABLE core.privacy_erasure_item_purposes (
    item_id uuid NOT NULL REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT,
    purpose_code varchar(120) NOT NULL,
    policy_code varchar(120) NOT NULL,
    trigger_code varchar(120) NOT NULL,
    triggered_at timestamptz,
    contributor_code varchar(120) NOT NULL,
    PRIMARY KEY (item_id, purpose_code, contributor_code),
    CONSTRAINT chk_erasure_item_purpose_codes CHECK (
        purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        policy_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        trigger_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        contributor_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
);

CREATE TABLE core.privacy_destruction_evidence (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    case_id uuid NOT NULL REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT,
    item_id uuid NOT NULL UNIQUE REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT,
    resource_type varchar(120) NOT NULL,
    resource_reference_hash char(64) NOT NULL CHECK (resource_reference_hash ~ '^[a-f0-9]{64}$'),
    policy_code varchar(120) NOT NULL,
    evidence_policy_code varchar(120) NOT NULL,
    evidence_retain_until timestamptz NOT NULL,
    action varchar(24) NOT NULL CHECK (action IN ('delete','anonymize','pseudonymize','custom')),
    result varchar(24) NOT NULL CHECK (result IN ('deleted','anonymized','pseudonymized','custom_completed')),
    executed_at timestamptz NOT NULL,
    executor_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    journal_event_id uuid NOT NULL,
    CONSTRAINT chk_destruction_codes CHECK (
        resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        policy_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        evidence_policy_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
);
CREATE INDEX idx_destruction_evidence_case ON core.privacy_destruction_evidence(case_id, executed_at, id);
CREATE FUNCTION core.protect_privacy_destruction_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Destruction evidence is immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE TRIGGER trg_privacy_destruction_evidence_protect
    BEFORE UPDATE OR DELETE ON core.privacy_destruction_evidence
    FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_destruction_evidence();

ALTER TABLE core.data_subject_request_executions
    ADD COLUMN erasure_case_id uuid REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT,
    ADD COLUMN erasure_completed_items integer,
    ADD COLUMN erasure_blocked_items integer,
    ADD COLUMN erasure_failed_items integer,
    ADD CONSTRAINT chk_erasure_execution_evidence CHECK (
        (erasure_case_id IS NULL AND erasure_completed_items IS NULL AND erasure_blocked_items IS NULL AND erasure_failed_items IS NULL)
        OR (execution_code = 'data_erasure' AND status = 'completed' AND erasure_case_id IS NOT NULL
            AND erasure_completed_items >= 0 AND erasure_blocked_items >= 0 AND erasure_failed_items >= 0
            AND erasure_completed_items + erasure_blocked_items + erasure_failed_items > 0));
