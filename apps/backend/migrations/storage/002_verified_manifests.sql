-- ============================================================================
-- 002 — Verified manifests, bound transfer tickets and privacy receipts
-- A manifest is pending until every expected upload exists with the recorded
-- checksum and size. Deletions are applied only at confirmation and keep the
-- version history. Tickets carry their purpose, expected content and, for
-- downloads, the exact immutable version they may serve.
-- ============================================================================
ALTER TABLE storage.manifests ADD COLUMN entries_sha256 char(64);
ALTER TABLE storage.manifests ADD COLUMN base_version integer;
ALTER TABLE storage.manifests ADD COLUMN snapshot jsonb;
ALTER TABLE storage.manifests ADD COLUMN snapshot_sha256 char(64);
ALTER TABLE storage.files ADD COLUMN deleted_by_manifest_id uuid;
ALTER TABLE storage.tickets ADD COLUMN expected_sha256 char(64);
ALTER TABLE storage.tickets ADD COLUMN expected_size bigint
    CHECK (expected_size IS NULL OR expected_size BETWEEN 0 AND 1073741824);
ALTER TABLE storage.tickets ADD COLUMN file_version_id uuid REFERENCES storage.versions(id);
ALTER TABLE storage.tickets ADD COLUMN device_id uuid;

CREATE TABLE storage.manifest_entries (
    manifest_id uuid NOT NULL REFERENCES storage.manifests(id) ON DELETE CASCADE,
    path varchar(1024) NOT NULL CHECK (btrim(path) <> '' AND path NOT LIKE '%..%'),
    operation varchar(8) NOT NULL CHECK (operation IN ('add','update','delete')),
    sha256 char(64), size_bytes bigint CHECK (size_bytes IS NULL OR size_bytes BETWEEN 0 AND 1073741824),
    base_sha256 char(64),
    state varchar(16) NOT NULL DEFAULT 'expected'
        CHECK (state IN ('expected','verified','deleted','skipped','conflict')),
    ticket_id uuid REFERENCES storage.tickets(id),
    verified_sha256 char(64), verified_size bigint,
    verified_version_id uuid REFERENCES storage.versions(id),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (manifest_id, path)
);
CREATE INDEX manifest_entries_state ON storage.manifest_entries(manifest_id, state);
CREATE INDEX manifests_confirmed ON storage.manifests(space_id, version DESC) WHERE status='confirmed';

CREATE TABLE storage.privacy_receipts (
    item_id uuid PRIMARY KEY, result varchar(32) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
