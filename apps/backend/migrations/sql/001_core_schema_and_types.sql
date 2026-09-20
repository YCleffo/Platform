-- ============================================================================
-- 001 — Core schema and PostgreSQL ENUM types
-- PostgreSQL 18 is required because Core v1 uses native uuidv7() / uuidv4().
-- ============================================================================

CREATE SCHEMA core;
COMMENT ON SCHEMA core IS 'Universal platform kernel: identity, auth, RBAC, modules, settings, audit and integration events.';

CREATE TYPE core.person_status AS ENUM (
    'active',
    'inactive',
    'archived'
);

CREATE TYPE core.contact_type AS ENUM (
    'email',
    'phone'
);

CREATE TYPE core.account_type AS ENUM (
    'human',
    'service',
    'system'
);

CREATE TYPE core.account_status AS ENUM (
    'pending_verification',
    'pending_approval',
    'active',
    'disabled',
    'archived'
);

CREATE TYPE core.auth_identifier_type AS ENUM (
    'username',
    'email',
    'phone'
);

CREATE TYPE core.totp_algorithm AS ENUM (
    'sha1',
    'sha256',
    'sha512'
);

CREATE TYPE core.audit_actor_type AS ENUM (
    'anonymous',
    'account',
    'system',
    'service'
);

CREATE TYPE core.outbox_status AS ENUM (
    'pending',
    'processing',
    'published',
    'failed',
    'dead'
);

CREATE TYPE core.inbox_status AS ENUM (
    'received',
    'processing',
    'processed',
    'failed',
    'dead'
);
