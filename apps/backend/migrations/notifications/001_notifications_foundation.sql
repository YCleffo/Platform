-- Migration 001: Universal Notifications Center (PLATFORM_10)
-- Migrates existing `education.notifications` preserving exact UUIDs & read state.

CREATE SCHEMA IF NOT EXISTS notifications;

CREATE TABLE IF NOT EXISTS notifications.module_migrations (
    name text PRIMARY KEY,
    sha256 char(64) NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS notifications.items (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    person_id uuid NOT NULL,
    account_id uuid,
    category varchar(40) NOT NULL DEFAULT 'general'
        CHECK (category IN ('message', 'homework', 'assessment', 'activity', 'schedule', 'family', 'security', 'system', 'general')),
    kind varchar(80) NOT NULL DEFAULT 'message',
    title varchar(255) NOT NULL DEFAULT '',
    body text NOT NULL DEFAULT '',
    room_id uuid,
    message_id uuid,
    resource_type varchar(60),
    resource_id uuid,
    resource_path varchar(500),
    event_key varchar(240),
    parent_student_id uuid,
    priority varchar(16) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    read_at timestamptz,
    archived_at timestamptz
);

CREATE INDEX IF NOT EXISTS notifications_recipient_idx
    ON notifications.items (organization_id, person_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS notifications_unread_idx
    ON notifications.items (person_id, organization_id, read_at)
    WHERE read_at IS NULL AND archived_at IS NULL;
CREATE INDEX IF NOT EXISTS notifications_room_idx
    ON notifications.items (room_id, message_id)
    WHERE room_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS notifications_event_key_idx
    ON notifications.items (organization_id, person_id, event_key)
    WHERE event_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS notifications.preferences (
    account_id uuid NOT NULL,
    organization_id uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000'::uuid,
    category varchar(40) NOT NULL,
    in_app boolean NOT NULL DEFAULT true,
    realtime boolean NOT NULL DEFAULT true,
    web_push boolean NOT NULL DEFAULT true,
    email boolean NOT NULL DEFAULT false,
    sound boolean NOT NULL DEFAULT true,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (account_id, organization_id, category)
);

CREATE TABLE IF NOT EXISTS notifications.push_subscriptions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,
    person_id uuid NOT NULL,
    organization_id uuid,
    endpoint text NOT NULL,
    p256dh text NOT NULL DEFAULT '',
    auth_secret text NOT NULL DEFAULT '',
    user_agent text,
    device_label varchar(120),
    created_at timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz,
    revoked_at timestamptz,
    UNIQUE (account_id, endpoint)
);

DO $$
BEGIN
    IF to_regclass('education.notifications') IS NOT NULL THEN
        INSERT INTO notifications.items (
            id, organization_id, person_id, category, kind, title, body,
            room_id, message_id, resource_type, resource_id, resource_path,
            event_key, parent_student_id, created_at, read_at
        )
        SELECT
            n.id,
            n.organization_id,
            n.person_id,
            CASE
                WHEN n.kind LIKE '%homework%' THEN 'homework'
                WHEN n.kind LIKE '%assessment%' THEN 'assessment'
                WHEN n.kind LIKE '%activity%' OR n.kind LIKE '%achievement%' THEN 'activity'
                WHEN n.kind LIKE '%schedule%' OR n.kind LIKE '%lesson%' THEN 'schedule'
                WHEN n.kind LIKE '%family%' THEN 'family'
                ELSE 'message'
            END,
            COALESCE(n.kind, 'message'),
            COALESCE(n.subject, t.subject, ''),
            COALESCE(n.body, ''),
            n.thread_id,
            n.message_id,
            CASE
                WHEN n.kind LIKE '%homework%' THEN 'homework'
                WHEN n.kind LIKE '%assessment%' THEN 'assessment'
                WHEN n.kind LIKE '%activity%' THEN 'activity'
                WHEN n.thread_id IS NOT NULL THEN 'chat_room'
                ELSE NULL
            END,
            n.resource_id,
            n.resource_path,
            n.event_key,
            n.parent_student_id,
            n.created_at,
            n.read_at
        FROM education.notifications n
        LEFT JOIN education.threads t ON t.id = n.thread_id
        ON CONFLICT (id) DO NOTHING;
    END IF;
END $$;

INSERT INTO core.modules(code, name, description, version, enabled)
VALUES ('notifications', 'Universal Notifications', 'Cross-module notifications center, preferences and push subscriptions', '1.0.0', true)
ON CONFLICT(code) DO UPDATE SET enabled = true;

INSERT INTO core.permissions(module_id, code, name, description, is_system)
SELECT m.id, p.code, p.name, p.description, true
FROM core.modules m
CROSS JOIN (VALUES
    ('notifications.use', 'Use Notifications Center', 'Read notifications and update own delivery preferences'),
    ('notifications.manage', 'Manage Notifications', 'Administer notification templates and policies')
) AS p(code, name, description)
WHERE m.code = 'notifications'
ON CONFLICT(code) DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code = 'notifications.use'
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA notifications TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA notifications TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA notifications TO platform_core_runtime;
    END IF;
END $$;
