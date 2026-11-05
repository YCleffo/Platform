-- Owned by Education. Core identity and organization UUIDs are application-port references.
CREATE TABLE education.teachers (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    account_id uuid NOT NULL, person_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, account_id), UNIQUE (organization_id, person_id)
);
CREATE TABLE education.groups (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, owner_person_id uuid NOT NULL,
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''), created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id, organization_id, owner_account_id)
);
CREATE TABLE education.students (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, person_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, owner_account_id, person_id), UNIQUE (id, organization_id, owner_account_id)
);
CREATE TABLE education.group_students (
    group_id uuid NOT NULL, student_id uuid NOT NULL, organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    PRIMARY KEY (group_id, student_id),
    FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id),
    FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)
);
CREATE TABLE education.lessons (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    owner_person_id uuid NOT NULL, group_id uuid NOT NULL, title varchar(160) NOT NULL CHECK (btrim(title) <> ''),
    starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL, location varchar(240) NOT NULL DEFAULT '',
    status varchar(20) NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'completed', 'cancelled')),
    created_at timestamptz NOT NULL DEFAULT now(), CHECK (ends_at > starts_at AND ends_at <= starts_at + interval '24 hours'),
    FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id),
    UNIQUE (id, organization_id, owner_account_id)
);
CREATE INDEX lessons_owner_time ON education.lessons(organization_id, owner_account_id, starts_at, id);
CREATE TABLE education.attendance (
    lesson_id uuid NOT NULL, student_id uuid NOT NULL, organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    status varchar(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','present','absent')),
    updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(lesson_id, student_id),
    FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id),
    FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)
);
CREATE TABLE education.events (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    owner_person_id uuid NOT NULL, title varchar(160) NOT NULL CHECK(btrim(title) <> ''),
    kind varchar(20) NOT NULL CHECK(kind IN ('meeting','birthday','other')),
    starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL, location varchar(240) NOT NULL DEFAULT '',
    CHECK(ends_at > starts_at AND ends_at <= starts_at + interval '24 hours')
);
CREATE INDEX events_owner_time ON education.events(organization_id, owner_account_id, starts_at, id);
CREATE TABLE education.files (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    owner_person_id uuid NOT NULL, lesson_id uuid, name varchar(240) NOT NULL,
    mime varchar(100) NOT NULL, purpose varchar(12) NOT NULL DEFAULT 'material' CHECK(purpose IN ('avatar','material')),
    size_bytes integer NOT NULL CHECK(size_bytes > 0 AND size_bytes <= 10485760),
    sha256 char(64) NOT NULL, storage_key uuid NOT NULL UNIQUE, created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id),
    UNIQUE (id, owner_account_id)
);
CREATE TABLE education.profiles (
    id uuid PRIMARY KEY DEFAULT uuidv7(), account_id uuid NOT NULL UNIQUE, person_id uuid NOT NULL,
    display_name varchar(160), avatar_file_id uuid,
    FOREIGN KEY (avatar_file_id, account_id) REFERENCES education.files(id, owner_account_id)
);
CREATE TABLE education.threads (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    subject varchar(160) NOT NULL CHECK(btrim(subject) <> ''), created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(id, organization_id)
);
CREATE TABLE education.thread_members (
    thread_id uuid NOT NULL, organization_id uuid NOT NULL, person_id uuid NOT NULL,
    last_read_at timestamptz, PRIMARY KEY(thread_id, person_id),
    FOREIGN KEY(thread_id, organization_id) REFERENCES education.threads(id, organization_id)
);
CREATE INDEX thread_members_person ON education.thread_members(person_id, organization_id, thread_id);
CREATE TABLE education.messages (
    id uuid PRIMARY KEY DEFAULT uuidv7(), thread_id uuid NOT NULL, organization_id uuid NOT NULL,
    sender_person_id uuid NOT NULL, body text NOT NULL CHECK(length(body) BETWEEN 1 AND 5000),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY(thread_id, organization_id) REFERENCES education.threads(id, organization_id)
);
CREATE INDEX messages_thread_time ON education.messages(thread_id, created_at, id);
CREATE TABLE education.notifications (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, person_id uuid NOT NULL,
    thread_id uuid NOT NULL REFERENCES education.threads(id), created_at timestamptz NOT NULL DEFAULT now(), read_at timestamptz
);
CREATE INDEX notifications_recipient ON education.notifications(organization_id, person_id, created_at, id);
CREATE TABLE education.privacy_receipts (item_id uuid PRIMARY KEY, result varchar(30) NOT NULL, created_at timestamptz NOT NULL DEFAULT now());

INSERT INTO core.modules(code,name,description,version,enabled) VALUES
    ('education','Education','Optional education workspace','1.0.0',false) ON CONFLICT(code) DO NOTHING;
INSERT INTO core.permissions(module_id,code,name,description,is_system)
SELECT m.id, p.code, p.name, p.name, true FROM core.modules m CROSS JOIN (VALUES
    ('education.access','Access Education'), ('education.schedule.manage','Manage own Education schedule'),
    ('education.people.manage','Manage own Education students and groups'),
    ('education.files.manage','Manage own Education files'), ('education.messages.use','Use Education messages')
) p(code,name) WHERE m.code='education' ON CONFLICT(code) DO NOTHING;
INSERT INTO core.roles(code,name,description,is_system,is_protected) VALUES
    ('education.teacher','Education teacher','Requires an explicit Education organization assignment.',true,false)
    ON CONFLICT(code) DO NOTHING;
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p
WHERE r.code='education.teacher' AND p.code LIKE 'education.%' ON CONFLICT DO NOTHING;
