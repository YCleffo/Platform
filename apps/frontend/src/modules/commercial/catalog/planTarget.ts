import { onMounted } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import type { Plan } from '@/shared/types';

/** Shared catalog CTA target. Keeps account bootstrap out of individual views. */
export function usePlanTarget() {
  const auth = useAuthStore();
  const accounts = useAccountStore();

  onMounted(() => {
    if (auth.isAuthenticated && accounts.accounts.length === 0 && !accounts.loading) {
      void accounts.loadPage(0);
    }
  });

  return function target(plan: Plan): RouteLocationRaw {
    if (!auth.isAuthenticated) {
      return { name: 'login', query: { redirect: '/' } };
    }
    const accountId = accounts.accounts[0]?.id;
    if (accountId === undefined) {
      return { name: 'cabinet' };
    }
    const price = plan.prices[0];
    return {
      name: 'account-billing',
      params: { accountId },
      query: price === undefined ? {} : { planPrice: price.id },
    };
  };
}
