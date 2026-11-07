-- ============================================================================
-- 005 — Student identity and Education Activities
-- Links Education students to real Core accounts (activation by invitation),
-- adds the student role/permissions, and builds the extensible activity
-- foundation (definitions, assignments, attempts, typed results) with the
-- first activity: KiberOne Typing. No Education-side passwords or sessions.
-- ============================================================================

CREATE TABLE education.student_accounts (
    student_id uuid NOT NULL REFERENCES education.students(id),
    organization_id uuid NOT NULL, account_id uuid NOT NULL, person_id uuid NOT NULL,
    activated_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
    PRIMARY KEY (student_id, organization_id),
    UNIQUE (organization_id, account_id)
);
CREATE INDEX student_accounts_account ON education.student_accounts(account_id, organization_id) WHERE revoked_at IS NULL;

CREATE TABLE education.student_invites (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    student_id uuid NOT NULL, code_hash char(64) NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL, used_at timestamptz, used_account_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)
);

INSERT INTO core.permissions(module_id,code,name,description,is_system)
SELECT m.id, p.code, p.name, p.name, true FROM core.modules m CROSS JOIN (VALUES
    ('education.student.use','Use own Education student cabinet'),
    ('education.activities.use','Run Education activities'),
    ('education.activities.manage','Assign Education activities and read own group results'),
    ('education.devices.manage','Manage own Education computers')
) p(code,name) WHERE m.code='education' ON CONFLICT(code) DO NOTHING;
INSERT INTO core.roles(code,name,description,is_system,is_protected) VALUES
    ('education.student','Education student','Own Education cabinet, diary and activities only.',true,false)
    ON CONFLICT(code) DO NOTHING;
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p
WHERE r.code='education.student' AND p.code IN ('education.access','education.student.use','education.activities.use')
    ON CONFLICT DO NOTHING;
-- education.teacher keeps every education.% permission via its wildcard grant in 001.

CREATE TABLE education.activity_definitions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), code varchar(60) NOT NULL UNIQUE,
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
    kind varchar(40) NOT NULL CHECK (kind IN ('typing','web-game','test','programming','native-app')),
    contract_version integer NOT NULL DEFAULT 1 CHECK (contract_version > 0),
    enabled boolean NOT NULL DEFAULT true,
    config jsonb NOT NULL DEFAULT '{}', metrics jsonb NOT NULL DEFAULT '[]',
    required_permission varchar(180) NOT NULL DEFAULT 'education.activities.use',
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE education.activity_assignments (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    activity_id uuid NOT NULL REFERENCES education.activity_definitions(id),
    group_id uuid, student_id uuid,
    config jsonb NOT NULL DEFAULT '{}', opens_at timestamptz, due_at timestamptz, completion jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (group_id IS NOT NULL OR student_id IS NOT NULL),
    FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id),
    FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)
);
CREATE INDEX activity_assignments_group ON education.activity_assignments(organization_id, owner_account_id, group_id);
CREATE TABLE education.activity_attempts (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    student_id uuid NOT NULL REFERENCES education.students(id),
    account_id uuid NOT NULL, activity_id uuid NOT NULL REFERENCES education.activity_definitions(id),
    assignment_id uuid REFERENCES education.activity_assignments(id),
    status varchar(20) NOT NULL DEFAULT 'running' CHECK (status IN ('running','finished','expired','failed')),
    source varchar(20) NOT NULL DEFAULT 'browser' CHECK (source IN ('browser','managed-device','native-app')),
    contract_version integer NOT NULL DEFAULT 1, exercise_text text,
    started_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
    result_payload jsonb, result_version integer,
    CHECK (finished_at IS NULL OR finished_at >= started_at)
);
CREATE INDEX activity_attempts_student ON education.activity_attempts(organization_id, student_id, started_at DESC, id);
CREATE TABLE education.typing_results (
    attempt_id uuid PRIMARY KEY REFERENCES education.activity_attempts(id),
    organization_id uuid NOT NULL, student_id uuid NOT NULL REFERENCES education.students(id),
    duration_seconds integer NOT NULL CHECK (duration_seconds IN (15,30,60)),
    language varchar(2) NOT NULL CHECK (language IN ('ru','en')),
    keystrokes integer NOT NULL CHECK (keystrokes >= 0 AND keystrokes <= 10000),
    correct_chars integer NOT NULL CHECK (correct_chars >= 0 AND correct_chars <= keystrokes),
    errors integer NOT NULL CHECK (errors >= 0 AND errors <= keystrokes),
    wpm numeric(6,1) NOT NULL CHECK (wpm >= 0 AND wpm <= 500),
    accuracy numeric(5,2) NOT NULL CHECK (accuracy >= 0 AND accuracy <= 100),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX typing_results_student ON education.typing_results(organization_id, student_id, wpm DESC);

INSERT INTO education.activity_definitions(code,name,kind,config,metrics,required_permission) VALUES
    ('kiberone-typing','KiberOne Typing','typing',
     '{"durations":[15,30,60],"languages":["ru","en"],"modes":["words"]}',
     '[{"code":"wpm","name":"Words per minute","kind":"number"},{"code":"accuracy","name":"Accuracy percent","kind":"number"},{"code":"errors","name":"Error count","kind":"number"},{"code":"keystrokes","name":"Keystrokes","kind":"number"}]',
     'education.activities.use')
    ON CONFLICT(code) DO NOTHING;
