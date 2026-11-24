-- Migration 019: Message send idempotency
-- Adds an optional client-generated idempotency key to prevent duplicate messages on HTTP retry.
-- The unique index enforces at-most-once delivery per (thread, sender, client_key) triple.
-- client_key is NULL when the caller does not supply one (older API callers).

ALTER TABLE education.messages
    ADD COLUMN IF NOT EXISTS client_key varchar(128) DEFAULT NULL;

-- Partial unique index: only enforces uniqueness when client_key is supplied
CREATE UNIQUE INDEX IF NOT EXISTS messages_idempotency
    ON education.messages (thread_id, sender_person_id, client_key)
    WHERE client_key IS NOT NULL;
