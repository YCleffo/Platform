import type {
  AccountRole,
  CommercialAccountPermissions,
  CommercialAccountSummary,
} from '@/shared/types';

export type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger';

export interface RoleOption {
  value: AccountRole;
  label: string;
}

/** Server RBAC mirrored only for showing/hiding actions; the backend re-checks. */
export function canManageMembership(role: AccountRole, permissions: CommercialAccountPermissions): boolean {
  return role === 'owner' ? permissions.manage_ownership : permissions.manage_members;
}

export function assignableRoles(
  currentRole: AccountRole,
  permissions: CommercialAccountPermissions,
): AccountRole[] {
  if (!canManageMembership(currentRole, permissions)) {
    return [];
  }
  const all: AccountRole[] = ['owner', 'admin', 'billing', 'technical'];
  const allowed: AccountRole[] = permissions.manage_ownership
    ? all
    : ['admin', 'billing', 'technical'];
  return allowed.filter((role) => role !== currentRole);
}

export function roleVariant(role: string): BadgeVariant {
  return role === 'owner' ? 'primary' : 'neutral';
}

export function statusVariant(status: string): BadgeVariant {
  switch (status) {
    case 'active':
      return 'success';
    case 'suspended':
      return 'warning';
    case 'closed':
      return 'danger';
    default:
      return 'neutral';
  }
}

export function onboardingVariant(state: string): BadgeVariant {
  switch (state) {
    case 'ready_for_subscription':
      return 'success';
    case 'catalog_selection_pending':
      return 'warning';
    default:
      return 'neutral';
  }
}

export function accountInitials(account: CommercialAccountSummary): string {
  const name = account.display_name.trim();
  return name === '' ? '?' : name.charAt(0).toUpperCase();
}
