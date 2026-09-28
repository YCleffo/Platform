DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM core.role_permissions rp JOIN core.permissions p ON p.id = rp.permission_id
        WHERE p.code LIKE 'core.privacy.%'
    ) THEN
        RAISE EXCEPTION 'Cannot remove assigned privacy permissions';
    END IF;
END;
$$;
DELETE FROM core.permissions WHERE code LIKE 'core.privacy.%';
DROP TRIGGER trg_privacy_consent_validate_insert ON core.privacy_consents;
DROP FUNCTION core.validate_privacy_consent_insert();
ALTER TABLE core.legal_representations DROP CONSTRAINT chk_legal_rep_verified_evidence;
