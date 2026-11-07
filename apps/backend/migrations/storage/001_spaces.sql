-- ============================================================================
-- 001 — Storage foundation
-- Personal student document spaces with versions, manifests, sync sessions,
-- short-lived transfer tickets and conflict records. Binary payloads live
-- outside PostgreSQL in the configured file adapter; only metadata is stored
-- here. student_id is an application port reference to education.students.
-- ============================================================================
CREATE TABLE storage.spaces (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    student_id uuid NOT NULL, account_id uuid NOT NULL,
    max_files integer NOT NULL DEFAULT 5000 CHECK (max_files BETWEEN 1 AND 100000),
    max_bytes bigint NOT NULL DEFAULT 1073741824 CHECK (max_bytes BETWEEN 1 AND 1099511627776),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, student_id)
);
CREATE TABLE storage.files (
    id uuid PRIMARY KEY DEFAULT uuidv7(), space_id uuid NOT NULL REFERENCES storage.spaces(id),
    path varchar(1024) NOT NULL CHECK (btrim(path) <> '' AND path NOT LIKE '%..%'),
    current_version_id uuid, deleted_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (space_id, path)
);
CREATE INDEX files_space ON storage.files(space_id, deleted_at, path);
CREATE TABLE storage.versions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), file_id uuid NOT NULL REFERENCES storage.files(id),
    version integer NOT NULL CHECK (version > 0), size_bytes bigint NOT NULL CHECK (size_bytes BETWEEN 0 AND 1073741824),
    sha256 char(64) NOT NULL, storage_key uuid NOT NULL UNIQUE, created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (file_id, version)
);
CREATE INDEX versions_file ON storage.versions(file_id, version);
CREATE TABLE storage.manifests (
    id uuid PRIMARY KEY DEFAULT uuidv7(), space_id uuid NOT NULL REFERENCES storage.spaces(id),
    version integer NOT NULL CHECK (version > 0), entries jsonb NOT NULL DEFAULT '[]',
    status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','confirmed')),
    device_id uuid, created_at timestamptz NOT NULL DEFAULT now(), confirmed_at timestamptz,
    UNIQUE (space_id, version)
);
CREATE TABLE storage.sync_sessions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), space_id uuid NOT NULL REFERENCES storage.spaces(id),
    device_id uuid NOT NULL, direction varchar(8) NOT NULL CHECK (direction IN ('in','out')),
    status varchar(16) NOT NULL DEFAULT 'running' CHECK (status IN ('running','done','failed')),
    created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz
);
CREATE TABLE storage.tickets (
    id uuid PRIMARY KEY DEFAULT uuidv7(), space_id uuid NOT NULL REFERENCES storage.spaces(id),
    purpose varchar(8) NOT NULL CHECK (purpose IN ('upload','download')),
    path varchar(1024) NOT NULL CHECK (btrim(path) <> '' AND path NOT LIKE '%..%'),
    token_hash char(64) NOT NULL UNIQUE, single_use boolean NOT NULL DEFAULT true,
    used_at timestamptz, expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tickets_space ON storage.tickets(space_id, created_at);
CREATE TABLE storage.conflicts (
    id uuid PRIMARY KEY DEFAULT uuidv7(), space_id uuid NOT NULL REFERENCES storage.spaces(id),
    path varchar(1024) NOT NULL, server_version integer, device_version integer,
    server_sha256 char(64), device_sha256 char(64), resolution varchar(16),
    created_at timestamptz NOT NULL DEFAULT now(), resolved_at timestamptz
);
INSERT INTO core.modules(code,name,description,version,enabled) VALUES
    ('storage','Student Storage','Personal student documents, versions and sync','1.0.0',false)
    ON CONFLICT(code) DO NOTHING;
