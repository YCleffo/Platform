import { ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { coreAdminSessionEpoch } from './session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';

export const useCorePresence = defineStore('corePresence', () => {
  const state = ref<'disabled' | 'paused' | 'sending' | 'online' | 'unknown'>('disabled');
  let generation = 0;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let controller: AbortController | undefined;
  let requestTimeout: ReturnType<typeof setTimeout> | undefined;
  let enabled = false;
  let active = false;
  let failures = 0;
  let nextAllowed = 0;
  function cancel(): void {
    generation += 1;
    clearTimeout(timer); timer = undefined;
    clearTimeout(requestTimeout); requestTimeout = undefined;
    controller?.abort(); controller = undefined;
  }
  async function beat(): Promise<void> {
    if (!active) return;
    const version = generation;
    const current = () => version === generation && active;
    const requestController = new AbortController(); controller = requestController;
    const signal = requestController.signal;
    nextAllowed = Date.now() + 30_000;
    const timeout = setTimeout(() => requestController.abort(), 10_000); requestTimeout = timeout;
    state.value = 'sending';
    try {
      const result = await coreAdminHttp.post<{status: string}>('/auth/me/presence/heartbeat', {}, signal);
      if (!current()) return;
      if (!['online', 'unknown'].includes(result?.status)) throw new ApiResponseError('/auth/me/presence/heartbeat');
      state.value = result.status as 'online' | 'unknown';
      failures = result.status === 'online' ? 0 : Math.min(failures + 1, 2);
    } catch {
      if (!current()) return;
      failures = Math.min(failures + 1, 2); state.value = 'unknown';
    } finally {
      clearTimeout(timeout);
      if (current()) {
        controller = undefined;
        nextAllowed = Date.now() + 30_000 * 2 ** failures;
        timer = setTimeout(() => { void beat(); }, nextAllowed - Date.now());
      }
    }
  }
  function configure(allowed: boolean, available: boolean): void {
    const next = allowed && available;
    if (allowed === enabled && next === active) return;
    cancel(); enabled = allowed; active = next;
    if (!allowed) { state.value = 'disabled'; failures = 0; nextAllowed = 0; }
    else if (!next) state.value = 'paused';
    else {
      state.value = 'paused';
      timer = setTimeout(() => { void beat(); }, Math.max(0, nextAllowed - Date.now()));
    }
  }
  function stop(): void { cancel(); enabled = false; active = false; state.value = 'disabled'; failures = 0; nextAllowed = 0; }
  watch(coreAdminSessionEpoch, stop, { flush: 'sync' });
  return { state, configure, stop };
});
