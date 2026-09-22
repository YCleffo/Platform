-- ============================================================================
-- 011 — Authentication runtime and Core authorization vocabulary
-- Extends existing tables only; the Core table count remains 23.
-- ============================================================================

ALTER TABLE core.auth_sessions
    ADD COLUMN access_expires_at timestamptz;

UPDATE core.auth_sessions
SET access_expires_at = expires_at
WHERE access_expires_at IS NULL;

ALTER TABLE core.auth_sessions
    ALTER COLUMN access_expires_at SET NOT NULL,
    ADD CONSTRAINT chk_auth_sessions_access_expiry
        CHECK (access_expires_at <= expires_at);

COMMENT ON COLUMN core.auth_sessions.access_expires_at IS
    'Expiry of the current short-lived opaque access token; expires_at is absolute session expiry.';

INSERT INTO core.permissions (code, name, description, is_system)
VALUES
    ('core.persons.read', 'Read people', 'Read Core person and contact data.', true),
    ('core.persons.manage', 'Manage people', 'Create, update, archive, and manage Core person contacts.', true),
    ('core.accounts.read', 'Read accounts', 'Read safe Core account information.', true),
    ('core.accounts.manage', 'Manage accounts', 'Create and manage Core account lifecycle and invitations.', true),
    ('core.rbac.read', 'Read RBAC', 'Read Core roles and permissions.', true),
    ('core.rbac.manage', 'Manage RBAC', 'Assign and revoke Core account roles.', true)
ON CONFLICT (code) DO NOTHING;

INSERT INTO core.role_permissions (role_id, permission_id)
SELECT role.id, permission.id
FROM core.roles role
CROSS JOIN core.permissions permission
WHERE role.code = 'core.admin'
  AND permission.code IN (
      'core.persons.read',
      'core.persons.manage',
      'core.accounts.read',
      'core.accounts.manage',
      'core.rbac.read',
      'core.rbac.manage'
  )
ON CONFLICT (role_id, permission_id) DO NOTHING;
