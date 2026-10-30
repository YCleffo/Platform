import { computed, ref } from 'vue';
import { RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';

/**
 * Wraps a submit action with a duplicate-submit guard and a localized error message.
 * The raw error is kept so the message re-localizes when the locale changes.
 */
export function useAsyncAction(action: () => Promise<unknown>) {
  const submitting = ref(false);
  const error = ref<unknown>(null);
  const message = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));

  function clearError(): void {
    error.value = null;
  }

  async function run(): Promise<boolean> {
    if (submitting.value) {
      return false;
    }
    submitting.value = true;
    error.value = null;
    try {
      await action();
      return true;
    } catch (err) {
      if (err instanceof RequestAbortedError || err instanceof SupersededOperationError) {
        return false;
      }
      error.value = err;
      return false;
    } finally {
      submitting.value = false;
    }
  }

  return { submitting, error, message, run, clearError };
}
