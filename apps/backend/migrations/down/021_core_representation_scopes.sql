DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM core.legal_representation_scopes) THEN
        RAISE EXCEPTION 'Cannot roll back scoped authority evidence';
    END IF;
END $$;

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

DROP TRIGGER trg_legal_representation_verification ON core.legal_representations;
DROP FUNCTION core.validate_legal_representation_verification();
DROP TRIGGER trg_legal_representation_scopes_protect ON core.legal_representation_scopes;
DROP FUNCTION core.protect_legal_representation_scope();
DROP TABLE core.legal_representation_scopes;
