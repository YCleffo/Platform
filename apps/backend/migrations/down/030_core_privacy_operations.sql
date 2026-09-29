DROP TABLE IF EXISTS core.privacy_legal_destruction_signatures;
DROP TABLE core.privacy_legal_destruction_packages;
DROP FUNCTION core.protect_privacy_legal_destruction_package();
DROP TABLE core.privacy_collection_notices;
ALTER TABLE core.data_subject_requests DROP CONSTRAINT chk_dsar_actor;
ALTER TABLE core.data_subject_requests
    DROP COLUMN intake_channel,
    DROP COLUMN identity_verification_code,
    DROP COLUMN identity_evidence_reference,
    DROP COLUMN representative_verification_reference,
    ALTER COLUMN submitted_by_person_id SET NOT NULL;
ALTER TABLE core.data_subject_requests ADD CONSTRAINT chk_dsar_actor CHECK (
    (subject_person_id = submitted_by_person_id AND legal_representation_id IS NULL) OR
    (subject_person_id <> submitted_by_person_id AND legal_representation_id IS NOT NULL));
CREATE OR REPLACE FUNCTION core.validate_dsar_insert() RETURNS trigger LANGUAGE plpgsql AS $$
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
END; $$;
