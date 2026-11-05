import { computed, ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount } from '@/core/api';
import { getSessionEpoch, sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type {
  CommercialAccountDetail,
  CommercialAccountSummary,
  CreateAccountPayload,
  CreateAccountResult,
} from '@/shared/types';

export const useAccountStore = defineStore('account', () => {
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

  const selectedAccountId = ref<string | null>(null);
  const accountDetail = ref<CommercialAccountDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<unknown>(null);
  const detailErrorMessage = computed(() =>
    detailError.value === null ? null : resolveErrorMessage(detailError.value),
  );

  let requestToken = 0;
  let detailToken = 0;

  function clearAccountContext(): void {
    detailToken += 1;
    selectedAccountId.value = null;
    accountDetail.value = null;
    detailError.value = null;
    detailLoading.value = false;
  }

  function clear(): void {
    requestToken += 1;
    accounts.value = [];
    total.value = 0;
    offset.value = 0;
    loading.value = false;
    error.value = null;
    clearAccountContext();
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

  async function loadAccount(id: string): Promise<void> {
    const epoch = getSessionEpoch();
    const token = ++detailToken;
    selectedAccountId.value = id;
    // Never display the previous account while the next one loads.
    accountDetail.value = null;
    detailError.value = null;
    detailLoading.value = true;
    try {
      const detail = await commercialAccount.getAccount(id);
      if (token !== detailToken || epoch !== getSessionEpoch()) {
        return;
      }
      accountDetail.value = detail;
    } catch (err) {
      if (token !== detailToken || epoch !== getSessionEpoch()) {
        return;
      }
      if (err instanceof RequestAbortedError) {
        return;
      }
      detailError.value = err;
    } finally {
      if (token === detailToken) {
        detailLoading.value = false;
      }
    }
  }

  /** Refresh the current account after a mutation without unmounting its in-flight form. */
  async function refreshAccount(id: string): Promise<void> {
    if (selectedAccountId.value !== id || accountDetail.value?.id !== id) return loadAccount(id);
    const epoch = getSessionEpoch();
    const token = ++detailToken;
    try {
      const detail = await commercialAccount.getAccount(id);
      if (token === detailToken && epoch === getSessionEpoch() && selectedAccountId.value === id) {
        accountDetail.value = detail;
        detailError.value = null;
      }
    } catch (err) {
      if (token === detailToken && epoch === getSessionEpoch() && selectedAccountId.value === id) {
        accountDetail.value = null;
        detailError.value = err;
      }
      throw err;
    }
  }

  function invalidateAccount(id?: string): void {
    if (id !== undefined && selectedAccountId.value !== id) {
      return;
    }
    detailToken += 1;
    accountDetail.value = null;
    detailError.value = null;
    detailLoading.value = false;
  }

  async function create(payload: CreateAccountPayload, idempotencyKey: string): Promise<CreateAccountResult> {
    return commercialAccount.createAccount(payload, idempotencyKey);
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
    selectedAccountId,
    accountDetail,
    detailLoading,
    detailError,
    detailErrorMessage,
    loadPage,
    loadAccount,
    refreshAccount,
    invalidateAccount,
    clearAccountContext,
    clear,
    create,
    nextPage,
    prevPage,
  };
});
