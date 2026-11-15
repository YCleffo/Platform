-- Existing Activities and private Education files remain the runtime engines.
ALTER TABLE education.homework_recipients ADD COLUMN activity_assignment_id uuid REFERENCES education.activity_assignments(id);
CREATE UNIQUE INDEX homework_recipient_activity ON education.homework_recipients(activity_assignment_id) WHERE activity_assignment_id IS NOT NULL;
ALTER TABLE education.homework_submissions ADD COLUMN selected_activity_attempt_id uuid REFERENCES education.activity_attempts(id);

CREATE TABLE education.homework_attempt_links (
    attempt_id uuid PRIMARY KEY REFERENCES education.activity_attempts(id),
    organization_id uuid NOT NULL,
    recipient_id uuid NOT NULL REFERENCES education.homework_recipients(id),
    answer_sequence integer NOT NULL CHECK(answer_sequence>0),
    linked_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX homework_attempts_recipient ON education.homework_attempt_links(recipient_id,linked_at,attempt_id);

CREATE TABLE education.homework_files (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    assignment_id uuid NOT NULL REFERENCES education.homework_assignments(id),
    recipient_id uuid REFERENCES education.homework_recipients(id),
    owner_person_id uuid NOT NULL,
    purpose varchar(20) NOT NULL CHECK(purpose IN ('material','submission')),
    name varchar(240) NOT NULL, mime varchar(100) NOT NULL,
    size_bytes integer NOT NULL CHECK(size_bytes BETWEEN 1 AND 10485760),
    sha256 char(64) NOT NULL, storage_key uuid NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK((purpose='material' AND recipient_id IS NULL) OR (purpose='submission' AND recipient_id IS NOT NULL))
);
CREATE INDEX homework_files_assignment ON education.homework_files(organization_id,assignment_id,created_at,id);
CREATE INDEX homework_files_recipient ON education.homework_files(recipient_id,created_at,id);
CREATE TABLE education.homework_draft_files (
    recipient_id uuid NOT NULL REFERENCES education.homework_recipients(id),
    file_id uuid NOT NULL REFERENCES education.homework_files(id),
    PRIMARY KEY(recipient_id,file_id)
);
CREATE TABLE education.homework_version_files (
    version_id uuid NOT NULL REFERENCES education.homework_submission_versions(id),
    file_id uuid NOT NULL REFERENCES education.homework_files(id),
    PRIMARY KEY(version_id,file_id)
);

-- Keep a single Education notification center for messages and learning events.
ALTER TABLE education.notifications ALTER COLUMN thread_id DROP NOT NULL;
ALTER TABLE education.notifications ADD COLUMN kind varchar(40) NOT NULL DEFAULT 'message';
ALTER TABLE education.notifications ADD COLUMN resource_id uuid;
ALTER TABLE education.notifications ADD COLUMN resource_path varchar(240);
ALTER TABLE education.notifications ADD COLUMN subject varchar(160);
ALTER TABLE education.notifications ADD COLUMN body text;
ALTER TABLE education.notifications ADD COLUMN event_key varchar(240);
ALTER TABLE education.notifications ADD CONSTRAINT education_notification_target CHECK (
    (kind='message' AND thread_id IS NOT NULL) OR
    (kind IN ('homework_published','homework_deadline_changed','homework_submitted','homework_returned','homework_reviewed')
        AND resource_id IS NOT NULL AND resource_path IS NOT NULL AND subject IS NOT NULL)
);
CREATE UNIQUE INDEX education_notification_event ON education.notifications(organization_id,person_id,event_key) WHERE event_key IS NOT NULL;

-- Verify tenant/recipient consistency even for future direct database adapters.
CREATE FUNCTION education.check_homework_integration() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE recipient education.homework_recipients; assignment_org uuid; attempt education.activity_attempts;
BEGIN
 IF TG_TABLE_NAME='homework_files' THEN
   SELECT organization_id INTO assignment_org FROM education.homework_assignments WHERE id=NEW.assignment_id;
   IF assignment_org IS DISTINCT FROM NEW.organization_id THEN RAISE EXCEPTION 'Homework file tenant mismatch' USING ERRCODE='23514'; END IF;
   IF NEW.recipient_id IS NOT NULL THEN
     SELECT * INTO recipient FROM education.homework_recipients WHERE id=NEW.recipient_id;
     IF recipient.organization_id IS DISTINCT FROM NEW.organization_id OR recipient.assignment_id IS DISTINCT FROM NEW.assignment_id
       OR recipient.person_id IS DISTINCT FROM NEW.owner_person_id THEN
       RAISE EXCEPTION 'Homework file recipient mismatch' USING ERRCODE='23514';
     END IF;
   END IF;
 ELSE
   SELECT * INTO recipient FROM education.homework_recipients WHERE id=NEW.recipient_id;
   SELECT * INTO attempt FROM education.activity_attempts WHERE id=NEW.attempt_id;
   IF recipient.organization_id IS DISTINCT FROM NEW.organization_id OR attempt.organization_id IS DISTINCT FROM NEW.organization_id
     OR attempt.student_id IS DISTINCT FROM recipient.student_id OR attempt.assignment_id IS DISTINCT FROM recipient.activity_assignment_id
     OR attempt.status IS DISTINCT FROM 'finished' THEN
     RAISE EXCEPTION 'Homework activity evidence mismatch' USING ERRCODE='23514';
   END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER homework_file_scope BEFORE INSERT OR UPDATE ON education.homework_files
FOR EACH ROW EXECUTE FUNCTION education.check_homework_integration();
CREATE TRIGGER homework_attempt_scope BEFORE INSERT OR UPDATE ON education.homework_attempt_links
FOR EACH ROW EXECUTE FUNCTION education.check_homework_integration();

CREATE FUNCTION education.check_homework_file_link() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE recipient uuid;
BEGIN
 IF TG_TABLE_NAME='homework_draft_files' THEN recipient := NEW.recipient_id;
 ELSE
   SELECT s.recipient_id INTO recipient FROM education.homework_submission_versions v
   JOIN education.homework_submissions s ON s.id=v.submission_id WHERE v.id=NEW.version_id;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM education.homework_files f WHERE f.id=NEW.file_id AND f.recipient_id=recipient) THEN
   RAISE EXCEPTION 'Homework attachment owner mismatch' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER homework_draft_file_scope BEFORE INSERT OR UPDATE ON education.homework_draft_files
FOR EACH ROW EXECUTE FUNCTION education.check_homework_file_link();
CREATE TRIGGER homework_version_file_scope BEFORE INSERT OR UPDATE ON education.homework_version_files
FOR EACH ROW EXECUTE FUNCTION education.check_homework_file_link();
