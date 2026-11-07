-- ============================================================================
-- 001 — DeviceManagement foundation
-- Physical computers, classrooms/workstations, one-time enrollment, device
-- credentials, strictly ordered Windows session lifecycle, one-shot commands
-- and an event journal. Owned by DeviceManagement; student_id is an application
-- port reference to education.students (cross-schema, no FK).
-- ============================================================================
CREATE TABLE device_management.classrooms (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, owner_person_id uuid NOT NULL,
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''), created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id, organization_id, owner_account_id)
);
CREATE TABLE device_management.workstations (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    classroom_id uuid NOT NULL, number varchar(40) NOT NULL CHECK (btrim(number) <> ''),
    name varchar(160) NOT NULL DEFAULT '',
    FOREIGN KEY (classroom_id, organization_id, owner_account_id) REFERENCES device_management.classrooms(id, organization_id, owner_account_id),
    UNIQUE (classroom_id, number)
);
CREATE TABLE device_management.devices (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, owner_person_id uuid NOT NULL,
    classroom_id uuid, workstation_id uuid, name varchar(160) NOT NULL DEFAULT '',
    number varchar(40) NOT NULL DEFAULT '',
    status varchar(24) NOT NULL DEFAULT 'OFFLINE' CHECK (status IN
        ('OFFLINE','AVAILABLE','RESERVED','PREPARING','SYNCING_IN','ACTIVE','SYNCING_OUT','CLEANUP','RECOVERY_REQUIRED','ERROR')),
    enrollment_state varchar(20) NOT NULL DEFAULT 'pending' CHECK (enrollment_state IN ('pending','confirmed','revoked')),
    last_seen_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (classroom_id, organization_id, owner_account_id) REFERENCES device_management.classrooms(id, organization_id, owner_account_id),
    FOREIGN KEY (workstation_id) REFERENCES device_management.workstations(id),
    UNIQUE (id, organization_id, owner_account_id)
);
CREATE INDEX devices_owner ON device_management.devices(organization_id, owner_account_id, created_at, id);
CREATE TABLE device_management.device_enrollments (
    id uuid PRIMARY KEY DEFAULT uuidv7(), device_id uuid NOT NULL REFERENCES device_management.devices(id),
    code_hash char(64) NOT NULL UNIQUE, expires_at timestamptz NOT NULL, used_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE device_management.device_credentials (
    id uuid PRIMARY KEY DEFAULT uuidv7(), device_id uuid NOT NULL REFERENCES device_management.devices(id),
    credential_hash char(64) NOT NULL UNIQUE, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX device_credentials_device ON device_management.device_credentials(device_id) WHERE revoked_at IS NULL;
CREATE TABLE device_management.device_sessions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), device_id uuid NOT NULL REFERENCES device_management.devices(id),
    student_id uuid NOT NULL, organization_id uuid NOT NULL, owner_account_id uuid NOT NULL,
    status varchar(24) NOT NULL DEFAULT 'AUTHORIZED' CHECK (status IN
        ('AUTHORIZED','PREPARING','WINDOWS_PROFILE_READY','SYNCING_IN','ACTIVE','ENDING','SYNCING_OUT',
         'STORAGE_CONFIRMED','CLEANUP','CLOSED','SYNC_PENDING','RECOVERY_REQUIRED','FAILED')),
    desired_status varchar(24) CHECK (desired_status IS NULL OR desired_status IN
        ('AUTHORIZED','PREPARING','WINDOWS_PROFILE_READY','SYNCING_IN','ACTIVE','ENDING','SYNCING_OUT',
         'STORAGE_CONFIRMED','CLEANUP','CLOSED','SYNC_PENDING','RECOVERY_REQUIRED','FAILED')),
    created_at timestamptz NOT NULL DEFAULT now(), started_at timestamptz NOT NULL DEFAULT now(), ended_at timestamptz
);
CREATE UNIQUE INDEX device_sessions_active ON device_management.device_sessions(device_id) WHERE status NOT IN ('CLOSED','FAILED');
CREATE TABLE device_management.device_commands (
    id uuid PRIMARY KEY DEFAULT uuidv7(), device_id uuid NOT NULL REFERENCES device_management.devices(id),
    kind varchar(40) NOT NULL, payload jsonb NOT NULL DEFAULT '{}',
    state varchar(16) NOT NULL DEFAULT 'queued' CHECK (state IN ('queued','delivered','acknowledged','expired')),
    expires_at timestamptz NOT NULL, delivered_at timestamptz, acknowledged_at timestamptz,
    authorized_by_account_id uuid, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX device_commands_poll ON device_management.device_commands(device_id, state, id);
CREATE TABLE device_management.device_events (
    id uuid PRIMARY KEY DEFAULT uuidv7(), device_id uuid NOT NULL REFERENCES device_management.devices(id),
    kind varchar(60) NOT NULL, payload jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX device_events_device ON device_management.device_events(device_id, id);
CREATE TABLE device_management.device_heartbeats (
    device_id uuid NOT NULL REFERENCES device_management.devices(id),
    received_at timestamptz NOT NULL DEFAULT now(), state jsonb NOT NULL DEFAULT '{}',
    PRIMARY KEY (device_id, received_at)
);
INSERT INTO core.modules(code,name,description,version,enabled) VALUES
    ('device_management','Device Management','Classroom computers, agents and managed Windows sessions','1.0.0',false)
    ON CONFLICT(code) DO NOTHING;
