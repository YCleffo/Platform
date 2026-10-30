import { computed, ref } from 'vue';
import { commercialAuth } from '@/core/api';
import { getSessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { ControlSession } from '@/shared/types';

export function useSessions() {
  const sessions = ref<ControlSession[]>([]);
  const loading = ref(false);
  const error = ref<unknown>(null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));
  const current = computed(() => sessions.value.find((session) => session.current) ?? null);

  let requestId = 0;

  async function load(): Promise<void> {
    const epoch = getSessionEpoch();
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAuth.sessions();
      if (id !== requestId || epoch !== getSessionEpoch()) {
        return;
      }
      sessions.value = result.items;
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

  return { sessions, current, loading, error, errorMessage, load };
}
