-- Retention workers need UPDATE(id) to acquire SELECT FOR UPDATE row locks.
-- No process may change audit identity, including a no-op UPDATE id=id.
-- Other columns retain the existing privacy/FK anonymization semantics.
CREATE FUNCTION core.reject_audit_identity_update() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Audit identity is immutable' USING ERRCODE = '42501';
END;
$$;

CREATE TRIGGER audit_events_identity_immutable
BEFORE UPDATE OF id ON core.audit_events
FOR EACH ROW EXECUTE FUNCTION core.reject_audit_identity_update();
