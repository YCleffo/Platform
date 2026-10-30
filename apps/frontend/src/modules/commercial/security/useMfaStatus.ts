import { computed, ref } from 'vue';
import { commercialAuth } from '@/core/api';
import { getSessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { MfaStatus } from '@/shared/types';

export function useMfaStatus() {
  const status = ref<MfaStatus | null>(null);
  const loading = ref(false);
  const error = ref<unknown>(null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));

  let requestId = 0;

  async function load(): Promise<void> {
    const epoch = getSessionEpoch();
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAuth.mfaStatus();
      if (id !== requestId || epoch !== getSessionEpoch()) {
        return;
      }
      status.value = result;
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

  return { status, loading, error, errorMessage, load };
}
