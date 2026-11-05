-- Run only through db-role-gate.sh against its guarded disposable database.
BEGIN;
SELECT set_config('platform.role_gate_schema', :'schema', true);
DO $$
DECLARE target_schema text := current_setting('platform.role_gate_schema');
        audit_id bigint;
        locked_id bigint;
        deleted integer;
BEGIN
    IF target_schema = 'core' THEN
        INSERT INTO core.audit_events(actor_type, action, resource_type, resource_id)
        VALUES ('system', 'role.gate', 'role.gate', 'disposable') RETURNING id INTO audit_id;
    ELSIF target_schema = 'commercial' THEN
        INSERT INTO commercial.audit_events(actor_type, action, resource_type, resource_id, outcome)
        VALUES ('system', 'role.gate', 'role.gate', 'disposable', 'success') RETURNING id INTO audit_id;
    ELSE
        RAISE EXCEPTION 'Unexpected role probe schema';
    END IF;
    EXECUTE format('SELECT id FROM %I.audit_events WHERE id=$1 FOR UPDATE SKIP LOCKED', target_schema) INTO locked_id USING audit_id;
    IF locked_id IS DISTINCT FROM audit_id THEN RAISE EXCEPTION 'Retention row lock failed'; END IF;
    BEGIN
        EXECUTE format('UPDATE %I.audit_events SET id=DEFAULT WHERE id=$1', target_schema) USING audit_id;
        RAISE EXCEPTION 'Audit identity update was allowed';
    EXCEPTION WHEN insufficient_privilege OR check_violation THEN NULL;
    END;
    PERFORM set_config('app.privacy_lifecycle', 'on', true);
    EXECUTE format('DELETE FROM %I.audit_events WHERE id=$1', target_schema) USING audit_id;
    GET DIAGNOSTICS deleted = ROW_COUNT;
    IF deleted <> 1 THEN RAISE EXCEPTION 'Retention delete failed'; END IF;
END $$;
ROLLBACK;
