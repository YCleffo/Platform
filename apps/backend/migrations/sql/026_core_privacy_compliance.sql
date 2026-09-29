ALTER TABLE core.data_subject_request_executions
    DROP CONSTRAINT chk_erasure_execution_evidence,
    ADD CONSTRAINT chk_erasure_execution_evidence CHECK (
        (erasure_case_id IS NULL AND erasure_completed_items IS NULL
            AND erasure_blocked_items IS NULL AND erasure_failed_items IS NULL)
        OR (execution_code = 'data_erasure' AND status = 'completed'
            AND erasure_case_id IS NOT NULL AND erasure_completed_items > 0
            AND erasure_blocked_items >= 0 AND erasure_failed_items = 0));

CREATE TABLE core.privacy_incidents (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    incident_type varchar(120) NOT NULL CHECK (incident_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    status varchar(24) NOT NULL CHECK (status IN ('detected','investigating','contained','resolved')),
    containment_status varchar(24) NOT NULL CHECK (containment_status IN ('unknown','in_progress','contained')),
    detected_at timestamptz NOT NULL,
    initial_notification_due_at timestamptz NOT NULL,
    initial_notification_at timestamptz,
    initial_notification_reference varchar(120),
    investigation_notification_due_at timestamptz NOT NULL,
    investigation_notification_at timestamptz,
    investigation_notification_reference varchar(120),
    estimated_subjects integer CHECK (estimated_subjects >= 0),
    safe_reason_code varchar(120) NOT NULL CHECK (safe_reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    created_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT chk_privacy_incident_deadlines CHECK (
        initial_notification_due_at = detected_at + interval '24 hours'
        AND investigation_notification_due_at = detected_at + interval '72 hours'),
    CONSTRAINT chk_privacy_incident_notifications CHECK (
        (initial_notification_at IS NULL) = (initial_notification_reference IS NULL)
        AND (investigation_notification_at IS NULL) = (investigation_notification_reference IS NULL))
);
CREATE INDEX idx_privacy_incidents_status ON core.privacy_incidents(status, detected_at);

CREATE TABLE core.privacy_incident_categories (
    incident_id uuid NOT NULL REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT,
    category_code varchar(120) NOT NULL CHECK (category_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    PRIMARY KEY (incident_id, category_code)
);

CREATE TABLE core.privacy_incident_subject_scope (
    incident_id uuid NOT NULL REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT,
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    PRIMARY KEY (incident_id, subject_person_id)
);
CREATE INDEX idx_privacy_incident_subject ON core.privacy_incident_subject_scope(subject_person_id);

CREATE TABLE core.privacy_incident_events (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    incident_id uuid NOT NULL REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT,
    event_code varchar(120) NOT NULL CHECK (event_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    from_status varchar(24),
    to_status varchar(24),
    reason_code varchar(120) CHECK (reason_code IS NULL OR reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    actor_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    occurred_at timestamptz NOT NULL
);
CREATE INDEX idx_privacy_incident_events ON core.privacy_incident_events(incident_id, occurred_at);
CREATE FUNCTION core.protect_privacy_incident_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Incident event is immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE TRIGGER trg_privacy_incident_event_protect BEFORE UPDATE OR DELETE
    ON core.privacy_incident_events FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_incident_event();

CREATE TABLE core.privacy_distribution_consents (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    granted_by_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    granted_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    legal_representation_id uuid REFERENCES core.legal_representations(id) ON DELETE RESTRICT,
    purpose_code varchar(120) NOT NULL CHECK (purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    category_codes text[] NOT NULL CHECK (cardinality(category_codes) BETWEEN 1 AND 32),
    field_codes text[] NOT NULL CHECK (cardinality(field_codes) BETWEEN 1 AND 64),
    condition_codes text[] NOT NULL DEFAULT '{}',
    restriction_codes text[] NOT NULL DEFAULT '{}',
    document_version varchar(80) NOT NULL,
    document_sha256 char(64) NOT NULL CHECK (document_sha256 ~ '^[a-f0-9]{64}$'),
    granted_at timestamptz NOT NULL,
    withdrawn_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_distribution_codes CHECK (
        array_to_string(category_codes, ',') ~ '^[a-z0-9.,_-]+$'
        AND array_to_string(field_codes, ',') ~ '^[a-z0-9.,_-]+$'
        AND (cardinality(condition_codes) = 0 OR array_to_string(condition_codes, ',') ~ '^[a-z0-9.,_-]+$')
        AND (cardinality(restriction_codes) = 0 OR array_to_string(restriction_codes, ',') ~ '^[a-z0-9.,_-]+$')),
    CONSTRAINT chk_distribution_actor CHECK (
        (subject_person_id = granted_by_person_id AND legal_representation_id IS NULL)
        OR (subject_person_id <> granted_by_person_id AND legal_representation_id IS NOT NULL)),
    CONSTRAINT chk_distribution_withdrawal CHECK (withdrawn_at IS NULL OR withdrawn_at >= granted_at)
);
CREATE INDEX idx_distribution_consent_subject ON core.privacy_distribution_consents(subject_person_id, purpose_code, granted_at DESC);
CREATE FUNCTION core.protect_privacy_distribution_consent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Distribution consent evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.withdrawn_at IS NOT NULL OR NEW.withdrawn_at IS NULL
       OR (to_jsonb(NEW) - 'withdrawn_at') IS DISTINCT FROM (to_jsonb(OLD) - 'withdrawn_at') THEN
        RAISE EXCEPTION 'Distribution consent may only be withdrawn once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_privacy_distribution_consent_protect BEFORE UPDATE OR DELETE
    ON core.privacy_distribution_consents FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_distribution_consent();
