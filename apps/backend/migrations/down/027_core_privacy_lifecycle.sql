DROP TABLE core.privacy_cleanup_runs;
CREATE OR REPLACE FUNCTION core.protect_dsar_deadline() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Deadline evidence cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
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
CREATE OR REPLACE FUNCTION core.protect_dsar_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Request workflow events are immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE OR REPLACE FUNCTION core.protect_dsar_execution() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Execution evidence cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
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
CREATE OR REPLACE FUNCTION core.protect_privacy_retention_decision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Retention decision evidence is immutable' USING ERRCODE = 'check_violation';
END;
$$;
CREATE OR REPLACE FUNCTION core.protect_privacy_destruction_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Destruction evidence is immutable' USING ERRCODE = 'check_violation';
END;
$$;
