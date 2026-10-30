import { computed, ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { getSessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { CommercialAccountSummary } from '@/shared/types';

/** Server-paginated account picker; does not rely on the cabinet's first page. */
export function useAccountPicker() {
  const accounts = ref<CommercialAccountSummary[]>([]);
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

  let requestId = 0;

  async function loadPage(nextOffset: number = offset.value): Promise<void> {
    const epoch = getSessionEpoch();
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAccount.listAccounts(limit.value, nextOffset);
      if (id !== requestId || epoch !== getSessionEpoch()) {
        return;
      }
      accounts.value = result.items;
      total.value = result.total;
      limit.value = result.limit;
      offset.value = result.offset;
    } catch (failure) {
      if (id !== requestId || epoch !== getSessionEpoch() || failure instanceof RequestAbortedError) {
        return;
      }
      error.value = failure;
    } finally {
      if (id === requestId) {
        loading.value = false;
      }
    }
  }

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
    accounts,
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
