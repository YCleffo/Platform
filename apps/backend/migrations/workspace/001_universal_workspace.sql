-- Migration 001: Universal Workspace (PLATFORM_12)
-- Provides workspace preferences, universal action/undo operations, universal trash,
-- privacy erasure receipts, trigram/FTS indexes, and workspace module permissions.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE SCHEMA IF NOT EXISTS workspace;

CREATE TABLE IF NOT EXISTS workspace.module_migrations (
    name text PRIMARY KEY,
    sha256 char(64) NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS workspace.preferences (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,
    organization_id uuid,
    key varchar(120) NOT NULL CHECK (btrim(key) <> ''),
    value jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE NULLS NOT DISTINCT (account_id, organization_id, key)
);

CREATE INDEX IF NOT EXISTS workspace_preferences_account_idx
    ON workspace.preferences (account_id, organization_id, key);

CREATE TABLE IF NOT EXISTS workspace.operations (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    account_id uuid NOT NULL,
    person_id uuid,
    action_key varchar(120) NOT NULL CHECK (btrim(action_key) <> ''),
    entity_type varchar(80) NOT NULL DEFAULT '',
    entity_id uuid,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    mode varchar(24) NOT NULL CHECK (mode IN ('immediate', 'reversible', 'deferred', 'background', 'irreversible', 'confirmed')),
    status varchar(24) NOT NULL CHECK (status IN ('pending', 'running', 'undoable', 'committed', 'completed', 'undone', 'cancelled', 'failed')),
    undo_deadline timestamptz,
    execute_at timestamptz,
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    result jsonb,
    error_code varchar(80),
    error_message text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    finalized_at timestamptz
);

CREATE INDEX IF NOT EXISTS workspace_operations_actor_idx
    ON workspace.operations (organization_id, account_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS workspace_operations_undoable_idx
    ON workspace.operations (status, undo_deadline)
    WHERE status = 'undoable' AND finalized_at IS NULL;
CREATE INDEX IF NOT EXISTS workspace_operations_deferred_idx
    ON workspace.operations (status, execute_at)
    WHERE status = 'pending' AND finalized_at IS NULL;
CREATE INDEX IF NOT EXISTS workspace_operations_entity_idx
    ON workspace.operations (entity_type, entity_id)
    WHERE entity_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS workspace_operations_person_idx
    ON workspace.operations (person_id, created_at DESC)
    WHERE person_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS workspace.trash_items (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    deleted_by_account_id uuid NOT NULL,
    deleted_by_person_id uuid,
    entity_type varchar(80) NOT NULL CHECK (btrim(entity_type) <> ''),
    entity_id uuid NOT NULL,
    module_code varchar(60) NOT NULL CHECK (btrim(module_code) <> ''),
    title varchar(255) NOT NULL DEFAULT '',
    subtitle varchar(255) NOT NULL DEFAULT '',
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    restore_status varchar(24) NOT NULL DEFAULT 'trashed' CHECK (restore_status IN ('trashed', 'restored', 'purged')),
    expires_at timestamptz NOT NULL DEFAULT (now() + interval '30 days'),
    deleted_at timestamptz NOT NULL DEFAULT now(),
    restored_at timestamptz,
    purged_at timestamptz
);

CREATE UNIQUE INDEX IF NOT EXISTS workspace_trash_active_entity_idx
    ON workspace.trash_items (entity_type, entity_id)
    WHERE restore_status = 'trashed';
CREATE INDEX IF NOT EXISTS workspace_trash_org_list_idx
    ON workspace.trash_items (organization_id, restore_status, deleted_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS workspace_trash_expiry_idx
    ON workspace.trash_items (restore_status, expires_at)
    WHERE restore_status = 'trashed';
CREATE INDEX IF NOT EXISTS workspace_trash_actor_idx
    ON workspace.trash_items (deleted_by_account_id, deleted_by_person_id);

CREATE TABLE IF NOT EXISTS workspace.privacy_receipts (
    item_id uuid PRIMARY KEY,
    result varchar(30) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Soft-delete support columns for universal trash providers
ALTER TABLE media.assets ADD COLUMN IF NOT EXISTS deleted_at timestamptz;
ALTER TABLE activity.items ADD COLUMN IF NOT EXISTS deleted_at timestamptz;

DO $$
BEGIN
    IF to_regclass('education.homework_assignments') IS NOT NULL THEN
        ALTER TABLE education.homework_assignments ADD COLUMN IF NOT EXISTS deleted_at timestamptz;
    END IF;
END $$;

-- Trigram & Full-Text Search GIN indexes (RU + EN)
CREATE INDEX IF NOT EXISTS core_persons_search_trgm_idx
    ON core.persons USING gin (given_name gin_trgm_ops, family_name gin_trgm_ops, (COALESCE(display_name, '')) gin_trgm_ops);
CREATE INDEX IF NOT EXISTS core_persons_fts_ru_idx
    ON core.persons USING gin (to_tsvector('russian', COALESCE(display_name, '') || ' ' || given_name || ' ' || family_name));
CREATE INDEX IF NOT EXISTS core_persons_fts_simple_idx
    ON core.persons USING gin (to_tsvector('simple', COALESCE(display_name, '') || ' ' || given_name || ' ' || family_name));

CREATE INDEX IF NOT EXISTS core_organizations_search_trgm_idx
    ON core.organizations USING gin (name gin_trgm_ops, code gin_trgm_ops);

CREATE INDEX IF NOT EXISTS storage_files_path_trgm_idx
    ON storage.files USING gin (path gin_trgm_ops);

CREATE INDEX IF NOT EXISTS media_assets_name_trgm_idx
    ON media.assets USING gin (original_name gin_trgm_ops);

CREATE INDEX IF NOT EXISTS activity_items_search_trgm_idx
    ON activity.items USING gin (title gin_trgm_ops, description gin_trgm_ops);
CREATE INDEX IF NOT EXISTS activity_items_fts_ru_idx
    ON activity.items USING gin (to_tsvector('russian', COALESCE(title, '') || ' ' || COALESCE(description, '')));
CREATE INDEX IF NOT EXISTS activity_items_fts_simple_idx
    ON activity.items USING gin (to_tsvector('simple', COALESCE(title, '') || ' ' || COALESCE(description, '')));

CREATE INDEX IF NOT EXISTS chat_rooms_search_trgm_idx
    ON chat.rooms USING gin (title gin_trgm_ops, description gin_trgm_ops);
CREATE INDEX IF NOT EXISTS chat_rooms_fts_ru_idx
    ON chat.rooms USING gin (to_tsvector('russian', COALESCE(title, '') || ' ' || COALESCE(description, '')));

CREATE INDEX IF NOT EXISTS chat_messages_body_trgm_idx
    ON chat.messages USING gin ((COALESCE(body, '')) gin_trgm_ops);
CREATE INDEX IF NOT EXISTS chat_messages_fts_ru_idx
    ON chat.messages USING gin (to_tsvector('russian', COALESCE(body, '')));

DO $$
BEGIN
    IF to_regclass('education.groups') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS education_groups_name_trgm_idx
            ON education.groups USING gin (name gin_trgm_ops);
    END IF;
    IF to_regclass('education.lessons') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS education_lessons_title_trgm_idx
            ON education.lessons USING gin (title gin_trgm_ops, location gin_trgm_ops);
    END IF;
    IF to_regclass('education.homework_assignments') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS education_homework_search_trgm_idx
            ON education.homework_assignments USING gin (title gin_trgm_ops, description gin_trgm_ops);
        CREATE INDEX IF NOT EXISTS education_homework_fts_ru_idx
            ON education.homework_assignments USING gin (to_tsvector('russian', COALESCE(title, '') || ' ' || COALESCE(description, '')));
    END IF;
    IF to_regclass('education.activity_definitions') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS education_activity_defs_trgm_idx
            ON education.activity_definitions USING gin (name gin_trgm_ops, code gin_trgm_ops);
    END IF;
    IF to_regclass('device_management.devices') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS device_management_devices_trgm_idx
            ON device_management.devices USING gin (name gin_trgm_ops, number gin_trgm_ops);
    END IF;
END $$;

-- Module & Permissions registration
INSERT INTO core.modules(code, name, description, version, enabled)
VALUES ('workspace', 'Universal Workspace', 'Dashboard, universal search, action engine, undo and trash', '1.0.0', true)
ON CONFLICT(code) DO UPDATE SET enabled = true;

INSERT INTO core.permissions(module_id, code, name, description, is_system)
SELECT m.id, p.code, p.name, p.description, true
FROM core.modules m
CROSS JOIN (VALUES
    ('workspace.use', 'Use Universal Workspace', 'Access workspace dashboard, search, preferences, actions, undo and own trash'),
    ('workspace.manage', 'Manage Universal Workspace', 'Administer workspace operations and organization trash')
) AS p(code, name, description)
WHERE m.code = 'workspace'
ON CONFLICT(code) DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code = 'workspace.use'
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code = 'workspace.manage'
WHERE r.code = 'core.admin'
ON CONFLICT DO NOTHING;

-- Explicit grants matching Platform schema convention
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA workspace TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA workspace TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA workspace TO platform_core_runtime;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_maintenance') THEN
        GRANT USAGE, CREATE ON SCHEMA workspace TO platform_core_maintenance;
        GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA workspace TO platform_core_maintenance;
        GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA workspace TO platform_core_maintenance;
    END IF;
END $$;
