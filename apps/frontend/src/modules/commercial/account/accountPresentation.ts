import type { CommercialAccountSummary } from '@/shared/types';

export type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger';

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
