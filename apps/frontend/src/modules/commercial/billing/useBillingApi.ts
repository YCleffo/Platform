import { onBeforeUnmount, watch } from 'vue';
import { sessionEpoch } from '@/core/auth/session';
import { SupersededOperationError } from '@/shared/api/errors';
import type { CommercialBillingApi } from '@/shared/api/commercial/billing';

/** Guard every read/write settlement and every subsequent step of a chained billing operation. */
export function useBillingApi(api: CommercialBillingApi, accountId: () => string): CommercialBillingApi {
  const mountedEpoch = sessionEpoch.value;
  let active = true;
  let generation = 0;
  watch(accountId, () => { generation += 1; }, { flush: 'sync' });
  onBeforeUnmount(() => { active = false; });
  return new Proxy(api, {
    get(target, key, receiver) {
      const method: unknown = Reflect.get(target, key, receiver);
      if (typeof method !== 'function') return method;
      return async (...args: unknown[]) => {
        const id = accountId();
        const version = generation;
        const current = () => active && version === generation && sessionEpoch.value === mountedEpoch && id === accountId();
        if (!current() || args[0] !== id) throw new SupersededOperationError();
        try {
          const result: unknown = await Reflect.apply(method, target, args);
          if (!current()) throw new SupersededOperationError();
          return result;
        } catch (error) {
          if (!current()) throw new SupersededOperationError();
          throw error;
        }
      };
    },
  });
}
