-- Second-line protection for consent writes, independent of PHP callers.
ALTER TABLE core.legal_representations
    ADD CONSTRAINT chk_legal_rep_verified_evidence CHECK (
        status NOT IN ('verified', 'revoked', 'expired')
        OR (evidence_type IS NOT NULL AND (evidence_reference IS NOT NULL OR evidence_sha256 IS NOT NULL))
    );

CREATE FUNCTION core.validate_privacy_consent_insert()
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
CREATE TRIGGER trg_privacy_consent_validate_insert
BEFORE INSERT ON core.privacy_consents
FOR EACH ROW EXECUTE FUNCTION core.validate_privacy_consent_insert();

INSERT INTO core.permissions (code, name, description, is_system)
VALUES
    ('core.privacy.read', 'Read privacy cases', 'Restricted Core privacy oversight', true),
    ('core.privacy.manage', 'Manage privacy cases', 'Restricted Core privacy operations', true),
    ('core.privacy.representations.read', 'Read legal representations', 'Read representative authority evidence', true),
    ('core.privacy.representations.manage', 'Manage legal representations', 'Verify and revoke representative authority', true),
    ('core.privacy.requests.read', 'Read data subject requests', 'Read data subject request status', true),
    ('core.privacy.requests.manage', 'Manage data subject requests', 'Process data subject requests', true),
    ('core.privacy.erasure.manage', 'Manage privacy erasure', 'Execute controlled erasure workflow', true),
    ('core.privacy.incidents.read', 'Read privacy incidents', 'Read privacy incident status', true),
    ('core.privacy.incidents.manage', 'Manage privacy incidents', 'Manage privacy incident evidence', true)
ON CONFLICT (code) DO NOTHING;
