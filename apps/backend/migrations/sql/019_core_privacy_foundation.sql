-- Generic authority and canonical subject consent. Existing evidence is retained as
-- legacy evidence because migration 018 did not record its subject or purpose.
CREATE TABLE core.legal_representations (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    subject_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    representative_person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    authority_type varchar(120) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'declared',
    valid_from timestamptz NOT NULL,
    valid_until timestamptz,
    verified_at timestamptz,
    verified_by_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    evidence_type varchar(120),
    evidence_reference varchar(512),
    evidence_sha256 bytea,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    revoked_at timestamptz,
    revoked_by_account_id uuid REFERENCES core.accounts(id) ON DELETE RESTRICT,
    revocation_reason_code varchar(120),
    CONSTRAINT chk_legal_rep_distinct CHECK (subject_person_id <> representative_person_id),
    CONSTRAINT chk_legal_rep_type CHECK (authority_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    CONSTRAINT chk_legal_rep_status CHECK (status IN ('declared', 'verification_pending', 'verified', 'revoked', 'expired', 'rejected')),
    CONSTRAINT chk_legal_rep_validity CHECK (valid_until IS NULL OR valid_until >= valid_from),
    CONSTRAINT chk_legal_rep_verification CHECK ((verified_at IS NULL) = (verified_by_account_id IS NULL)),
    CONSTRAINT chk_legal_rep_verified_status CHECK (status NOT IN ('verified', 'revoked', 'expired') OR verified_at IS NOT NULL),
    CONSTRAINT chk_legal_rep_revocation CHECK ((status = 'revoked') = (revoked_at IS NOT NULL)),
    CONSTRAINT chk_legal_rep_revocation_actor CHECK ((revoked_at IS NULL) = (revoked_by_account_id IS NULL)),
    CONSTRAINT chk_legal_rep_evidence_hash CHECK (evidence_sha256 IS NULL OR octet_length(evidence_sha256) = 32)
);
CREATE INDEX idx_legal_rep_subject ON core.legal_representations (subject_person_id, status, valid_until);
CREATE INDEX idx_legal_rep_representative ON core.legal_representations (representative_person_id, status);

CREATE FUNCTION core.protect_legal_representation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Legal representation evidence cannot be deleted outside privacy erasure' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.subject_person_id, NEW.representative_person_id, NEW.authority_type,
        NEW.valid_from, NEW.valid_until, NEW.evidence_type, NEW.evidence_reference,
        NEW.evidence_sha256, NEW.created_at)
       IS DISTINCT FROM
       (OLD.id, OLD.subject_person_id, OLD.representative_person_id, OLD.authority_type,
        OLD.valid_from, OLD.valid_until, OLD.evidence_type, OLD.evidence_reference,
        OLD.evidence_sha256, OLD.created_at) THEN
        RAISE EXCEPTION 'Legal representation evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status IN ('revoked', 'rejected', 'expired') OR
       (OLD.status = 'verified' AND NEW.status NOT IN ('verified', 'revoked', 'expired')) THEN
        RAISE EXCEPTION 'Legal representation cannot be reactivated' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_legal_representations_protect
BEFORE UPDATE OR DELETE ON core.legal_representations
FOR EACH ROW EXECUTE FUNCTION core.protect_legal_representation();

ALTER TABLE core.account_consents RENAME TO privacy_consents;
ALTER TABLE core.privacy_consents RENAME COLUMN account_id TO granted_by_account_id;
ALTER TABLE core.privacy_consents
    ADD COLUMN subject_person_id uuid REFERENCES core.persons(id) ON DELETE RESTRICT,
    ADD COLUMN granted_by_person_id uuid REFERENCES core.persons(id) ON DELETE RESTRICT,
    ADD COLUMN legal_representation_id uuid REFERENCES core.legal_representations(id) ON DELETE RESTRICT,
    ADD COLUMN purpose_code varchar(120),
    ADD COLUMN retention_until timestamptz,
    ADD COLUMN legacy_record boolean NOT NULL DEFAULT false;
UPDATE core.privacy_consents SET legacy_record = true;
ALTER TABLE core.privacy_consents
    ADD CONSTRAINT chk_privacy_consent_subject_actor CHECK (
        legacy_record OR
        (subject_person_id IS NOT NULL AND granted_by_person_id IS NOT NULL AND purpose_code IS NOT NULL
         AND ((subject_person_id = granted_by_person_id AND legal_representation_id IS NULL)
              OR (subject_person_id <> granted_by_person_id AND legal_representation_id IS NOT NULL)))
    ),
    ADD CONSTRAINT chk_privacy_consent_purpose CHECK (purpose_code IS NULL OR purpose_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    ADD CONSTRAINT chk_privacy_consent_retention CHECK (retention_until IS NULL OR retention_until >= granted_at);
DROP INDEX core.uq_account_consents_one_active;
CREATE UNIQUE INDEX uq_privacy_consents_one_active
    ON core.privacy_consents (subject_person_id, consent_code, purpose_code)
    WHERE withdrawn_at IS NULL AND legacy_record = false;
CREATE INDEX idx_privacy_consents_subject_history
    ON core.privacy_consents (subject_person_id, granted_at DESC, id DESC);
CREATE INDEX idx_privacy_consents_actor_history
    ON core.privacy_consents (granted_by_account_id, granted_at DESC, id DESC);

DROP TRIGGER trg_account_consents_protect ON core.privacy_consents;
DROP FUNCTION core.protect_account_consent();
CREATE FUNCTION core.protect_privacy_consent()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Consent evidence requires controlled privacy erasure' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.id, NEW.granted_by_account_id, NEW.subject_person_id, NEW.granted_by_person_id,
        NEW.legal_representation_id, NEW.purpose_code, NEW.consent_code, NEW.document_version,
        NEW.document_sha256, NEW.locale, NEW.source, NEW.granted_at, NEW.request_id,
        NEW.created_at, NEW.legacy_record, NEW.retention_until)
       IS DISTINCT FROM
       (OLD.id, OLD.granted_by_account_id, OLD.subject_person_id, OLD.granted_by_person_id,
        OLD.legal_representation_id, OLD.purpose_code, OLD.consent_code, OLD.document_version,
        OLD.document_sha256, OLD.locale, OLD.source, OLD.granted_at, OLD.request_id,
        OLD.created_at, OLD.legacy_record, OLD.retention_until) THEN
        RAISE EXCEPTION 'Consent evidence is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.withdrawn_at IS NOT NULL OR NEW.withdrawn_at IS NULL OR NEW.withdrawn_at < OLD.granted_at THEN
        RAISE EXCEPTION 'Only an active consent may be withdrawn once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_privacy_consents_protect
BEFORE UPDATE OR DELETE ON core.privacy_consents
FOR EACH ROW EXECUTE FUNCTION core.protect_privacy_consent();

COMMENT ON TABLE core.legal_representations IS 'Verified generic authority to act for another Person; no scanned documents stored here.';
COMMENT ON TABLE core.privacy_consents IS 'Subject and actor consent evidence; migration 018 rows remain flagged as unclassified legacy evidence.';
