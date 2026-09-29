DELETE FROM core.role_permissions WHERE permission_id IN (
    SELECT id FROM core.permissions WHERE code IN (
        'core.organizations.read', 'core.organizations.manage',
        'core.organization_memberships.read', 'core.organization_memberships.manage'
    )
);
DELETE FROM core.permissions WHERE code IN (
    'core.organizations.read', 'core.organizations.manage',
    'core.organization_memberships.read', 'core.organization_memberships.manage'
);
DROP TABLE core.organization_memberships;
DROP TABLE core.organizations;
