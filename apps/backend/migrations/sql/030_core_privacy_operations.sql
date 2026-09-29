ALTER TABLE core.data_subject_requests
    ALTER COLUMN submitted_by_person_id DROP NOT NULL,
    ADD COLUMN intake_channel varchar(32),
    ADD COLUMN identity_verification_code varchar(120),
    ADD COLUMN identity_evidence_reference varchar(500),
    ADD COLUMN representative_verification_reference varchar(500);
ALTER TABLE core.data_subject_requests DROP CONSTRAINT chk_dsar_actor;
ALTER TABLE core.data_subject_requests ADD CONSTRAINT chk_dsar_actor CHECK (
    (intake_channel IS NULL AND submitted_by_person_id IS NOT NULL
      AND identity_verification_code IS NULL AND identity_evidence_reference IS NULL
      AND representative_verification_reference IS NULL
      AND ((subject_person_id = submitted_by_person_id AND legal_representation_id IS NULL)
        OR (subject_person_id <> submitted_by_person_id AND legal_representation_id IS NOT NULL)))
    OR
    (intake_channel IN ('paper','email','signed_electronic_document')
      AND submitted_by_person_id IS NULL AND legal_representation_id IS NULL
      AND identity_verification_code IS NOT NULL AND identity_evidence_reference IS NOT NULL
      AND identity_verification_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
      AND length(btrim(identity_evidence_reference)) > 0)
);
CREATE OR REPLACE FUNCTION core.validate_dsar_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor_person uuid;
BEGIN
    SELECT person_id INTO actor_person FROM core.accounts
    WHERE id = NEW.submitted_by_account_id AND account_type = 'human';
    IF actor_person IS NULL THEN
        RAISE EXCEPTION 'Request actor must be a human Account' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.intake_channel IS NOT NULL THEN RETURN NEW; END IF;
    IF actor_person <> NEW.submitted_by_person_id THEN
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

CREATE TABLE core.privacy_collection_notices (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    purpose_code varchar(120) NOT NULL,
    category_codes text[] NOT NULL CHECK (cardinality(category_codes) > 0),
    source_code varchar(120) NOT NULL,
    collected_at timestamptz NOT NULL,
    notice_required boolean NOT NULL,
    exemption_code varchar(120),
    notice_issued_at timestamptz,
    notice_evidence_reference varchar(500),
    created_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT collection_notice_exemption CHECK (
       (notice_required AND exemption_code IS NULL)
       OR (NOT notice_required AND exemption_code IS NOT NULL)),
    CONSTRAINT collection_notice_issued CHECK (
       (notice_issued_at IS NULL AND notice_evidence_reference IS NULL)
       OR (notice_required AND notice_issued_at IS NOT NULL AND length(btrim(notice_evidence_reference)) > 0)),
    CONSTRAINT collection_notice_codes CHECK (
       purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
       AND source_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'
       AND (exemption_code IS NULL OR exemption_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'))
);
CREATE INDEX privacy_collection_notices_subject_idx ON core.privacy_collection_notices(subject_person_id, collected_at DESC);

CREATE TABLE core.privacy_legal_destruction_packages (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    evidence_id uuid NOT NULL UNIQUE REFERENCES core.privacy_destruction_evidence(id) ON DELETE CASCADE,
    case_id uuid NOT NULL REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT,
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    subject_name varchar(400) NOT NULL,
    operator_name varchar(300) NOT NULL,
    operator_address varchar(500) NOT NULL,
    processor_name varchar(300),
    processor_address varchar(500),
    category_codes text[] NOT NULL CHECK (cardinality(category_codes) > 0),
    information_system_name varchar(300) NOT NULL,
    information_system_code varchar(120) NOT NULL,
    destruction_method varchar(200) NOT NULL,
    destruction_reason_code varchar(120) NOT NULL,
    destroyed_at timestamptz NOT NULL,
    executor_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    document_reference varchar(500) NOT NULL,
    journal_event_id uuid NOT NULL,
    retain_until timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT legal_destruction_retention CHECK (retain_until >= destroyed_at + interval '3 years')
);
CREATE INDEX privacy_legal_destruction_case_idx ON core.privacy_legal_destruction_packages(case_id, destroyed_at, id);
CREATE FUNCTION core.protect_privacy_legal_destruction_package() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' AND current_setting('app.privacy_lifecycle', true) = 'on' THEN RETURN OLD; END IF;
    RAISE EXCEPTION 'Legal destruction package is immutable' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER privacy_legal_destruction_immutable BEFORE UPDATE OR DELETE
    ON core.privacy_legal_destruction_packages FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_legal_destruction_package();

CREATE TABLE core.privacy_legal_destruction_signatures (
    package_id uuid PRIMARY KEY REFERENCES core.privacy_legal_destruction_packages(id) ON DELETE CASCADE,
    signed_document_reference varchar(500) NOT NULL,
    signature_reference varchar(500) NOT NULL,
    attested_by_account_id uuid NOT NULL REFERENCES core.accounts(id) ON DELETE RESTRICT,
    attested_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT destruction_signature_references CHECK (
        length(btrim(signed_document_reference)) > 0 AND length(btrim(signature_reference)) > 0)
);
CREATE TRIGGER privacy_legal_signature_immutable BEFORE UPDATE OR DELETE
    ON core.privacy_legal_destruction_signatures FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_legal_destruction_package();
