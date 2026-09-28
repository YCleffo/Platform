-- Capabilities are part of the authority evidence, not a property of Person.
CREATE TABLE core.legal_representation_scopes (
    representation_id uuid NOT NULL REFERENCES core.legal_representations(id) ON DELETE RESTRICT,
    scope_code varchar(120) NOT NULL,
    PRIMARY KEY (representation_id, scope_code),
    CONSTRAINT chk_legal_rep_scope_code CHECK (scope_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')
);

CREATE FUNCTION core.protect_legal_representation_scope()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE authority_status varchar(32);
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'Authority scope evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    SELECT status INTO authority_status FROM core.legal_representations
    WHERE id = COALESCE(NEW.representation_id, OLD.representation_id) FOR UPDATE;
    IF authority_status NOT IN ('declared', 'verification_pending') THEN
        RAISE EXCEPTION 'Verified authority scopes cannot be changed' USING ERRCODE = 'check_violation';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;
CREATE TRIGGER trg_legal_representation_scopes_protect
BEFORE INSERT OR UPDATE OR DELETE ON core.legal_representation_scopes
FOR EACH ROW EXECUTE FUNCTION core.protect_legal_representation_scope();

CREATE FUNCTION core.validate_legal_representation_verification()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'verified' AND OLD.status <> 'verified' THEN
        IF NEW.valid_from > now() OR (NEW.valid_until IS NOT NULL AND NEW.valid_until < now()) THEN
            RAISE EXCEPTION 'Authority is outside its validity interval' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM core.legal_representation_scopes WHERE representation_id = NEW.id) THEN
            RAISE EXCEPTION 'Verified authority requires explicit scopes' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_legal_representation_verification
BEFORE UPDATE ON core.legal_representations
FOR EACH ROW EXECUTE FUNCTION core.validate_legal_representation_verification();

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
          AND r.valid_from <= now() AND (r.valid_until IS NULL OR r.valid_until >= now())
    ) THEN
        RAISE EXCEPTION 'Consent grant scope is not active' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
