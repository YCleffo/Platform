-- Migration 002: Transactional Outbox Lease Locking, Backoff & Dead-Lettering (PLATFORM_12 / GATE 06)

ALTER TABLE realtime.outbox_events
    ADD COLUMN IF NOT EXISTS locked_until timestamptz,
    ADD COLUMN IF NOT EXISTS next_retry_at timestamptz,
    ADD COLUMN IF NOT EXISTS max_attempts integer NOT NULL DEFAULT 12,
    ADD COLUMN IF NOT EXISTS dead_lettered_at timestamptz;

DROP INDEX IF EXISTS realtime.realtime_outbox_pending_idx;

CREATE INDEX IF NOT EXISTS realtime_outbox_pending_idx
    ON realtime.outbox_events (seq ASC)
    WHERE dispatched_at IS NULL AND dead_lettered_at IS NULL;

CREATE INDEX IF NOT EXISTS realtime_outbox_org_channel_seq_idx
    ON realtime.outbox_events (organization_id, channel, seq ASC);
