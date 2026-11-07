import { onMounted } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import type { Plan } from '@/shared/types';

/** Shared catalog CTA target. Keeps account bootstrap and plan intent out of individual views. */
export function usePlanTarget() {
  const router = useRouter();
  const auth = useAuthStore();
  const accounts = useAccountStore();

  onMounted(() => {
    if (auth.isAuthenticated && accounts.accounts.length === 0 && !accounts.loading) {
      void accounts.loadPage(0);
    }
  });

  return function target(plan: Plan): RouteLocationRaw {
    const price = plan.prices[0];
    const planQuery = price === undefined ? {} : { planPrice: price.id };
    if (!auth.isAuthenticated) {
      const planRoute = router.resolve({
        name: 'plan-detail',
        params: { code: plan.code },
        query: planQuery,
      });
      return { name: 'login', query: { redirect: planRoute.fullPath } };
    }
    const accountId = accounts.accounts[0]?.id;
    if (accountId === undefined) {
      return { name: 'cabinet', query: planQuery };
    }
    return {
      name: 'account-billing',
      params: { accountId },
      query: planQuery,
    };
  };
}
