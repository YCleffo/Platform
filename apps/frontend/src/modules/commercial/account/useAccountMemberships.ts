import { computed, ref, watch, type Ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { getSessionEpoch, sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { CommercialMembership } from '@/shared/types';

export function useAccountMemberships(accountId: Ref<string>, enabled: Ref<boolean>) {
  const memberships = ref<CommercialMembership[]>([]);
  const total = ref(0);
  const limit = ref(20);
  const offset = ref(0);
  const loading = ref(false);
  const error = ref<unknown>(null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));
  const page = computed(() => Math.floor(offset.value / limit.value) + 1);
  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / limit.value)));
  const hasPrev = computed(() => offset.value > 0);
  const hasNext = computed(() => offset.value + limit.value < total.value);

  let requestToken = 0;

  async function loadPage(nextOffset: number = offset.value): Promise<void> {
    if (!enabled.value) {
      return;
    }
    const epoch = getSessionEpoch();
    const token = ++requestToken;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAccount.listMemberships(accountId.value, limit.value, nextOffset);
      if (token !== requestToken || epoch !== getSessionEpoch()) {
        return;
      }
      memberships.value = result.items;
      total.value = result.total;
      limit.value = result.limit;
      offset.value = result.offset;
    } catch (err) {
      if (token !== requestToken || epoch !== getSessionEpoch()) {
        return;
      }
      if (err instanceof RequestAbortedError) {
        return;
      }
      error.value = err;
    } finally {
      if (token === requestToken) {
        loading.value = false;
      }
    }
  }

  watch(
    [accountId, enabled],
    () => {
      requestToken += 1;
      memberships.value = [];
      total.value = 0;
      offset.value = 0;
      error.value = null;
      loading.value = false;
      if (enabled.value) {
        void loadPage(0);
      }
    },
    { immediate: true },
  );

  // Logout / user switch invalidates membership data even while a request is in flight.
  watch(sessionEpoch, () => {
    requestToken += 1;
    memberships.value = [];
    total.value = 0;
    offset.value = 0;
    error.value = null;
    loading.value = false;
  });

  function nextPage(): void {
    if (hasNext.value) {
      void loadPage(offset.value + limit.value);
    }
  }

  function prevPage(): void {
    if (hasPrev.value) {
      void loadPage(Math.max(0, offset.value - limit.value));
    }
  }

  return {
    memberships,
    total,
    limit,
    offset,
    loading,
    error,
    errorMessage,
    page,
    pageCount,
    hasPrev,
    hasNext,
    loadPage,
    nextPage,
    prevPage,
  };
}
