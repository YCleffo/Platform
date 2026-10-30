import { computed, ref } from 'vue';
import { commercialInvitation } from '@/core/api';
import { getSessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { captureInvitationTokenFromLocation, clearInvitationToken } from './invitationSession';
import type { InvitationAcceptResult, InvitationPreview } from '@/shared/types';

export function useInvitationAcceptance() {
  const token = ref<string | null>(null);
  const preview = ref<InvitationPreview | null>(null);
  const loading = ref(true);
  const error = ref<unknown>(null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));
  const acting = ref(false);
  const actionError = ref<unknown>(null);
  const actionErrorMessage = computed(() =>
    actionError.value === null ? null : resolveErrorMessage(actionError.value),
  );
  const declined = ref(false);
  const hasToken = computed(() => token.value !== null);

  function capture(): void {
    token.value = captureInvitationTokenFromLocation();
  }

  async function load(): Promise<void> {
    capture();
    if (token.value === null) {
      loading.value = false;
      return;
    }
    const epoch = getSessionEpoch();
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialInvitation.preview(token.value);
      if (epoch !== getSessionEpoch()) {
        return;
      }
      preview.value = result;
    } catch (failure) {
      if (epoch !== getSessionEpoch() || failure instanceof RequestAbortedError) {
        return;
      }
      error.value = failure;
    } finally {
      loading.value = false;
    }
  }

  async function accept(): Promise<InvitationAcceptResult | null> {
    if (token.value === null || acting.value) {
      return null;
    }
    acting.value = true;
    actionError.value = null;
    try {
      const result = await commercialInvitation.accept(token.value);
      clearInvitationToken();
      token.value = null;
      return result;
    } catch (failure) {
      actionError.value = failure;
      return null;
    } finally {
      acting.value = false;
    }
  }

  async function decline(): Promise<boolean> {
    if (token.value === null || acting.value) {
      return false;
    }
    acting.value = true;
    actionError.value = null;
    try {
      await commercialInvitation.decline(token.value);
      clearInvitationToken();
      token.value = null;
      declined.value = true;
      return true;
    } catch (failure) {
      actionError.value = failure;
      return false;
    } finally {
      acting.value = false;
    }
  }

  return {
    preview,
    loading,
    error,
    errorMessage,
    actionError,
    actionErrorMessage,
    acting,
    declined,
    hasToken,
    load,
    accept,
    decline,
  };
}
