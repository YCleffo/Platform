-- Homework owns answer revisions; Activities remain the exercise attempt engine.
ALTER TABLE education.students ADD CONSTRAINT students_homework_tenant UNIQUE(id,organization_id);
ALTER TABLE education.lessons ADD CONSTRAINT lessons_homework_tenant UNIQUE(id,organization_id);
CREATE TABLE education.homework_assignments (
 id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
 author_account_id uuid NOT NULL, author_person_id uuid NOT NULL,
 title varchar(160) NOT NULL CHECK(btrim(title)<>''), description text NOT NULL DEFAULT '' CHECK(length(description)<=8000),
 instructions text NOT NULL DEFAULT '' CHECK(length(instructions)<=20000),
 type varchar(12) NOT NULL CHECK(type IN ('text','files','typing','activity')),
 group_id uuid, lesson_id uuid, due_at timestamptz, allow_late boolean NOT NULL DEFAULT false,
 activity_code varchar(60) REFERENCES education.activity_definitions(code), activity_config jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(activity_config)='object'),
 max_attempts integer NOT NULL DEFAULT 3 CHECK(max_attempts BETWEEN 1 AND 20),
 attempt_policy varchar(10) NOT NULL DEFAULT 'latest' CHECK(attempt_policy IN ('first','latest','best')),
 status varchar(12) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','PUBLISHED','CLOSED','ARCHIVED')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(),
 published_at timestamptz,closed_at timestamptz,archived_at timestamptz,
 UNIQUE(id,organization_id),
 FOREIGN KEY(group_id,organization_id) REFERENCES education.groups(id,organization_id),
 FOREIGN KEY(lesson_id,organization_id) REFERENCES education.lessons(id,organization_id),
 CHECK((type IN ('text','files') AND activity_code IS NULL) OR (type IN ('typing','activity') AND activity_code IS NOT NULL))
);
CREATE INDEX homework_assignment_lists ON education.homework_assignments(organization_id,status,due_at,id);
CREATE INDEX homework_assignment_author ON education.homework_assignments(organization_id,author_account_id,created_at DESC,id);
CREATE TABLE education.homework_recipients (
 id uuid PRIMARY KEY DEFAULT uuidv7(),assignment_id uuid NOT NULL,organization_id uuid NOT NULL,
 student_id uuid NOT NULL,person_id uuid NOT NULL,assigned_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(assignment_id,student_id),UNIQUE(id,organization_id),
 FOREIGN KEY(assignment_id,organization_id) REFERENCES education.homework_assignments(id,organization_id),
 FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
CREATE INDEX homework_recipient_student ON education.homework_recipients(organization_id,student_id,assigned_at DESC,id);
CREATE TABLE education.homework_submissions (
 id uuid PRIMARY KEY DEFAULT uuidv7(),recipient_id uuid NOT NULL UNIQUE,organization_id uuid NOT NULL,
 state varchar(16) NOT NULL DEFAULT 'IN_PROGRESS' CHECK(state IN ('IN_PROGRESS','SUBMITTED','RETURNED','RESUBMITTED','REVIEWED')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),draft_text text NOT NULL DEFAULT '' CHECK(length(draft_text)<=20000),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(id,organization_id),FOREIGN KEY(recipient_id,organization_id) REFERENCES education.homework_recipients(id,organization_id)
);
CREATE INDEX homework_submission_states ON education.homework_submissions(organization_id,state,updated_at,id);
CREATE TABLE education.homework_submission_versions (
 id uuid PRIMARY KEY DEFAULT uuidv7(),submission_id uuid NOT NULL,organization_id uuid NOT NULL,
 sequence integer NOT NULL CHECK(sequence>0),assignment_version integer NOT NULL CHECK(assignment_version>0),
 assignment_snapshot jsonb NOT NULL CHECK(jsonb_typeof(assignment_snapshot)='object'),text text NOT NULL CHECK(length(text)<=20000),
 activity_attempt_id uuid REFERENCES education.activity_attempts(id),submitted_at timestamptz NOT NULL,late boolean NOT NULL,
 idempotency_key uuid NOT NULL,UNIQUE(submission_id,sequence),UNIQUE(submission_id,idempotency_key),UNIQUE(id,submission_id,organization_id),
 FOREIGN KEY(submission_id,organization_id) REFERENCES education.homework_submissions(id,organization_id)
);
CREATE UNIQUE INDEX homework_version_activity_unique ON education.homework_submission_versions(activity_attempt_id) WHERE activity_attempt_id IS NOT NULL;
CREATE TABLE education.homework_reviews (
 id uuid PRIMARY KEY DEFAULT uuidv7(),submission_id uuid NOT NULL,submission_version_id uuid NOT NULL,organization_id uuid NOT NULL,
 teacher_account_id uuid NOT NULL,decision varchar(10) NOT NULL CHECK(decision IN ('accepted','returned')),
 result_status varchar(20) CHECK(result_status IN ('mastered','developing','needs_support')),comment text NOT NULL DEFAULT '' CHECK(length(comment)<=5000),
 created_at timestamptz NOT NULL,idempotency_key uuid NOT NULL,UNIQUE(submission_id,idempotency_key),UNIQUE(submission_version_id),
 FOREIGN KEY(submission_version_id,submission_id,organization_id) REFERENCES education.homework_submission_versions(id,submission_id,organization_id),
 CHECK(decision='accepted' OR result_status IS NULL)
);
CREATE TABLE education.homework_revisions (
 assignment_id uuid NOT NULL,organization_id uuid NOT NULL,version integer NOT NULL,action varchar(30) NOT NULL,
 payload jsonb NOT NULL,actor_account_id uuid NOT NULL,created_at timestamptz NOT NULL,
 PRIMARY KEY(assignment_id,version),FOREIGN KEY(assignment_id,organization_id) REFERENCES education.homework_assignments(id,organization_id)
);
-- Atomic response records return the original response even after subsequent transitions.
CREATE TABLE education.homework_idempotency (
 organization_id uuid NOT NULL,actor_account_id uuid NOT NULL,operation varchar(16) NOT NULL,
 idempotency_key uuid NOT NULL,resource_id uuid NOT NULL,request jsonb NOT NULL,response jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),PRIMARY KEY(organization_id,actor_account_id,operation,idempotency_key)
);
CREATE FUNCTION education.homework_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND current_setting('education.homework_erasure',true)='on' THEN RETURN OLD; END IF;
 RAISE EXCEPTION 'Homework history is immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER homework_version_immutable BEFORE UPDATE OR DELETE ON education.homework_submission_versions FOR EACH ROW EXECUTE FUNCTION education.homework_immutable();
CREATE TRIGGER homework_review_immutable BEFORE UPDATE OR DELETE ON education.homework_reviews FOR EACH ROW EXECUTE FUNCTION education.homework_immutable();
CREATE TRIGGER homework_revision_immutable BEFORE UPDATE OR DELETE ON education.homework_revisions FOR EACH ROW EXECUTE FUNCTION education.homework_immutable();
CREATE FUNCTION education.homework_recipient_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM education.students s WHERE s.id=NEW.student_id AND s.organization_id=NEW.organization_id AND s.person_id=NEW.person_id)
 THEN RAISE EXCEPTION 'Homework recipient identity mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER homework_recipient_identity_guard BEFORE INSERT OR UPDATE OF student_id,person_id,organization_id ON education.homework_recipients FOR EACH ROW EXECUTE FUNCTION education.homework_recipient_identity();
INSERT INTO core.permissions(module_id,code,name,description,is_system)
SELECT m.id,p.code,p.name,p.name,true FROM core.modules m CROSS JOIN (VALUES
 ('education.homework.manage','Manage authorized homework'),('education.homework.review','Review authorized homework')
) p(code,name) WHERE m.code='education' ON CONFLICT(code) DO NOTHING;
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p WHERE r.code='education.teacher' AND p.code IN ('education.homework.manage','education.homework.review') ON CONFLICT DO NOTHING;
