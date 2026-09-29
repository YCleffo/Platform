DELETE FROM core.permissions WHERE code IN ('core.privacy.legal_holds.read', 'core.privacy.legal_holds.manage');
ALTER TABLE core.data_subject_request_executions DROP CONSTRAINT chk_termination_evidence,
    DROP COLUMN stopped_purpose_codes, DROP COLUMN retained_purpose_codes, DROP COLUMN reason_codes;
DROP TABLE core.privacy_retention_decisions;
DROP FUNCTION IF EXISTS core.protect_privacy_retention_decision();
DROP TABLE core.privacy_legal_holds;
