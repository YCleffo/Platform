import { ref } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount } from '@/shared/api/commercial';
import { toErrorMessage } from '@/shared/errors/humanize';
import type { CommercialAccountSummary, CreateAccountPayload } from '@/shared/types';

export const useAccountStore = defineStore('account', () => {
  const accounts = ref<CommercialAccountSummary[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function loadAccounts(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAccount.listAccounts();
      accounts.value = result.items;
      total.value = result.total;
    } catch (err) {
      error.value = toErrorMessage(err);
    } finally {
      loading.value = false;
    }
  }

  async function createAccount(payload: CreateAccountPayload, idempotencyKey: string): Promise<void> {
    await commercialAccount.createAccount(payload, idempotencyKey);
    await loadAccounts();
  }

  return { accounts, total, loading, error, loadAccounts, createAccount };
});
