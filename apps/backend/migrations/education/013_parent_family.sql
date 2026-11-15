-- Existing relationships remain records, never evidence of authorized representation.
ALTER TABLE education.student_guardians
 ADD COLUMN status varchar(12) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','revoked')),
 ADD COLUMN confirmed_at timestamptz,
 ADD COLUMN confirmed_by_account_id uuid REFERENCES core.accounts(id),
 ADD COLUMN revoked_at timestamptz,
 ADD COLUMN revoked_by_account_id uuid REFERENCES core.accounts(id),
 ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
 ADD CONSTRAINT guardian_confirmation_evidence CHECK(
   (status='pending' AND confirmed_at IS NULL AND revoked_at IS NULL) OR
   (status='confirmed' AND confirmed_at IS NOT NULL AND confirmed_by_account_id IS NOT NULL AND revoked_at IS NULL) OR
   (status='revoked' AND revoked_at IS NOT NULL AND revoked_by_account_id IS NOT NULL));

CREATE TABLE education.family_history (
 id uuid PRIMARY KEY DEFAULT uuidv7(),organization_id uuid NOT NULL,
 student_id uuid NOT NULL,guardian_person_id uuid NOT NULL REFERENCES core.persons(id),
 actor_account_id uuid REFERENCES core.accounts(id),action varchar(32) NOT NULL,
 relationship varchar(12) NOT NULL CHECK(relationship IN ('mother','father','guardian')),
 status varchar(12) NOT NULL CHECK(status IN ('pending','confirmed','revoked')),
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
CREATE INDEX family_history_student ON education.family_history(organization_id,student_id,created_at DESC,id);
CREATE TABLE education.parent_invites (
 id uuid PRIMARY KEY DEFAULT uuidv7(),organization_id uuid NOT NULL,student_id uuid NOT NULL,
 guardian_person_id uuid NOT NULL REFERENCES core.persons(id),code_hash char(64) NOT NULL UNIQUE,
 expires_at timestamptz NOT NULL,used_at timestamptz,used_account_id uuid REFERENCES core.accounts(id),
 revoked_at timestamptz,created_by_account_id uuid NOT NULL REFERENCES core.accounts(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(expires_at>created_at),CHECK((used_at IS NULL)=(used_account_id IS NULL)),
 CHECK(used_at IS NULL OR revoked_at IS NULL),
 FOREIGN KEY(student_id,guardian_person_id) REFERENCES education.student_guardians(student_id,guardian_person_id),
 FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
CREATE UNIQUE INDEX parent_invite_pending ON education.parent_invites(student_id,guardian_person_id)
 WHERE used_at IS NULL AND revoked_at IS NULL;
CREATE INDEX parent_invite_retention ON education.parent_invites(expires_at,id);
CREATE TABLE education.parent_accounts (
 organization_id uuid NOT NULL REFERENCES core.organizations(id),
 guardian_person_id uuid NOT NULL REFERENCES core.persons(id),account_id uuid NOT NULL REFERENCES core.accounts(id),
 activated_at timestamptz NOT NULL DEFAULT now(),revoked_at timestamptz,
 PRIMARY KEY(organization_id,guardian_person_id),UNIQUE(organization_id,account_id)
);
CREATE FUNCTION education.parent_account_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM core.accounts a WHERE a.id=NEW.account_id AND a.person_id=NEW.guardian_person_id AND a.account_type='human')
 THEN RAISE EXCEPTION 'Parent account person mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER parent_account_identity_guard BEFORE INSERT OR UPDATE ON education.parent_accounts
 FOR EACH ROW EXECUTE FUNCTION education.parent_account_identity();
CREATE FUNCTION education.family_history_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND current_setting('education.family_erasure',true)='on' THEN RETURN OLD; END IF;
 RAISE EXCEPTION 'Family history is immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER family_history_immutable BEFORE UPDATE OR DELETE ON education.family_history
 FOR EACH ROW EXECUTE FUNCTION education.family_history_immutable();

INSERT INTO core.permissions(module_id,code,name,description,is_system)
SELECT m.id,p.code,p.name,p.name,true FROM core.modules m CROSS JOIN (VALUES
 ('education.family.manage','Manage family relationships for authorized students'),
 ('education.family.confirm','Verify legal representation for authorized students'),
 ('education.parent.use','Read confirmed children educational information')
) p(code,name) WHERE m.code='education' ON CONFLICT(code) DO NOTHING;
INSERT INTO core.roles(code,name,description,is_system,is_protected) VALUES
 ('education.parent','Education parent','Read permitted educational data of confirmed children only.',true,false),
 ('education.family_verifier','Education family verifier','Additional representation verification permission; teacher assignment is still required.',true,false)
 ON CONFLICT(code) DO NOTHING;
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p WHERE
 (r.code='education.parent' AND p.code IN ('education.access','education.parent.use')) OR
 (r.code='education.family_verifier' AND p.code='education.family.confirm') OR
 (r.code='education.teacher' AND p.code='education.family.manage') ON CONFLICT DO NOTHING;

ALTER TABLE education.notifications ADD COLUMN parent_student_id uuid;
ALTER TABLE education.notifications ADD CONSTRAINT parent_notification_student
 FOREIGN KEY(parent_student_id,organization_id) REFERENCES education.students(id,organization_id);
ALTER TABLE education.notifications DROP CONSTRAINT education_notification_target;
ALTER TABLE education.notifications ADD CONSTRAINT education_notification_target CHECK (
 (kind='message' AND thread_id IS NOT NULL AND parent_student_id IS NULL) OR
 (kind IN ('homework_published','homework_deadline_changed','homework_submitted','homework_returned','homework_reviewed')
   AND resource_id IS NOT NULL AND resource_path IS NOT NULL AND subject IS NOT NULL AND parent_student_id IS NULL) OR
 (kind IN ('parent_homework_published','parent_homework_reviewed','parent_homework_returned','parent_homework_deadline_changed',
   'parent_schedule_changed','parent_family_confirmed','parent_family_revoked','parent_family_invited',
   'parent_family_invitation_revoked','parent_family_relationship_changed','parent_family_added','parent_family_updated')
   AND parent_student_id IS NOT NULL AND resource_id IS NOT NULL AND resource_path IS NOT NULL AND subject IS NOT NULL AND thread_id IS NULL)
);
CREATE INDEX parent_notification_list ON education.notifications(organization_id,person_id,parent_student_id,created_at DESC,id)
 WHERE parent_student_id IS NOT NULL;
