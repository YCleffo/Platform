import { computed, ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount } from '@/core/api';
import { getSessionEpoch, sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { CommercialAccountSummary, CreateAccountPayload, CreateAccountResult } from '@/shared/types';

export const useAccountStore = defineStore('account', () => {
  const accounts = ref<CommercialAccountSummary[]>([]);
  const total = ref(0);
  const limit = ref(20);
  const offset = ref(0);
  const loading = ref(false);
  const error = ref<unknown>(null);
  const selectedId = ref<string | null>(null);

  const selected = computed(() => accounts.value.find((account) => account.id === selectedId.value) ?? null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));
  const page = computed(() => Math.floor(offset.value / limit.value) + 1);
  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / limit.value)));
  const hasPrev = computed(() => offset.value > 0);
  const hasNext = computed(() => offset.value + limit.value < total.value);

  let requestToken = 0;

  function clear(): void {
    requestToken += 1;
    accounts.value = [];
    total.value = 0;
    offset.value = 0;
    selectedId.value = null;
    loading.value = false;
    error.value = null;
  }

  // Any session boundary (login, logout, user switch) invalidates account data.
  watch(sessionEpoch, clear);

  async function loadPage(nextOffset: number = offset.value): Promise<void> {
    const epoch = getSessionEpoch();
    const token = ++requestToken;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAccount.listAccounts(limit.value, nextOffset);
      if (token !== requestToken || epoch !== getSessionEpoch()) {
        return;
      }
      accounts.value = result.items;
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

  async function create(payload: CreateAccountPayload, idempotencyKey: string): Promise<CreateAccountResult> {
    return commercialAccount.createAccount(payload, idempotencyKey);
  }

  function selectAccount(id: string): void {
    selectedId.value = id;
  }

  function clearSelection(): void {
    selectedId.value = null;
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
    selected,
    selectedId,
    page,
    pageCount,
    hasPrev,
    hasNext,
    loadPage,
    create,
    selectAccount,
    clearSelection,
    nextPage,
    prevPage,
    clear,
  };
});
