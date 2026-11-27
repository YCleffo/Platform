-- Migration 001: Universal Realtime Outbox & Subscription Audit (PLATFORM_10)

CREATE SCHEMA IF NOT EXISTS realtime;

CREATE TABLE IF NOT EXISTS realtime.module_migrations (
    name text PRIMARY KEY,
    sha256 char(64) NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS realtime.outbox_events (
    seq bigserial PRIMARY KEY,
    event_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    channel varchar(200) NOT NULL,
    event_type varchar(100) NOT NULL,
    aggregate_type varchar(64) NOT NULL DEFAULT '',
    aggregate_id varchar(128) NOT NULL DEFAULT '',
    actor_account_id uuid,
    actor_person_id uuid,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    target_person_ids jsonb CHECK (target_person_ids IS NULL OR jsonb_typeof(target_person_ids) = 'array'),
    created_at timestamptz NOT NULL DEFAULT now(),
    dispatched_at timestamptz,
    attempts integer NOT NULL DEFAULT 0,
    last_error text
);

CREATE INDEX IF NOT EXISTS realtime_outbox_channel_seq_idx
    ON realtime.outbox_events (channel, seq);
CREATE INDEX IF NOT EXISTS realtime_outbox_org_seq_idx
    ON realtime.outbox_events (organization_id, seq);
CREATE INDEX IF NOT EXISTS realtime_outbox_pending_idx
    ON realtime.outbox_events (seq)
    WHERE dispatched_at IS NULL;
CREATE INDEX IF NOT EXISTS realtime_outbox_created_idx
    ON realtime.outbox_events (created_at);

CREATE TABLE IF NOT EXISTS realtime.connections (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    account_id uuid NOT NULL,
    person_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    client_instance_id varchar(120),
    status varchar(24) NOT NULL DEFAULT 'connected' CHECK (status IN ('connected', 'disconnected')),
    last_seq bigint NOT NULL DEFAULT 0,
    connected_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    disconnected_at timestamptz
);

CREATE INDEX IF NOT EXISTS realtime_connections_person_idx
    ON realtime.connections (organization_id, person_id, status, last_seen_at DESC);

INSERT INTO core.modules(code, name, description, version, enabled)
VALUES ('realtime', 'Realtime Engine', 'WebSocket and Redis Pub/Sub event distribution with outbox replay', '1.0.0', true)
ON CONFLICT(code) DO UPDATE SET enabled = true;

INSERT INTO core.permissions(module_id, code, name, description, is_system)
SELECT m.id, 'realtime.connect', 'Connect to Realtime', 'Establish authenticated WebSocket sessions and subscribe to authorized channels', true
FROM core.modules m
WHERE m.code = 'realtime'
ON CONFLICT(code) DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code = 'realtime.connect'
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA realtime TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA realtime TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA realtime TO platform_core_runtime;
    END IF;
END $$;
