import { ref, shallowRef } from 'vue';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';

export function useSensitiveAction(onError: (message: string) => void) {
  const reauthOpen = ref(false);
  const pending = shallowRef<null | (() => Promise<void>)>(null);

  async function runSensitive(action: () => Promise<void>): Promise<boolean> {
    try {
      await action();
      return true;
    } catch (error) {
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

  return { reauthOpen, runSensitive, onReauthenticated };
}
