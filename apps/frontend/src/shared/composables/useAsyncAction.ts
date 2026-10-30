import { ref } from 'vue';
import { toErrorMessage } from '@/shared/errors/humanize';

export function useAsyncAction<Args extends unknown[]>(action: (...args: Args) => Promise<unknown>) {
  const submitting = ref(false);
  const error = ref<string | null>(null);

  async function run(...args: Args): Promise<boolean> {
    if (submitting.value) {
      return false;
    }
    submitting.value = true;
    error.value = null;
    try {
      await action(...args);
      return true;
    } catch (err) {
      error.value = toErrorMessage(err);
      return false;
    } finally {
      submitting.value = false;
    }
  }

  return { submitting, error, run };
}
