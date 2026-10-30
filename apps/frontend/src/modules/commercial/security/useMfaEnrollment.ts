import { ref } from 'vue';
import { commercialAuth } from '@/core/api';
import { getSessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import type { TotpSetup } from '@/shared/types';

/**
 * One-time TOTP enrollment state. The secret and recovery codes live only in memory
 * and are cleared by reset()/on dialog close.
 */
export function useMfaEnrollment() {
  const setup = ref<TotpSetup | null>(null);
  const recoveryCodes = ref<string[]>([]);
  const loading = ref(false);
  const error = ref<unknown>(null);

  let requestId = 0;

  async function begin(): Promise<boolean> {
    const epoch = getSessionEpoch();
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAuth.beginTotpSetup();
      if (id !== requestId || epoch !== getSessionEpoch()) {
        return false;
      }
      setup.value = result;
      return true;
    } catch (failure) {
      if (id !== requestId || failure instanceof RequestAbortedError) {
        return false;
      }
      error.value = failure;
      throw failure;
    } finally {
      if (id === requestId) {
        loading.value = false;
      }
    }
  }

  async function confirm(code: string): Promise<boolean> {
    const current = setup.value;
    if (current === null) {
      return false;
    }
    const epoch = getSessionEpoch();
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialAuth.confirmTotpSetup(current.factor_id, code);
      if (id !== requestId || epoch !== getSessionEpoch()) {
        return false;
      }
      recoveryCodes.value = result.recovery_codes;
      // The secret and otpauth URI must not outlive the confirmation step.
      setup.value = null;
      return true;
    } catch (failure) {
      if (id !== requestId || failure instanceof RequestAbortedError) {
        return false;
      }
      error.value = failure;
      throw failure;
    } finally {
      if (id === requestId) {
        loading.value = false;
      }
    }
  }

  function reset(): void {
    requestId += 1;
    setup.value = null;
    recoveryCodes.value = [];
    error.value = null;
    loading.value = false;
  }

  return { setup, recoveryCodes, loading, error, begin, confirm, reset };
}
