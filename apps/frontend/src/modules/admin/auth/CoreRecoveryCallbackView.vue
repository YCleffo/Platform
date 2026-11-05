<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { coreAdminAuth } from '@/shared/api/core/adminAuth';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { ApiError } from '@/shared/api/errors';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { consumeRecoveryCallback } from './externalRecovery';
import { relayIdentityCallback } from './externalLink';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import './core-auth.css';

const route = useRoute();
const auth = useAdminAuthStore();
const router = useRouter();
const completed = ref(false);
const linkDelivered = ref(false);
const started = ref(false);
const controller = new AbortController();
let handoff = false;
let loginCallback = false;
const callback = {
  provider: route.params.provider,
  state: route.query.state,
  code: route.query.code,
  error: route.query.error,
};
const { submitting, message, run } = useAsyncAction(async () => {
  const { provider, purpose } = await consumeRecoveryCallback(callback.provider, callback.state);
  if (purpose === 'identity-link') {
    await relayIdentityCallback(provider, callback.state as string, callback.code, callback.error);
    linkDelivered.value = true;
    window.close();
    return;
  }
  loginCallback = purpose === 'login';
  if (callback.error !== undefined)
    throw new ApiError(400, 'core_recovery_cancelled', 'Provider verification was cancelled.');
  if (typeof callback.code !== 'string' || callback.code === '' || callback.code.length > 4096) {
    throw new ApiError(400, 'core_recovery_callback_invalid', 'Recovery callback is invalid.');
  }
  if (controller.signal.aborted) return;
  if (loginCallback) {
    await auth.completeExternalLogin(provider, callback.state as string, callback.code);
    if (!controller.signal.aborted) {
      handoff = true;
      await router.replace({ name: auth.isAuthenticated ? 'admin-dashboard' : 'admin-login' });
    }
    return;
  }
  await coreAdminAuth.completeExternalMfaRecovery(
    provider,
    callback.state as string,
    callback.code,
    controller.signal,
  );
  if (!controller.signal.aborted) completed.value = true;
});
const title = computed(() =>
  linkDelivered.value
    ? 'coreCredentials.returnOriginal'
    : completed.value
      ? 'coreAuth.mfaCompletedTitle'
      : !started.value || submitting.value
        ? 'coreAuth.externalChecking'
        : 'coreAuth.externalFailed',
);
onMounted(async () => {
  // Keep provider codes and OAuth state out of history/referrers; a reload asks to restart instead of replaying the code.
  await router.replace({ query: {} });
  started.value = true;
  if (!controller.signal.aborted) await run();
});
onBeforeUnmount(() => {
  controller.abort();
  if (loginCallback && !handoff) auth.cancelPendingAuthentication();
});
</script>

<template>
  <AppFormShell
    :title="$t(title)"
    :subtitle="completed ? $t('coreAuth.mfaCompleted') : undefined"
    :error="message"
  >
    <p
      v-if="!submitting && !linkDelivered"
      class="core-auth-links"
      role="status"
    >
      <RouterLink :to="{ name: 'admin-login' }">{{ $t('coreAuth.backToLogin') }}</RouterLink>
      <RouterLink
        v-if="!completed"
        :to="{ name: 'core-mfa-recovery-request' }"
      >
        {{ $t('auth.requestNewLink') }}
      </RouterLink>
    </p>
  </AppFormShell>
</template>
