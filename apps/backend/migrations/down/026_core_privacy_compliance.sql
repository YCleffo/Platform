DROP TRIGGER trg_privacy_distribution_consent_protect ON core.privacy_distribution_consents;
DROP FUNCTION core.protect_privacy_distribution_consent();
DROP TABLE core.privacy_distribution_consents;
DROP TRIGGER trg_privacy_incident_event_protect ON core.privacy_incident_events;
DROP FUNCTION core.protect_privacy_incident_event();
DROP TABLE core.privacy_incident_events;
DROP TABLE core.privacy_incident_subject_scope;
DROP TABLE core.privacy_incident_categories;
DROP TABLE core.privacy_incidents;
ALTER TABLE core.data_subject_request_executions
    DROP CONSTRAINT chk_erasure_execution_evidence,
    ADD CONSTRAINT chk_erasure_execution_evidence CHECK (
        (erasure_case_id IS NULL AND erasure_completed_items IS NULL
            AND erasure_blocked_items IS NULL AND erasure_failed_items IS NULL)
        OR (execution_code = 'data_erasure' AND status = 'completed'
            AND erasure_case_id IS NOT NULL AND erasure_completed_items >= 0
            AND erasure_blocked_items >= 0 AND erasure_failed_items >= 0
            AND erasure_completed_items + erasure_blocked_items + erasure_failed_items > 0));
