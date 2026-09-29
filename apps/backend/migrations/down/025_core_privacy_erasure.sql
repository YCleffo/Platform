ALTER TABLE core.data_subject_request_executions DROP CONSTRAINT chk_erasure_execution_evidence,
    DROP COLUMN erasure_case_id, DROP COLUMN erasure_completed_items,
    DROP COLUMN erasure_blocked_items, DROP COLUMN erasure_failed_items;
DROP TRIGGER trg_privacy_destruction_evidence_protect ON core.privacy_destruction_evidence;
DROP FUNCTION core.protect_privacy_destruction_evidence();
DROP TABLE core.privacy_destruction_evidence;
DROP TABLE core.privacy_erasure_item_purposes;
DROP TABLE core.privacy_erasure_items;
DROP TABLE core.privacy_erasure_cases;
