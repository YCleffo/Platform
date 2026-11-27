-- Migration 002: Notifications Center Enhancements (PLATFORM_12 GATE 17)
-- Adds grouping key, category/resource indexes, and maintenance grants.

ALTER TABLE notifications.items
    ADD COLUMN IF NOT EXISTS group_key varchar(160);

CREATE INDEX IF NOT EXISTS notifications_category_list_idx
    ON notifications.items (organization_id, person_id, category, archived_at, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS notifications_resource_lookup_idx
    ON notifications.items (resource_type, resource_id)
    WHERE resource_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS notifications_group_key_idx
    ON notifications.items (organization_id, person_id, group_key, created_at DESC)
    WHERE group_key IS NOT NULL AND archived_at IS NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA notifications TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA notifications TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA notifications TO platform_core_runtime;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_maintenance') THEN
        GRANT USAGE, CREATE ON SCHEMA notifications TO platform_core_maintenance;
        GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA notifications TO platform_core_maintenance;
        GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA notifications TO platform_core_maintenance;
    END IF;
END $$;
