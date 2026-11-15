-- Additive Education curriculum. Platform installation modules remain in core.modules.
CREATE TABLE education.programs (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''), description text NOT NULL DEFAULT '',
    version integer NOT NULL DEFAULT 1 CHECK (version > 0), archived_at timestamptz,
    created_by_account_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(id,organization_id), CHECK (length(description) <= 8000)
);
CREATE INDEX programs_organization ON education.programs(organization_id,created_at,id);
CREATE TABLE education.curriculum_modules (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, program_id uuid NOT NULL,
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''), description text NOT NULL DEFAULT '',
    position integer NOT NULL CHECK(position BETWEEN 0 AND 10000), version integer NOT NULL DEFAULT 1 CHECK(version > 0),
    archived_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(id,organization_id), FOREIGN KEY(program_id,organization_id) REFERENCES education.programs(id,organization_id),
    CHECK(length(description) <= 8000)
);
CREATE INDEX curriculum_modules_program ON education.curriculum_modules(program_id,position,id);
CREATE TABLE education.curriculum_lessons (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, module_id uuid NOT NULL,
    title varchar(160) NOT NULL CHECK (btrim(title) <> ''), description text NOT NULL DEFAULT '',
    position integer NOT NULL CHECK(position BETWEEN 0 AND 10000), version integer NOT NULL DEFAULT 1 CHECK(version > 0),
    archived_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(id,organization_id), FOREIGN KEY(module_id,organization_id) REFERENCES education.curriculum_modules(id,organization_id),
    CHECK(length(description) <= 8000)
);
CREATE INDEX curriculum_lessons_module ON education.curriculum_lessons(module_id,position,id);
CREATE TABLE education.curriculum_revisions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    entity_type varchar(20) NOT NULL CHECK(entity_type IN ('program','module','lesson')), entity_id uuid NOT NULL,
    version integer NOT NULL, payload jsonb NOT NULL, revised_by_account_id uuid NOT NULL, revised_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(entity_type,entity_id,version)
);
ALTER TABLE education.groups ADD CONSTRAINT groups_id_organization UNIQUE(id,organization_id);
CREATE TABLE education.group_teachers (
    group_id uuid NOT NULL, organization_id uuid NOT NULL, account_id uuid NOT NULL,
    assigned_by_account_id uuid NOT NULL, assigned_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(group_id,account_id),
    FOREIGN KEY(group_id,organization_id) REFERENCES education.groups(id,organization_id),
    FOREIGN KEY(organization_id,account_id) REFERENCES education.teachers(organization_id,account_id)
);
CREATE INDEX group_teachers_account ON education.group_teachers(organization_id,account_id,group_id);
-- Existing owners keep their access, including deployments with older teacher rows.
INSERT INTO education.group_teachers(group_id,organization_id,account_id,assigned_by_account_id)
SELECT g.id,g.organization_id,g.owner_account_id,g.owner_account_id FROM education.groups g
JOIN education.teachers t ON t.organization_id=g.organization_id AND t.account_id=g.owner_account_id
ON CONFLICT DO NOTHING;
CREATE FUNCTION education.can_teach_group(p_org uuid,p_actor uuid,p_group uuid) RETURNS boolean
LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM education.groups g WHERE g.organization_id=p_org AND g.id=p_group
 AND (g.owner_account_id=p_actor OR EXISTS(SELECT 1 FROM education.group_teachers gt
 WHERE gt.organization_id=p_org AND gt.group_id=g.id AND gt.account_id=p_actor)))
$$;
CREATE TABLE education.group_programs (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, group_id uuid NOT NULL, program_id uuid NOT NULL,
    program_version integer NOT NULL, snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object'),
    assigned_by_account_id uuid NOT NULL, assigned_at timestamptz NOT NULL DEFAULT now(), ended_at timestamptz,
    UNIQUE(id,organization_id), FOREIGN KEY(group_id,organization_id) REFERENCES education.groups(id,organization_id),
    FOREIGN KEY(program_id,organization_id) REFERENCES education.programs(id,organization_id)
);
CREATE UNIQUE INDEX group_programs_active ON education.group_programs(group_id) WHERE ended_at IS NULL;
CREATE INDEX group_programs_history ON education.group_programs(organization_id,group_id,assigned_at,id);
ALTER TABLE education.lessons ADD COLUMN group_program_id uuid;
ALTER TABLE education.lessons ADD COLUMN curriculum_lesson_id uuid;
ALTER TABLE education.lessons ADD COLUMN curriculum_snapshot jsonb;
ALTER TABLE education.lessons ADD CONSTRAINT lesson_group_program_fk FOREIGN KEY(group_program_id,organization_id)
    REFERENCES education.group_programs(id,organization_id);
ALTER TABLE education.lessons ADD CONSTRAINT lesson_curriculum_lesson_fk FOREIGN KEY(curriculum_lesson_id,organization_id)
    REFERENCES education.curriculum_lessons(id,organization_id);
ALTER TABLE education.lessons ADD CONSTRAINT lesson_curriculum_binding CHECK (
    (group_program_id IS NULL AND curriculum_lesson_id IS NULL AND curriculum_snapshot IS NULL) OR
    (group_program_id IS NOT NULL AND curriculum_lesson_id IS NOT NULL AND curriculum_snapshot IS NOT NULL));
CREATE INDEX lessons_curriculum ON education.lessons(group_program_id,curriculum_lesson_id,status);
-- Creating a Core person is explicit delegation, checked again by Education application service.
-- This does not grant teachers general Core person or membership administration.
INSERT INTO core.permissions(module_id,code,name,description,is_system)
SELECT id,'education.students.create','Create Education students','Create a Core person and enroll in an accessible Education group.',true
FROM core.modules WHERE code='education' ON CONFLICT(code) DO NOTHING;
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p
WHERE r.code='education.teacher' AND p.code='education.students.create' ON CONFLICT DO NOTHING;
