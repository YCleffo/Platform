-- ============================================================================
-- 005 — Media Foundation
-- Reusable multimedia entities: MediaAsset, MediaVariant, MediaUploadSession.
-- Binary storage remains strictly in Storage/S3 via storage_key references.
-- ============================================================================

CREATE SCHEMA IF NOT EXISTS media;

CREATE TABLE IF NOT EXISTS media.assets (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL REFERENCES core.organizations(id),
    owner_person_id uuid NOT NULL REFERENCES core.persons(id),
    owner_account_id uuid NOT NULL REFERENCES core.accounts(id),
    kind varchar(32) NOT NULL CHECK (kind IN ('audio', 'voice', 'video', 'video_note', 'image', 'document')),
    original_name varchar(255) NOT NULL,
    original_mime varchar(128) NOT NULL,
    verified_mime varchar(128),
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    storage_key uuid NOT NULL UNIQUE,
    duration_seconds numeric(8,2),
    width integer,
    height integer,
    codec varchar(64),
    status varchar(32) NOT NULL DEFAULT 'RESERVED' CHECK (status IN ('RESERVED', 'UPLOADING', 'UPLOADED', 'PROCESSING', 'READY', 'FAILED', 'QUARANTINED', 'EXPIRED', 'CANCELLED')),
    failure_code varchar(64),
    metadata jsonb NOT NULL DEFAULT '{}',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS media_assets_owner ON media.assets(organization_id, owner_person_id, created_at DESC);
CREATE INDEX IF NOT EXISTS media_assets_status ON media.assets(status, created_at);

CREATE TABLE IF NOT EXISTS media.variants (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    asset_id uuid NOT NULL REFERENCES media.assets(id) ON DELETE CASCADE,
    variant_key varchar(64) NOT NULL,
    storage_key uuid NOT NULL UNIQUE,
    mime_type varchar(128) NOT NULL,
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    width integer,
    height integer,
    metadata jsonb NOT NULL DEFAULT '{}',
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(asset_id, variant_key)
);

CREATE INDEX IF NOT EXISTS media_variants_asset ON media.variants(asset_id);

CREATE TABLE IF NOT EXISTS media.upload_sessions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    asset_id uuid NOT NULL REFERENCES media.assets(id) ON DELETE CASCADE,
    staging_key varchar(255) NOT NULL UNIQUE,
    token_hash char(64) NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS media_upload_sessions_expiry ON media.upload_sessions(expires_at);

INSERT INTO core.modules(code, name, description, version, enabled) VALUES
    ('media', 'Media Foundation', 'Reusable media assets, processing pipelines, voice and video notes', '1.0.0', false)
    ON CONFLICT(code) DO NOTHING;

GRANT USAGE ON SCHEMA media TO PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA media TO PUBLIC;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA media TO PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA media GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO PUBLIC;

