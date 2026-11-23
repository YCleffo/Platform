import { describe, expect, it, vi, beforeEach } from 'vitest';
import { coreRbacApi, isPermission, isRole, isAccountRoleAssignment } from './rbac';
import { coreAdminHttp } from './adminHttp';
import { ApiResponseError } from '../errors';

vi.mock('./adminHttp', () => ({
  coreAdminHttp: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

describe('coreRbacApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const validPermission = {
    id: 'perm-1',
    code: 'core.roles.manage',
    name: 'Manage Roles',
    description: 'Create and assign roles',
    module: 'core',
  };

  const validRole = {
    id: 'role-1',
    name: 'Security Officer',
    code: 'security_officer',
    description: 'Handles credentials and roles',
    isSystem: false,
    permissions: ['core.roles.manage'],
    createdAt: '2026-11-23T00:00:00Z',
    updatedAt: '2026-11-23T00:00:00Z',
  };

  const validAssignment = {
    accountId: 'acc-1',
    roleId: 'role-1',
    roleCode: 'security_officer',
    roleName: 'Security Officer',
    assignedAt: '2026-11-23T00:00:00Z',
    assignedBy: 'user-admin',
  };

  it('validates permissions with runtime guards', () => {
    expect(isPermission(validPermission)).toBe(true);
    expect(isPermission({ ...validPermission, module: 'invalid_module' })).toBe(false);
    expect(isPermission(null)).toBe(false);
  });

  it('validates roles with runtime guards', () => {
    expect(isRole(validRole)).toBe(true);
    expect(isRole({ ...validRole, isSystem: 'not-bool' })).toBe(false);
    expect(isRole({ ...validRole, permissions: [123] })).toBe(false);
  });

  it('validates account role assignments', () => {
    expect(isAccountRoleAssignment(validAssignment)).toBe(true);
    expect(isAccountRoleAssignment({ ...validAssignment, accountId: '' })).toBe(false);
  });

  it('lists permissions', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [validPermission] });
    const items = await coreRbacApi.listPermissions();
    expect(items).toHaveLength(1);
    expect(items[0]?.code).toBe('core.roles.manage');
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/core/permissions');
  });

  it('lists roles and throws on invalid contract response', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [{ invalid: true }] });
    await expect(coreRbacApi.listRoles()).rejects.toThrow(ApiResponseError);
  });

  it('creates role successfully', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce(validRole);
    const role = await coreRbacApi.createRole({
      name: 'Security Officer',
      code: 'security_officer',
      permissions: ['core.roles.manage'],
    });
    expect(role.id).toBe('role-1');
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/core/roles', {
      name: 'Security Officer',
      code: 'security_officer',
      permissions: ['core.roles.manage'],
    });
  });

  it('assigns and revokes account roles', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce(validAssignment);
    const assigned = await coreRbacApi.assignAccountRole('acc-1', 'role-1');
    expect(assigned.roleCode).toBe('security_officer');

    vi.mocked(coreAdminHttp.delete).mockResolvedValueOnce({ status: 'revoked' });
    await expect(coreRbacApi.revokeAccountRole('acc-1', 'role-1')).resolves.toBeUndefined();
  });
});
