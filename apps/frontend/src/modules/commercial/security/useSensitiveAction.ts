import { ref, shallowRef } from 'vue';
import { ApiError, RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';

export function useSensitiveAction(onError: (message: string) => void, onEnrollmentRequired?: () => void) {
  const reauthOpen = ref(false);
  const pending = shallowRef<null | (() => Promise<void>)>(null);

  async function runSensitive(action: () => Promise<void>): Promise<boolean> {
    try {
      await action();
      return true;
    } catch (error) {
      if (error instanceof SupersededOperationError || error instanceof RequestAbortedError) return false;
      if (error instanceof ApiError && error.code === 'mfa_enrollment_required') {
        onEnrollmentRequired?.();
        return false;
      }
      if (error instanceof ApiError && error.code === 'recent_mfa_required') {
        pending.value = action;
        reauthOpen.value = true;
        return false;
      }
      onError(resolveErrorMessage(error));
      return false;
    }
  }

  async function onReauthenticated(): Promise<void> {
    reauthOpen.value = false;
    const action = pending.value;
    pending.value = null;
    if (action !== null) {
      await runSensitive(action);
    }
  }

  function cancelPending(): void {
    pending.value = null;
    reauthOpen.value = false;
  }

  return { reauthOpen, runSensitive, onReauthenticated, cancelPending };
}
