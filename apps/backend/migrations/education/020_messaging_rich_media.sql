-- Migration 020: Rich Media Messaging and Reactions
-- Adds media attachment, message kinds, reply threading, and reactions to education messages.

ALTER TABLE education.messages
    ADD COLUMN IF NOT EXISTS kind varchar(20) NOT NULL DEFAULT 'text',
    ADD COLUMN IF NOT EXISTS media_asset_id uuid DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reply_to_id uuid DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS metadata jsonb NOT NULL DEFAULT '{}'::jsonb;

-- Allow body to be NULL when a media asset is attached (e.g. voice message, video note, document)
ALTER TABLE education.messages
    ALTER COLUMN body DROP NOT NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = 'education'
          AND table_name = 'messages'
          AND constraint_name = 'messages_body_check'
    ) THEN
        ALTER TABLE education.messages DROP CONSTRAINT messages_body_check;
    END IF;
END $$;

ALTER TABLE education.messages
    ADD CONSTRAINT messages_body_or_media_check
    CHECK ((body IS NOT NULL AND length(body) BETWEEN 1 AND 5000) OR media_asset_id IS NOT NULL);

-- Index for reply lookup
CREATE INDEX IF NOT EXISTS messages_reply_to_idx
    ON education.messages (reply_to_id)
    WHERE reply_to_id IS NOT NULL;

-- Index for media lookup
CREATE INDEX IF NOT EXISTS messages_media_asset_idx
    ON education.messages (media_asset_id)
    WHERE media_asset_id IS NOT NULL;

-- Message reactions table
CREATE TABLE IF NOT EXISTS education.message_reactions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    message_id uuid NOT NULL REFERENCES education.messages(id) ON DELETE CASCADE,
    organization_id uuid NOT NULL,
    person_id uuid NOT NULL,
    emoji varchar(16) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_message_person_emoji UNIQUE (message_id, person_id, emoji)
);

CREATE INDEX IF NOT EXISTS message_reactions_message_idx
    ON education.message_reactions (message_id);

CREATE INDEX IF NOT EXISTS message_reactions_person_idx
    ON education.message_reactions (organization_id, person_id);
