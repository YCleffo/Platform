CREATE TABLE core.data_subject_requests (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    submitted_by_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    submitted_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    legal_representation_id uuid REFERENCES core.legal_representations(id) ON DELETE RESTRICT,
    request_type varchar(40) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'submitted',
    submitted_at timestamptz NOT NULL,
    identity_verified_at timestamptz,
    completed_at timestamptz,
    resolution_code varchar(120),
    submission_request_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_dsar_actor CHECK (
        (subject_person_id = submitted_by_person_id AND legal_representation_id IS NULL) OR
        (subject_person_id <> submitted_by_person_id AND legal_representation_id IS NOT NULL)
    ),
    CONSTRAINT chk_dsar_type CHECK (request_type IN
        ('access', 'processing_information', 'correction', 'restriction', 'processing_termination',
         'erasure', 'export', 'consent_withdrawal')),
    CONSTRAINT chk_dsar_status CHECK (status IN
        ('submitted', 'identity_verification', 'in_review', 'in_progress',
         'completed', 'partially_completed', 'rejected', 'cancelled')),
    CONSTRAINT chk_dsar_completion CHECK ((status IN ('completed', 'partially_completed')) = (completed_at IS NOT NULL)),
    CONSTRAINT chk_dsar_completion_order CHECK (completed_at IS NULL OR completed_at >= submitted_at),
    CONSTRAINT chk_dsar_resolution CHECK (resolution_code IS NULL OR resolution_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
);
CREATE INDEX idx_dsar_subject_submitted ON core.data_subject_requests (subject_person_id, submitted_at DESC);
CREATE INDEX idx_dsar_status_submitted ON core.data_subject_requests (status, submitted_at DESC);
CREATE INDEX idx_dsar_type_status ON core.data_subject_requests (request_type, status);
CREATE INDEX idx_dsar_actor_submitted ON core.data_subject_requests (submitted_by_account_id, submitted_at DESC);

CREATE FUNCTION core.validate_dsar_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor_person uuid;
BEGIN
    SELECT person_id INTO actor_person FROM core.accounts
    WHERE id = NEW.submitted_by_account_id AND account_type = 'human';
    IF actor_person IS NULL OR actor_person <> NEW.submitted_by_person_id THEN
        RAISE EXCEPTION 'Request actor must match human Account' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.legal_representation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM core.legal_representations r
        WHERE r.id = NEW.legal_representation_id AND r.subject_person_id = NEW.subject_person_id
          AND r.representative_person_id = NEW.submitted_by_person_id
    ) THEN
        RAISE EXCEPTION 'Request representation does not match subject and actor' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_dsar_actor BEFORE INSERT ON core.data_subject_requests
FOR EACH ROW EXECUTE FUNCTION core.validate_dsar_insert();

CREATE TABLE core.data_subject_request_deadlines (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    request_id uuid NOT NULL REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    deadline_code varchar(120) NOT NULL,
    triggered_at timestamptz NOT NULL,
    calendar_type varchar(20) NOT NULL,
    due_at timestamptz NOT NULL,
    extension_allowed boolean NOT NULL DEFAULT false,
    extended_at timestamptz,
    extended_due_at timestamptz,
    extension_reason_code varchar(120),
    satisfied_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (request_id, deadline_code),
    CONSTRAINT chk_dsar_deadline_code CHECK (deadline_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_dsar_calendar CHECK (calendar_type IN ('working_days', 'calendar_days', 'hours')),
    CONSTRAINT chk_dsar_due_order CHECK (due_at >= triggered_at),
    CONSTRAINT chk_dsar_extension CHECK (
        (extended_at IS NULL AND extended_due_at IS NULL AND extension_reason_code IS NULL) OR
        (extension_allowed AND extended_at IS NOT NULL AND extended_due_at IS NOT NULL
         AND extension_reason_code IS NOT NULL AND extended_due_at > due_at AND extended_at <= extended_due_at)
    ),
    CONSTRAINT chk_dsar_extension_reason CHECK (extension_reason_code IS NULL OR extension_reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_dsar_satisfied_order CHECK (satisfied_at IS NULL OR satisfied_at >= triggered_at)
);
CREATE INDEX idx_dsar_deadline_open ON core.data_subject_request_deadlines (due_at) WHERE satisfied_at IS NULL;

CREATE FUNCTION core.protect_dsar_deadline() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Deadline evidence cannot be deleted' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.request_id, NEW.deadline_code, NEW.triggered_at, NEW.calendar_type,
        NEW.due_at, NEW.extension_allowed, NEW.created_at)
       IS DISTINCT FROM
       (OLD.id, OLD.request_id, OLD.deadline_code, OLD.triggered_at, OLD.calendar_type,
        OLD.due_at, OLD.extension_allowed, OLD.created_at) THEN
        RAISE EXCEPTION 'Original deadline evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.extended_at IS NOT NULL AND (NEW.extended_at, NEW.extended_due_at, NEW.extension_reason_code)
       IS DISTINCT FROM (OLD.extended_at, OLD.extended_due_at, OLD.extension_reason_code) THEN
        RAISE EXCEPTION 'Deadline cannot be extended twice' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.satisfied_at IS NOT NULL AND NEW.satisfied_at IS DISTINCT FROM OLD.satisfied_at THEN
        RAISE EXCEPTION 'Deadline satisfaction is immutable' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_dsar_deadline_protect BEFORE UPDATE OR DELETE ON core.data_subject_request_deadlines
FOR EACH ROW EXECUTE FUNCTION core.protect_dsar_deadline();

CREATE TABLE core.data_subject_request_events (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    request_id uuid NOT NULL REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    event_code varchar(120) NOT NULL,
    from_status varchar(32),
    to_status varchar(32),
    actor_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    reason_code varchar(120),
    occurred_at timestamptz NOT NULL,
    CONSTRAINT chk_dsar_event_code CHECK (event_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_dsar_event_reason CHECK (reason_code IS NULL OR reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
);
CREATE INDEX idx_dsar_event_history ON core.data_subject_request_events (request_id, occurred_at, id);
CREATE FUNCTION core.protect_dsar_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Request workflow events are immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE TRIGGER trg_dsar_event_protect BEFORE UPDATE OR DELETE ON core.data_subject_request_events
FOR EACH ROW EXECUTE FUNCTION core.protect_dsar_event();

CREATE TABLE core.privacy_processing_blocks (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    scope_type varchar(20) NOT NULL,
    purpose_code varchar(120),
    resource_type varchar(120),
    resource_id uuid,
    reason_code varchar(120) NOT NULL,
    source_request_id uuid REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT,
    started_at timestamptz NOT NULL,
    expires_at timestamptz,
    released_at timestamptz,
    released_by_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    release_reason_code varchar(120),
    created_by_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_privacy_block_scope CHECK (
        (scope_type = 'subject' AND purpose_code IS NULL AND resource_type IS NULL AND resource_id IS NULL) OR
        (scope_type = 'purpose' AND purpose_code IS NOT NULL AND resource_type IS NULL AND resource_id IS NULL) OR
        (scope_type = 'resource' AND resource_type IS NOT NULL AND resource_id IS NOT NULL)
    ),
    CONSTRAINT chk_privacy_block_codes CHECK (
        reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND
        (purpose_code IS NULL OR purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$') AND
        (resource_type IS NULL OR resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$') AND
        (release_reason_code IS NULL OR release_reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
    ),
    CONSTRAINT chk_privacy_block_time CHECK (expires_at IS NULL OR expires_at > started_at),
    CONSTRAINT chk_privacy_block_release CHECK (
        (released_at IS NULL AND released_by_account_id IS NULL AND release_reason_code IS NULL) OR
        (released_at IS NOT NULL AND released_by_account_id IS NOT NULL AND release_reason_code IS NOT NULL AND released_at >= started_at)
    )
);
CREATE INDEX idx_privacy_blocks_subject_active ON core.privacy_processing_blocks (subject_person_id) WHERE released_at IS NULL;
CREATE INDEX idx_privacy_blocks_request ON core.privacy_processing_blocks (source_request_id);

CREATE FUNCTION core.validate_privacy_block_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.source_request_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM core.data_subject_requests
        WHERE id = NEW.source_request_id AND subject_person_id = NEW.subject_person_id
    ) THEN
        RAISE EXCEPTION 'Block source request belongs to another subject' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_privacy_block_request BEFORE INSERT ON core.privacy_processing_blocks
FOR EACH ROW EXECUTE FUNCTION core.validate_privacy_block_request();
