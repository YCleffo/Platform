CREATE TABLE core.privacy_legal_holds (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid REFERENCES core.persons(id) ON DELETE RESTRICT,
    scope_type varchar(20) NOT NULL CHECK (scope_type IN ('subject', 'purpose', 'resource')),
    purpose_code varchar(120),
    resource_type varchar(120),
    resource_id uuid,
    reason_code varchar(120) NOT NULL,
    source_type varchar(120),
    source_reference varchar(160),
    created_at timestamptz NOT NULL,
    created_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    expires_at timestamptz,
    released_at timestamptz,
    released_by_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    release_reason_code varchar(120),
    CONSTRAINT chk_privacy_hold_scope CHECK (
        (scope_type = 'subject' AND subject_person_id IS NOT NULL AND purpose_code IS NULL AND resource_type IS NULL AND resource_id IS NULL)
        OR (scope_type = 'purpose' AND purpose_code IS NOT NULL AND resource_type IS NULL AND resource_id IS NULL)
        OR (scope_type = 'resource' AND resource_type IS NOT NULL AND resource_id IS NOT NULL)),
    CONSTRAINT chk_privacy_hold_codes CHECK (
        reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND (purpose_code IS NULL OR purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (resource_type IS NULL OR resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (source_type IS NULL OR source_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (release_reason_code IS NULL OR release_reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
        AND (source_reference IS NULL OR source_reference ~ '^[a-zA-Z0-9._:/-]+$')),
    CONSTRAINT chk_privacy_hold_times CHECK (expires_at IS NULL OR expires_at > created_at),
    CONSTRAINT chk_privacy_hold_release CHECK (
        (released_at IS NULL AND released_by_account_id IS NULL AND release_reason_code IS NULL)
        OR (released_at >= created_at AND released_by_account_id IS NOT NULL AND release_reason_code IS NOT NULL))
);
CREATE INDEX idx_privacy_hold_subject_active ON core.privacy_legal_holds(subject_person_id, scope_type, expires_at) WHERE released_at IS NULL;
CREATE INDEX idx_privacy_hold_resource_active ON core.privacy_legal_holds(resource_type, resource_id) WHERE released_at IS NULL;

CREATE TABLE core.privacy_retention_decisions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    resource_type varchar(120) NOT NULL,
    resource_id uuid NOT NULL,
    purpose_code varchar(120) NOT NULL,
    policy_code varchar(120) NOT NULL,
    decision_code varchar(120) NOT NULL,
    trigger_code varchar(120) NOT NULL,
    triggered_at timestamptz,
    eligible_at timestamptz,
    legal_hold_id uuid REFERENCES core.privacy_legal_holds(id) ON DELETE RESTRICT,
    source_request_id uuid REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    evaluated_at timestamptz NOT NULL,
    evaluator_version varchar(40) NOT NULL,
    CONSTRAINT chk_privacy_retention_codes CHECK (
        resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND policy_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND trigger_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
        AND decision_code IN ('retain','not_yet_eligible','eligible_delete','eligible_anonymize',
            'eligible_pseudonymize','eligible_custom','blocked_by_legal_hold','blocked_by_active_basis',
            'blocked_by_missing_policy','blocked_by_unknown_basis','blocked_by_dependency'))
);
CREATE INDEX idx_privacy_retention_subject ON core.privacy_retention_decisions(subject_person_id, evaluated_at DESC);
CREATE INDEX idx_privacy_retention_resource ON core.privacy_retention_decisions(resource_type, resource_id, evaluated_at DESC);
CREATE FUNCTION core.protect_privacy_retention_decision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Retention decision evidence is immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE TRIGGER trg_privacy_retention_decision_protect
    BEFORE UPDATE OR DELETE ON core.privacy_retention_decisions
    FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_retention_decision();

ALTER TABLE core.data_subject_request_executions
    ADD COLUMN stopped_purpose_codes text[],
    ADD COLUMN retained_purpose_codes text[],
    ADD COLUMN reason_codes text[],
    ADD CONSTRAINT chk_termination_evidence CHECK (
        (stopped_purpose_codes IS NULL AND retained_purpose_codes IS NULL AND reason_codes IS NULL)
        OR (execution_code = 'processing_termination' AND status = 'completed'
            AND stopped_purpose_codes IS NOT NULL AND retained_purpose_codes IS NOT NULL AND reason_codes IS NOT NULL
            AND cardinality(stopped_purpose_codes) + cardinality(retained_purpose_codes) BETWEEN 1 AND 100
            AND cardinality(reason_codes) <= 100
            AND array_to_string(stopped_purpose_codes || retained_purpose_codes || reason_codes, ',')
                !~ '[^a-z0-9_,.-]'));

INSERT INTO core.permissions (code, name, description, is_system) VALUES
    ('core.privacy.legal_holds.read', 'Read legal holds', 'Read scoped privacy legal holds', true),
    ('core.privacy.legal_holds.manage', 'Manage legal holds', 'Create and release scoped privacy legal holds', true)
ON CONFLICT (code) DO NOTHING;
