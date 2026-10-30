import { computed, ref, watch, type Ref } from 'vue';
import { commercialInvitation } from '@/core/api';
import { getSessionEpoch, sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { AccountInvitation } from '@/shared/types';

export function useAccountInvitations(accountId: Ref<string>, enabled: Ref<boolean>) {
  const invitations = ref<AccountInvitation[]>([]);
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

  function resetState(): void {
    requestToken += 1;
    invitations.value = [];
    total.value = 0;
    offset.value = 0;
    error.value = null;
    loading.value = false;
  }

  async function loadPage(nextOffset: number = offset.value): Promise<void> {
    if (!enabled.value) {
      return;
    }
    const epoch = getSessionEpoch();
    const token = ++requestToken;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialInvitation.list(accountId.value, limit.value, nextOffset);
      if (token !== requestToken || epoch !== getSessionEpoch()) {
        return;
      }
      invitations.value = result.items;
      total.value = result.total;
      limit.value = result.limit;
      offset.value = result.offset;
    } catch (failure) {
      if (token !== requestToken || epoch !== getSessionEpoch() || failure instanceof RequestAbortedError) {
        return;
      }
      error.value = failure;
    } finally {
      if (token === requestToken) {
        loading.value = false;
      }
    }
  }

  watch(
    [accountId, enabled],
    () => {
      resetState();
      if (enabled.value) {
        void loadPage(0);
      }
    },
    { immediate: true },
  );

  watch(sessionEpoch, resetState);

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
    invitations,
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
