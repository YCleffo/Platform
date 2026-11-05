import { ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { coreAdminSessionEpoch } from './session';
import { coreAccount, type CoreSettings, type CoreSettingsPatch } from '@/shared/api/core/account';
import { SupersededOperationError } from '@/shared/api/errors';

export const useCoreAccountSettings = defineStore('coreAccountSettings', () => {
  const value = ref<CoreSettings | null>(null);
  let generation = 0; let pending: Promise<CoreSettings> | null = null;
  watch(coreAdminSessionEpoch, () => { generation += 1; value.value = null; pending = null; }, { flush: 'sync' });
  async function settle(action: () => Promise<CoreSettings>): Promise<CoreSettings> {
    const request = ++generation; const epoch = coreAdminSessionEpoch.value;
    const current = () => request === generation && epoch === coreAdminSessionEpoch.value;
    try { const result = await action(); if (!current()) throw new SupersededOperationError(); value.value = result; return result; }
    catch (error) { if (!current()) throw new SupersededOperationError(); throw error; }
  }
  function load(force = false): Promise<CoreSettings> {
    if (pending) return pending;
    if (value.value && !force) return Promise.resolve(value.value);
    const request = settle(coreAccount.settings); pending = request;
    void request.finally(() => { if (pending === request) pending = null; }).catch(() => undefined);
    return request;
  }
  function patch(document: CoreSettingsPatch): Promise<CoreSettings> { pending = null; return settle(() => coreAccount.patchSettings(document)); }
  return { value, load, patch };
});
