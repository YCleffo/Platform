-- ============================================================================
-- 012 — Core configuration runtime permission vocabulary
-- No tables or columns are added; the Core table count remains 23.
-- ============================================================================

INSERT INTO core.permissions (code, name, description, is_system)
VALUES
    ('core.modules.read', 'Read modules', 'Read the controlled Core module registry and runtime state.', true),
    ('core.modules.manage', 'Manage modules', 'Enable and disable registered Core modules.', true),
    ('core.settings.read', 'Read settings', 'Read Core system and module settings.', true),
    ('core.settings.manage', 'Manage settings', 'Upsert and delete Core system and module settings.', true),
    ('core.feature_flags.read', 'Read feature flags', 'Read Core feature flag definitions.', true),
    ('core.feature_flags.manage', 'Manage feature flags', 'Upsert and delete Core feature flag definitions.', true)
ON CONFLICT (code) DO NOTHING;

INSERT INTO core.role_permissions (role_id, permission_id)
SELECT role.id, permission.id
FROM core.roles role
CROSS JOIN core.permissions permission
WHERE role.code = 'core.admin'
  AND permission.code IN (
      'core.modules.read',
      'core.modules.manage',
      'core.settings.read',
      'core.settings.manage',
      'core.feature_flags.read',
      'core.feature_flags.manage'
  )
ON CONFLICT (role_id, permission_id) DO NOTHING;
