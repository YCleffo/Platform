DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM core.privacy_consents WHERE legacy_record = false)
       OR EXISTS (SELECT 1 FROM core.legal_representations) THEN
        RAISE EXCEPTION 'Cannot roll back privacy foundation while new evidence exists';
    END IF;
END;
$$;
DROP TRIGGER trg_privacy_consents_protect ON core.privacy_consents;
DROP FUNCTION core.protect_privacy_consent();
DROP INDEX core.idx_privacy_consents_actor_history;
DROP INDEX core.idx_privacy_consents_subject_history;
DROP INDEX core.uq_privacy_consents_one_active;
ALTER TABLE core.privacy_consents
    DROP CONSTRAINT chk_privacy_consent_subject_actor,
    DROP CONSTRAINT chk_privacy_consent_purpose,
    DROP CONSTRAINT chk_privacy_consent_retention,
    DROP COLUMN subject_person_id,
    DROP COLUMN granted_by_person_id,
    DROP COLUMN legal_representation_id,
    DROP COLUMN purpose_code,
    DROP COLUMN retention_until,
    DROP COLUMN legacy_record;
ALTER TABLE core.privacy_consents RENAME COLUMN granted_by_account_id TO account_id;
ALTER TABLE core.privacy_consents RENAME TO account_consents;
CREATE UNIQUE INDEX uq_account_consents_one_active
    ON core.account_consents (account_id, consent_code) WHERE withdrawn_at IS NULL;
CREATE FUNCTION core.protect_account_consent()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Consent evidence cannot be deleted' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.account_id, NEW.consent_code, NEW.document_version,
        NEW.document_sha256, NEW.locale, NEW.source, NEW.granted_at,
        NEW.request_id, NEW.created_at)
       IS DISTINCT FROM
       (OLD.id, OLD.account_id, OLD.consent_code, OLD.document_version,
        OLD.document_sha256, OLD.locale, OLD.source, OLD.granted_at,
        OLD.request_id, OLD.created_at) THEN
        RAISE EXCEPTION 'Consent evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.withdrawn_at IS NOT NULL OR NEW.withdrawn_at IS NULL OR NEW.withdrawn_at < OLD.granted_at THEN
        RAISE EXCEPTION 'Only an active consent may be withdrawn once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_account_consents_protect
BEFORE UPDATE OR DELETE ON core.account_consents
FOR EACH ROW EXECUTE FUNCTION core.protect_account_consent();
DROP TRIGGER trg_legal_representations_protect ON core.legal_representations;
DROP FUNCTION core.protect_legal_representation();
DROP TABLE core.legal_representations;
