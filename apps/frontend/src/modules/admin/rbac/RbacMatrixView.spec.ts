import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import RbacMatrixView from './RbacMatrixView.vue';
import { coreRbacApi, type CoreRole, type CorePermission } from '@/shared/api/core/rbac';

vi.mock('@/shared/api/core/rbac', () => ({
  coreRbacApi: {
    listRoles: vi.fn(),
    listPermissions: vi.fn(),
    setRolePermissions: vi.fn(),
    createRole: vi.fn(),
  },
}));

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

describe('RbacMatrixView', () => {
  const roles: CoreRole[] = [
    {
      id: 'role-superadmin',
      code: 'superadmin',
      name: 'Super Administrator',
      description: 'System superadmin role',
      isSystem: true,
      permissions: ['core.organizations.manage', 'platform.files.manage'],
      createdAt: '2026-11-20T00:00:00Z',
      updatedAt: '2026-11-20T00:00:00Z',
    },
    {
      id: 'role-developer',
      code: 'developer',
      name: 'Platform Developer',
      description: 'Platform developer role',
      isSystem: false,
      permissions: ['platform.files.manage'],
      createdAt: '2026-11-21T00:00:00Z',
      updatedAt: '2026-11-21T00:00:00Z',
    },
  ];

  const permissions: CorePermission[] = [
    {
      id: 'perm-1',
      code: 'core.organizations.manage',
      name: 'Manage Organizations',
      module: 'core',
      description: 'Create and modify organizations',
    },
    {
      id: 'perm-2',
      code: 'platform.files.manage',
      name: 'Manage Cloud Drive Files',
      module: 'platform',
      description: 'Upload and delete drive files',
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(coreRbacApi.listRoles).mockResolvedValue([...roles]);
    vi.mocked(coreRbacApi.listPermissions).mockResolvedValue([...permissions]);
  });

  it('renders matrix with roles and permission categories', async () => {
    const wrapper = mount(RbacMatrixView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Super Administrator');
    expect(wrapper.text()).toContain('Platform Developer');
    expect(wrapper.text()).toContain('Manage Organizations');
    expect(wrapper.text()).toContain('Manage Cloud Drive Files');
  });

  it('disables checkbox for system roles', async () => {
    const wrapper = mount(RbacMatrixView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    // First role is superadmin (system), so its checkboxes must be disabled
    expect(checkboxes.length).toBe(4);
    expect(checkboxes[0]?.attributes('disabled')).toBeDefined();
  });

  it('toggles permission for custom role and calls setRolePermissions', async () => {
    vi.mocked(coreRbacApi.setRolePermissions).mockResolvedValueOnce(roles[1]!);

    const wrapper = mount(RbacMatrixView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    // Checkbox for custom developer role on perm-1 (currently false)
    const devPerm1Checkbox = checkboxes[1];
    expect(devPerm1Checkbox?.attributes('disabled')).toBeUndefined();

    await devPerm1Checkbox?.setValue(true);
    await flushPromises();

    expect(coreRbacApi.setRolePermissions).toHaveBeenCalledWith(
      'role-developer',
      expect.arrayContaining(['platform.files.manage', 'core.organizations.manage']),
    );
  });
});
