CREATE OR REPLACE FUNCTION core.validate_legal_representation_verification()
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
        SELECT 1 FROM core.legal_representations
        WHERE id = NEW.legal_representation_id
          AND subject_person_id = NEW.subject_person_id
          AND representative_person_id = NEW.granted_by_person_id
          AND status = 'verified' AND verified_at IS NOT NULL AND revoked_at IS NULL
          AND valid_from <= now() AND (valid_until IS NULL OR valid_until >= now())
    ) THEN
        RAISE EXCEPTION 'Consent representative authority is not active' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER trg_dsar_execution_protect ON core.data_subject_request_executions;
DROP FUNCTION core.protect_dsar_execution();
DROP TABLE core.data_subject_request_executions;
