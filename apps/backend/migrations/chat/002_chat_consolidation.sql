-- Migration 002: Unified Chat Consolidation & Performance Indexes (PLATFORM_12 / GATE 04)
-- Idempotently backfills any historical education.* messaging data into canonical chat.* tables
-- preserving UUIDs and read cursors, and adds composite/partial indexes for high-concurrency queries.

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
            room_id, organization_id, person_id, role, can_write, last_read_at, last_read_message_id, joined_at
        )
        SELECT
            tm.thread_id,
            tm.organization_id,
            tm.person_id,
            'member',
            true,
            tm.last_read_at,
            tm.last_read_message_id,
            COALESCE(t.created_at, now())
        FROM education.thread_members tm
        JOIN education.threads t ON t.id = tm.thread_id AND t.organization_id = tm.organization_id
        JOIN chat.rooms r ON r.id = tm.thread_id AND r.organization_id = tm.organization_id
        ON CONFLICT (room_id, person_id) DO UPDATE SET
            last_read_at = CASE
                WHEN EXCLUDED.last_read_at IS NULL THEN chat.members.last_read_at
                WHEN chat.members.last_read_at IS NULL THEN EXCLUDED.last_read_at
                ELSE GREATEST(chat.members.last_read_at, EXCLUDED.last_read_at)
            END,
            last_read_message_id = CASE
                WHEN EXCLUDED.last_read_at IS NOT NULL
                    AND (chat.members.last_read_at IS NULL OR EXCLUDED.last_read_at > chat.members.last_read_at)
                    AND EXCLUDED.last_read_message_id IS NOT NULL
                THEN EXCLUDED.last_read_message_id
                ELSE COALESCE(chat.members.last_read_message_id, EXCLUDED.last_read_message_id)
            END;

        INSERT INTO chat.messages (
            id, room_id, organization_id, sender_person_id, kind, body, media_asset_id,
            reply_to_id, metadata, client_key, created_at, updated_at
        )
        SELECT
            m.id,
            m.thread_id,
            m.organization_id,
            m.sender_person_id,
            COALESCE(NULLIF(m.kind, ''), 'text'),
            m.body,
            m.media_asset_id,
            NULL,
            CASE WHEN m.metadata IS NOT NULL AND jsonb_typeof(m.metadata) = 'object' THEN m.metadata ELSE '{}'::jsonb END,
            m.client_key,
            m.created_at,
            m.created_at
        FROM education.messages m
        WHERE EXISTS (SELECT 1 FROM chat.rooms r WHERE r.id = m.thread_id AND r.organization_id = m.organization_id)
          AND (
              (m.body IS NOT NULL AND length(m.body) BETWEEN 1 AND 10000)
              OR m.media_asset_id IS NOT NULL
              OR COALESCE(NULLIF(m.kind, ''), 'text') IN ('activity_card', 'assessment_card', 'homework_card', 'system', 'poll')
          )
        ORDER BY m.created_at ASC, m.id ASC
        ON CONFLICT (id) DO NOTHING;

        UPDATE chat.messages cm
        SET reply_to_id = m.reply_to_id
        FROM education.messages m
        WHERE cm.id = m.id
          AND cm.reply_to_id IS NULL
          AND m.reply_to_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM chat.messages rm WHERE rm.id = m.reply_to_id);

        UPDATE chat.rooms cr
        SET
            last_message_id = COALESCE(cr.last_message_id, latest.id),
            last_activity_at = GREATEST(cr.last_activity_at, COALESCE(latest.created_at, cr.last_activity_at))
        FROM (
            SELECT DISTINCT ON (cm.room_id) cm.room_id, cm.id, cm.created_at
            FROM chat.messages cm
            WHERE cm.deleted_at IS NULL
            ORDER BY cm.room_id, cm.created_at DESC, cm.id DESC
        ) latest
        WHERE latest.room_id = cr.id;

        IF to_regclass('education.message_reactions') IS NOT NULL THEN
            INSERT INTO chat.reactions (id, message_id, organization_id, person_id, emoji, created_at)
            SELECT mr.id, mr.message_id, mr.organization_id, mr.person_id, mr.emoji, mr.created_at
            FROM education.message_reactions mr
            WHERE EXISTS (SELECT 1 FROM chat.messages cm WHERE cm.id = mr.message_id)
            ON CONFLICT DO NOTHING;
        END IF;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS chat_messages_room_active_time_idx
    ON chat.messages (room_id, created_at DESC, id DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS chat_messages_room_seq_idx
    ON chat.messages (room_id, seq DESC);

CREATE INDEX IF NOT EXISTS chat_members_person_org_active_idx
    ON chat.members (person_id, organization_id)
    WHERE left_at IS NULL;

CREATE INDEX IF NOT EXISTS chat_members_room_active_idx
    ON chat.members (room_id, organization_id, person_id)
    WHERE left_at IS NULL;

CREATE INDEX IF NOT EXISTS chat_delivery_receipts_msg_person_idx
    ON chat.delivery_receipts (message_id, person_id);

CREATE INDEX IF NOT EXISTS chat_reactions_message_idx
    ON chat.reactions (message_id);

DO $$
BEGIN
    IF to_regclass('notifications.items') IS NOT NULL THEN
        CREATE INDEX IF NOT EXISTS notifications_items_org_person_read_idx
            ON notifications.items (organization_id, person_id, read_at)
            WHERE archived_at IS NULL;
    END IF;
END $$;
