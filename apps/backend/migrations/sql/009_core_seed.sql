-- ============================================================================
-- 009 — Minimal immutable-ish system seed
-- No product/domain roles (student, teacher, parent, etc.) belong in Core.
-- ============================================================================

INSERT INTO core.roles (
    code,
    name,
    description,
    is_system,
    is_protected
)
VALUES
    (
        'core.superadmin',
        'Super Administrator',
        'Protected platform super administrator role. PermissionService may apply a full-access bypass.',
        true,
        true
    ),
    (
        'core.admin',
        'Administrator',
        'Protected administrative role governed by explicitly assigned RBAC permissions.',
        true,
        true
    )
ON CONFLICT (code) DO NOTHING;
