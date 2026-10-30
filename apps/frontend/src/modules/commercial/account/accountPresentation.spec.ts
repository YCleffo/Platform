import { describe, expect, it } from 'vitest';
import { assignableRoles, canManageMembership } from './accountPresentation';
import type { CommercialAccountPermissions } from '@/shared/types';

const permissions = (overrides: Partial<CommercialAccountPermissions> = {}): CommercialAccountPermissions => ({
  read_account: true,
  view_members: true,
  manage_members: false,
  manage_ownership: false,
  manage_billing: false,
  manage_technical: false,
  ...overrides,
});

describe('membership permissions', () => {
  it('owner manages owners and every role', () => {
    const owner = permissions({ manage_members: true, manage_ownership: true });

    expect(canManageMembership('owner', owner)).toBe(true);
    expect(canManageMembership('admin', owner)).toBe(true);
    expect(assignableRoles('owner', owner)).toEqual(['admin', 'billing', 'technical']);
    expect(assignableRoles('admin', owner)).toEqual(['owner', 'billing', 'technical']);
  });

  it('admin manages non-owner members only', () => {
    const admin = permissions({ manage_members: true });

    expect(canManageMembership('owner', admin)).toBe(false);
    expect(canManageMembership('billing', admin)).toBe(true);
    expect(assignableRoles('billing', admin)).toEqual(['admin', 'technical']);
    expect(assignableRoles('owner', admin)).toEqual([]);
  });

  it('billing and technical never receive management actions', () => {
    for (const permissionsSet of [permissions(), permissions({ manage_billing: true }), permissions({ manage_technical: true })]) {
      expect(canManageMembership('admin', permissionsSet)).toBe(false);
      expect(assignableRoles('admin', permissionsSet)).toEqual([]);
    }
  });
});
