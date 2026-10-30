import { ref } from 'vue';
import { ApiError } from '@/shared/api/errors';

const STEP_UP_CODES = new Set(['recent_authentication_required', 'recent_mfa_required']);

interface PendingAction {
  action: () => Promise<void>;
  resolve: (completed: boolean) => void;
}

/**
 * Runs a sensitive action and transparently requests a recent-auth step-up once.
 * The caller renders <ReauthenticateDialog v-model="reauthOpen" @authenticated="resume" @cancelled="cancel" />.
 */
export function useSensitiveAction() {
  const reauthOpen = ref(false);
  const busy = ref(false);
  const error = ref<unknown>(null);
  const needsMfaEnrollment = ref(false);
  let pending: PendingAction | null = null;

  function isStepUp(failure: unknown): boolean {
    return failure instanceof ApiError && STEP_UP_CODES.has(failure.code);
  }

  function isEnrollmentRequired(failure: unknown): boolean {
    return failure instanceof ApiError && failure.code === 'mfa_enrollment_required';
  }

  async function run(action: () => Promise<void>): Promise<boolean> {
    if (busy.value) {
      return false;
    }
    busy.value = true;
    error.value = null;
    needsMfaEnrollment.value = false;
    try {
      try {
        await action();
        return true;
      } catch (failure) {
        if (isStepUp(failure)) {
          return await new Promise<boolean>((resolve) => {
            pending = { action, resolve };
            reauthOpen.value = true;
          });
        }
        if (isEnrollmentRequired(failure)) {
          needsMfaEnrollment.value = true;
        }
        error.value = failure;
        return false;
      }
    } finally {
      busy.value = false;
    }
  }

  async function resume(): Promise<void> {
    const current = pending;
    pending = null;
    reauthOpen.value = false;
    if (current === null) {
      return;
    }
    busy.value = true;
    error.value = null;
    needsMfaEnrollment.value = false;
    try {
      await current.action();
      current.resolve(true);
    } catch (failure) {
      if (isEnrollmentRequired(failure)) {
        needsMfaEnrollment.value = true;
      }
      error.value = failure;
      current.resolve(false);
    } finally {
      busy.value = false;
    }
  }

  function cancel(): void {
    reauthOpen.value = false;
    const current = pending;
    pending = null;
    current?.resolve(false);
  }

  return { run, resume, cancel, reauthOpen, busy, error, needsMfaEnrollment };
}
