-- Durable transfers reserve quota before issuing S3 permissions. PostgreSQL
-- remains the source of business versions and all committed object references.
ALTER TABLE storage.versions DROP CONSTRAINT versions_size_bytes_check;
ALTER TABLE storage.versions ADD CONSTRAINT versions_size_bytes_check CHECK(size_bytes BETWEEN 0 AND 5497558138880);
ALTER TABLE storage.tickets DROP CONSTRAINT tickets_expected_size_check;
ALTER TABLE storage.tickets ADD CONSTRAINT tickets_expected_size_check CHECK(expected_size IS NULL OR expected_size BETWEEN 0 AND 5497558138880);
ALTER TABLE storage.manifest_entries DROP CONSTRAINT manifest_entries_size_bytes_check;
ALTER TABLE storage.manifest_entries ADD CONSTRAINT manifest_entries_size_bytes_check CHECK(size_bytes IS NULL OR size_bytes BETWEEN 0 AND 5497558138880);
ALTER TABLE storage.tickets ADD COLUMN actor_account_id uuid;

CREATE TABLE storage.transfers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    ticket_id uuid NOT NULL UNIQUE REFERENCES storage.tickets(id) ON DELETE CASCADE,
    space_id uuid NOT NULL REFERENCES storage.spaces(id),
    actor_account_id uuid, device_id uuid,
    direction varchar(8) NOT NULL CHECK(direction IN ('upload','download')),
    path varchar(1024) NOT NULL,
    status varchar(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','uploading','verifying','confirmed','aborted','expired')),
    mode varchar(12) NOT NULL CHECK(mode IN ('single','multipart')),
    expected_size bigint NOT NULL CHECK(expected_size BETWEEN 0 AND 5497558138880),
    expected_sha256 char(64) NOT NULL CHECK(expected_sha256 ~ '^[0-9a-f]{64}$'),
    staging_key varchar(1024) NOT NULL UNIQUE,
    final_storage_key uuid NOT NULL UNIQUE,
    multipart_upload_id text,
    part_size bigint CHECK(part_size BETWEEN 5242880 AND 5368709120),
    total_parts integer CHECK(total_parts BETWEEN 1 AND 10000),
    reserved_bytes bigint NOT NULL DEFAULT 0 CHECK(reserved_bytes >= 0),
    reserved_files integer NOT NULL DEFAULT 0 CHECK(reserved_files BETWEEN 0 AND 1),
    result jsonb, verified_at timestamptz,
    expires_at timestamptz NOT NULL DEFAULT now()+interval '24 hours',
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK((device_id IS NULL) <> (actor_account_id IS NULL)),
    CHECK((mode='single' AND part_size IS NULL AND total_parts IS NULL)
        OR (mode='multipart' AND part_size IS NOT NULL AND total_parts IS NOT NULL)),
    CHECK(status<>'confirmed' OR result IS NOT NULL)
);
CREATE INDEX storage_transfer_reservations ON storage.transfers(space_id,expires_at)
    WHERE direction='upload' AND status IN ('pending','uploading','verifying');
CREATE INDEX storage_transfer_reconciliation ON storage.transfers(status,updated_at);

CREATE TABLE storage.transfer_parts (
    transfer_id uuid NOT NULL REFERENCES storage.transfers(id) ON DELETE CASCADE,
    part_number integer NOT NULL CHECK(part_number BETWEEN 1 AND 10000),
    sha256 char(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'),
    size_bytes bigint NOT NULL CHECK(size_bytes BETWEEN 1 AND 5368709120),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(transfer_id,part_number)
);

-- Staging objects and failed publications are reconciled after committed
-- metadata has established that they have no active references.
CREATE TABLE storage.object_cleanup (
    object_key varchar(1024) PRIMARY KEY,
    multipart_upload_id text,
    transfer_id uuid,
    queued_at timestamptz NOT NULL DEFAULT now(),
    not_before timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz,
    attempts integer NOT NULL DEFAULT 0,
    last_error varchar(160)
);
CREATE INDEX storage_object_cleanup_pending ON storage.object_cleanup(not_before,queued_at)
    WHERE completed_at IS NULL;

-- Verified migration ledger records both modules without replacing file IDs.
CREATE TABLE storage.object_migrations (
    module varchar(16) NOT NULL CHECK(module IN ('storage','education')),
    storage_key uuid NOT NULL,
    size_bytes bigint NOT NULL CHECK(size_bytes >= 0),
    sha256 char(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'),
    source_fingerprint char(64) NOT NULL,
    verified_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(module,storage_key)
);
