-- Consolidate homework evidence FKs & trigger onto canonical Activity Engine (PLATFORM_19_A).
-- Idempotent: safe whether activity migrations run before or after education migrations.

INSERT INTO education.activity_definitions(id, code, name, kind, contract_version, enabled, config, metrics, required_permission)
VALUES (
    '01a21144-d380-70d9-b2e7-6a2a9071412f',
    'platform-math-sprint',
    'Math Sprint Studio',
    'web-game',
    1,
    true,
    '{"durationSeconds":60,"difficulty":1,"operations":["add","sub"]}'::jsonb,
    '[{"key":"accuracy","label":"Accuracy"},{"key":"score","label":"Score"},{"key":"solvedCount","label":"Solved"}]'::jsonb,
    'education.activities.use'
)
ON CONFLICT (code) DO NOTHING;

-- Drop legacy FKs pointing to education.activity_assignments / education.activity_attempts.
ALTER TABLE education.homework_recipients
    DROP CONSTRAINT IF EXISTS homework_recipients_activity_assignment_id_fkey;
ALTER TABLE education.homework_submissions
    DROP CONSTRAINT IF EXISTS homework_submissions_selected_activity_attempt_id_fkey;
ALTER TABLE education.homework_submission_versions
    DROP CONSTRAINT IF EXISTS homework_submission_versions_activity_attempt_id_fkey;
ALTER TABLE education.homework_attempt_links
    DROP CONSTRAINT IF EXISTS homework_attempt_links_attempt_id_fkey;

-- Update homework evidence integrity trigger to validate against canonical activity.attempts
-- (with read-only fallback when activity schema is not yet initialized in isolated test runs).
CREATE OR REPLACE FUNCTION education.check_homework_integration()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    recipient education.homework_recipients;
    assignment_org uuid;
    attempt_org uuid;
    attempt_person uuid;
    attempt_assignment uuid;
    attempt_state text;
    has_canonical boolean := to_regclass('activity.attempts') IS NOT NULL;
BEGIN
    IF TG_TABLE_NAME = 'homework_files' THEN
        SELECT organization_id INTO assignment_org FROM education.homework_assignments WHERE id = NEW.assignment_id;
        IF assignment_org IS DISTINCT FROM NEW.organization_id THEN
            RAISE EXCEPTION 'Homework file tenant mismatch' USING ERRCODE = '23514';
        END IF;
        IF NEW.recipient_id IS NOT NULL THEN
            SELECT * INTO recipient FROM education.homework_recipients WHERE id = NEW.recipient_id;
            IF recipient.organization_id IS DISTINCT FROM NEW.organization_id
                OR recipient.assignment_id IS DISTINCT FROM NEW.assignment_id
                OR recipient.person_id IS DISTINCT FROM NEW.owner_person_id THEN
                RAISE EXCEPTION 'Homework file recipient mismatch' USING ERRCODE = '23514';
            END IF;
        END IF;
    ELSE
        SELECT * INTO recipient FROM education.homework_recipients WHERE id = NEW.recipient_id;
        IF has_canonical THEN
            SELECT a.organization_id, a.participant_person_id, a.assignment_id, a.state
            INTO attempt_org, attempt_person, attempt_assignment, attempt_state
            FROM activity.attempts a
            WHERE a.id = NEW.attempt_id;

            IF recipient.organization_id IS DISTINCT FROM NEW.organization_id
                OR attempt_org IS DISTINCT FROM NEW.organization_id
                OR attempt_person IS DISTINCT FROM recipient.person_id
                OR attempt_assignment IS DISTINCT FROM recipient.activity_assignment_id
                OR attempt_state NOT IN ('EVALUATED', 'COMPLETED', 'EXPIRED') THEN
                RAISE EXCEPTION 'Homework activity evidence mismatch' USING ERRCODE = '23514';
            END IF;
        ELSE
            RAISE EXCEPTION 'Canonical activity.attempts schema is required' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END
$function$;

DO $$
BEGIN
    IF to_regclass('activity.assignments') IS NOT NULL AND to_regclass('activity.attempts') IS NOT NULL THEN
        ALTER TABLE education.homework_recipients
            ADD CONSTRAINT homework_recipients_activity_assignment_id_fkey
            FOREIGN KEY (activity_assignment_id) REFERENCES activity.assignments(id);
        ALTER TABLE education.homework_submissions
            ADD CONSTRAINT homework_submissions_selected_activity_attempt_id_fkey
            FOREIGN KEY (selected_activity_attempt_id) REFERENCES activity.attempts(id);
        ALTER TABLE education.homework_submission_versions
            ADD CONSTRAINT homework_submission_versions_activity_attempt_id_fkey
            FOREIGN KEY (activity_attempt_id) REFERENCES activity.attempts(id);
        ALTER TABLE education.homework_attempt_links
            ADD CONSTRAINT homework_attempt_links_attempt_id_fkey
            FOREIGN KEY (attempt_id) REFERENCES activity.attempts(id);
    END IF;
END $$;

-- Ensure Activity Engine permissions are mapped onto Education roles even when activity:migrate ran first.
INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('activity.use')
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('activity.create', 'activity.assign', 'activity.review')
WHERE r.code IN ('education.teacher', 'core.admin')
ON CONFLICT DO NOTHING;
