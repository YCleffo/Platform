-- Migration 001: Universal Chat Foundation (PLATFORM_10)
-- Independent platform module schema `chat`, decoupled from Education.
-- Safely migrates existing conversations from `education.threads`/`messages`/`message_reactions` preserving UUIDs & timestamps.

CREATE SCHEMA IF NOT EXISTS chat;

CREATE TABLE IF NOT EXISTS chat.module_migrations (
    name text PRIMARY KEY,
    sha256 char(64) NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS chat.rooms (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    room_type varchar(24) NOT NULL DEFAULT 'direct' CHECK (room_type IN ('direct', 'group', 'organization', 'support', 'context')),
    title varchar(180) NOT NULL CHECK (btrim(title) <> ''),
    description text NOT NULL DEFAULT '',
    context_type varchar(40),
    context_id uuid,
    created_by_person_id uuid,
    created_by_account_id uuid,
    last_message_id uuid,
    pinned_message_id uuid,
    last_activity_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    settings jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id, organization_id)
);

CREATE INDEX IF NOT EXISTS chat_rooms_org_activity_idx
    ON chat.rooms (organization_id, last_activity_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS chat_rooms_context_idx
    ON chat.rooms (organization_id, context_type, context_id)
    WHERE context_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS chat.members (
    room_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    person_id uuid NOT NULL,
    account_id uuid,
    role varchar(24) NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'admin', 'moderator', 'member', 'guest', 'readonly')),
    can_write boolean NOT NULL DEFAULT true,
    last_read_at timestamptz,
    last_read_message_id uuid,
    muted_until timestamptz,
    archived_at timestamptz,
    pinned_order integer,
    draft_text text,
    draft_reply_to_id uuid,
    draft_updated_at timestamptz,
    joined_at timestamptz NOT NULL DEFAULT now(),
    left_at timestamptz,
    PRIMARY KEY (room_id, person_id),
    FOREIGN KEY (room_id, organization_id) REFERENCES chat.rooms(id, organization_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS chat_members_person_idx
    ON chat.members (person_id, organization_id, room_id)
    WHERE left_at IS NULL;

CREATE TABLE IF NOT EXISTS chat.messages (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    room_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    sender_person_id uuid NOT NULL,
    sender_account_id uuid,
    seq bigserial NOT NULL,
    kind varchar(32) NOT NULL DEFAULT 'text',
    body text,
    media_asset_id uuid,
    reply_to_id uuid REFERENCES chat.messages(id) ON DELETE SET NULL,
    forwarded_from_message_id uuid,
    forwarded_from_room_id uuid,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    client_key varchar(128),
    edited_at timestamptz,
    deleted_at timestamptz,
    deleted_for_all boolean NOT NULL DEFAULT false,
    pinned_at timestamptz,
    pinned_by_person_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (room_id, organization_id) REFERENCES chat.rooms(id, organization_id) ON DELETE CASCADE,
    CONSTRAINT chat_messages_content_check CHECK (
        deleted_at IS NOT NULL
        OR (body IS NOT NULL AND length(body) BETWEEN 1 AND 10000)
        OR media_asset_id IS NOT NULL
        OR kind IN ('activity_card', 'assessment_card', 'homework_card', 'system', 'poll')
    )
);

CREATE INDEX IF NOT EXISTS chat_messages_room_time_idx
    ON chat.messages (room_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS chat_messages_room_seq_idx
    ON chat.messages (room_id, seq DESC);
CREATE UNIQUE INDEX IF NOT EXISTS chat_messages_idempotency_idx
    ON chat.messages (room_id, sender_person_id, client_key)
    WHERE client_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS chat_messages_media_idx
    ON chat.messages (media_asset_id)
    WHERE media_asset_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS chat_messages_fts_idx
    ON chat.messages USING gin (to_tsvector('simple', COALESCE(body, '')));

CREATE TABLE IF NOT EXISTS chat.message_edits (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    message_id uuid NOT NULL REFERENCES chat.messages(id) ON DELETE CASCADE,
    editor_person_id uuid NOT NULL,
    previous_body text,
    new_body text,
    edited_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS chat_message_edits_msg_idx
    ON chat.message_edits (message_id, edited_at DESC);

CREATE TABLE IF NOT EXISTS chat.message_deletions (
    message_id uuid NOT NULL REFERENCES chat.messages(id) ON DELETE CASCADE,
    person_id uuid NOT NULL,
    deleted_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (message_id, person_id)
);

CREATE TABLE IF NOT EXISTS chat.reactions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    message_id uuid NOT NULL REFERENCES chat.messages(id) ON DELETE CASCADE,
    organization_id uuid NOT NULL,
    person_id uuid NOT NULL,
    emoji varchar(32) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_chat_reaction_person_emoji UNIQUE (message_id, person_id, emoji)
);

CREATE INDEX IF NOT EXISTS chat_reactions_message_idx ON chat.reactions (message_id);
CREATE INDEX IF NOT EXISTS chat_reactions_person_idx ON chat.reactions (organization_id, person_id);

CREATE TABLE IF NOT EXISTS chat.delivery_receipts (
    message_id uuid NOT NULL REFERENCES chat.messages(id) ON DELETE CASCADE,
    person_id uuid NOT NULL,
    status varchar(16) NOT NULL CHECK (status IN ('delivered', 'read')),
    delivered_at timestamptz,
    read_at timestamptz,
    PRIMARY KEY (message_id, person_id)
);

CREATE INDEX IF NOT EXISTS chat_receipts_person_idx ON chat.delivery_receipts (person_id, read_at);

-- Idempotent data migration from education.* tables if present
DO $$
BEGIN
    IF to_regclass('education.threads') IS NOT NULL THEN
        INSERT INTO chat.rooms (
            id, organization_id, room_type, title, context_type, context_id, last_activity_at, created_at, updated_at
        )
        SELECT
            t.id,
            t.organization_id,
            CASE
                WHEN t.context_type IS NOT NULL THEN 'context'
                WHEN (SELECT count(*) FROM education.thread_members tm WHERE tm.thread_id = t.id) > 2 THEN 'group'
                ELSE 'direct'
            END,
            t.subject,
            t.context_type,
            t.context_id,
            COALESCE(t.last_activity_at, t.created_at),
            t.created_at,
            COALESCE(t.last_activity_at, t.created_at)
        FROM education.threads t
        ON CONFLICT (id) DO NOTHING;

        INSERT INTO chat.members (
            room_id, organization_id, person_id, role, last_read_at, last_read_message_id, joined_at
        )
        SELECT
            tm.thread_id,
            tm.organization_id,
            tm.person_id,
            'member',
            tm.last_read_at,
            tm.last_read_message_id,
            now()
        FROM education.thread_members tm
        WHERE EXISTS (SELECT 1 FROM chat.rooms r WHERE r.id = tm.thread_id AND r.organization_id = tm.organization_id)
        ON CONFLICT (room_id, person_id) DO NOTHING;

        INSERT INTO chat.messages (
            id, room_id, organization_id, sender_person_id, kind, body, media_asset_id, reply_to_id, metadata, client_key, created_at, updated_at
        )
        SELECT
            m.id,
            m.thread_id,
            m.organization_id,
            m.sender_person_id,
            COALESCE(m.kind, 'text'),
            m.body,
            m.media_asset_id,
            NULL,
            CASE WHEN m.metadata IS NOT NULL AND jsonb_typeof(m.metadata) = 'object' THEN m.metadata ELSE '{}'::jsonb END,
            m.client_key,
            m.created_at,
            m.created_at
        FROM education.messages m
        WHERE EXISTS (SELECT 1 FROM chat.rooms r WHERE r.id = m.thread_id AND r.organization_id = m.organization_id)
        ORDER BY m.created_at ASC, m.id ASC
        ON CONFLICT (id) DO NOTHING;

        UPDATE chat.messages cm
        SET reply_to_id = m.reply_to_id
        FROM education.messages m
        WHERE cm.id = m.id
          AND m.reply_to_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM chat.messages rm WHERE rm.id = m.reply_to_id);

        UPDATE chat.rooms cr
        SET last_message_id = (
            SELECT cm.id FROM chat.messages cm
            WHERE cm.room_id = cr.id AND cm.deleted_at IS NULL
            ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1
        )
        WHERE cr.last_message_id IS NULL;

        IF to_regclass('education.message_reactions') IS NOT NULL THEN
            INSERT INTO chat.reactions (id, message_id, organization_id, person_id, emoji, created_at)
            SELECT mr.id, mr.message_id, mr.organization_id, mr.person_id, mr.emoji, mr.created_at
            FROM education.message_reactions mr
            WHERE EXISTS (SELECT 1 FROM chat.messages cm WHERE cm.id = mr.message_id)
            ON CONFLICT (message_id, person_id, emoji) DO NOTHING;
        END IF;
    END IF;
END $$;

INSERT INTO core.modules(code, name, description, version, enabled)
VALUES ('chat', 'Universal Chat', 'Platform-wide direct, group, organization and contextual messaging', '1.0.0', true)
ON CONFLICT(code) DO UPDATE SET enabled = true;

INSERT INTO core.permissions(module_id, code, name, description, is_system)
SELECT m.id, p.code, p.name, p.description, true
FROM core.modules m
CROSS JOIN (VALUES
    ('chat.use', 'Use Universal Chat', 'Send messages, react, read rooms and drafts'),
    ('chat.rooms.direct', 'Create direct chats', 'Start direct 1:1 conversations'),
    ('chat.rooms.group', 'Create group chats', 'Create and manage multi-member group rooms'),
    ('chat.rooms.organization', 'Create organization channels', 'Create org-wide announcement/team rooms'),
    ('chat.manage', 'Manage chat rooms', 'Moderate, pin, archive and administer org rooms')
) AS p(code, name, description)
WHERE m.code = 'chat'
ON CONFLICT(code) DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('chat.use', 'chat.rooms.direct', 'chat.rooms.group')
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('chat.rooms.organization', 'chat.manage')
WHERE r.code IN ('education.teacher', 'core.admin')
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA chat TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA chat TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA chat TO platform_core_runtime;
    END IF;
END $$;
