<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
  coreAdminAuth,
  type CoreMfaRecoveryResult,
  type CoreExternalProvider,
} from '@/shared/api/core/adminAuth';
import {
  isCoreProvider,
  prepareRecoveryRedirect,
  requireRecoveryStorage,
} from './externalRecovery';
import { ApiError, RequestAbortedError } from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { required } from '@/shared/validation/rules';
import { corePassword } from './validation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import './core-auth.css';

const props = defineProps<{ kind: 'activation' | 'password' | 'mfa' }>();
const route = useRoute();
const router = useRouter();
// Keep the email URL reloadable until completion. Never persist link tokens or passwords in browser storage.
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const outcome = ref<
  'completed' | 'additional_recovery_required' | 'external_reauth_required' | null
>(null);
const external = ref<Extract<CoreMfaRecoveryResult, { status: 'external_reauth_required' }> | null>(
  null,
);
const providers = computed(() => external.value?.providers.filter(isCoreProvider) ?? []);
const requestRoute = computed(() =>
  props.kind === 'password' ? 'core-password-reset-request' : 'core-mfa-recovery-request',
);
const form = useForm({
  initial: { password: '', confirmation: '' },
  fields: {
    password: { id: 'core-link-password', rules: [] },
    confirmation: { id: 'core-link-confirmation', rules: [required] },
  },
});
let controller: AbortController | null = null;
const { submitting, message, error, run, clearError } = useAsyncAction(async () => {
  const request = new AbortController();
  controller = request;
  let result: typeof outcome.value = 'completed';
  if (props.kind === 'activation')
    await coreAdminAuth.activate(token.value, form.values.password, request.signal);
  else if (props.kind === 'password')
    await coreAdminAuth.confirmPasswordReset(token.value, form.values.password, request.signal);
  else {
    const recovery = await coreAdminAuth.confirmMfaRecovery(
      token.value,
      form.values.password || null,
      request.signal,
    );
    result = recovery.status;
    if (recovery.status === 'external_reauth_required') external.value = recovery;
  }
  if (request.signal.aborted) throw new RequestAbortedError();
  outcome.value = result;
  form.reset();
  const query = { ...route.query };
  delete query.token;
  await router.replace({ query });
});
const visibleError = computed(() =>
  props.kind === 'mfa' && error.value instanceof ApiError && error.value.code === 'auth.mfa.invalid'
    ? i18n.global.t('coreAuth.recoveryRejected')
    : message.value,
);
const redirectAction = useAsyncAction(async () => {
  const proof = external.value;
  const provider = selectedProvider.value;
  if (proof === null || provider === null || Date.parse(proof.expiresAt) <= Date.now()) {
    throw new ApiError(400, 'core_recovery_callback_invalid', 'Recovery must be restarted.');
  }
  requireRecoveryStorage();
  const request = new AbortController();
  controller = request;
  const start = await coreAdminAuth.startExternalMfaRecovery(
    provider,
    proof.recoveryToken,
    request.signal,
  );
  if (request.signal.aborted) return;
  const url = await prepareRecoveryRedirect(provider, start);
  if (!request.signal.aborted) window.location.assign(url);
});
const selectedProvider = ref<CoreExternalProvider | null>(null);
async function continueWith(provider: CoreExternalProvider): Promise<void> {
  if (redirectAction.submitting.value) return;
  selectedProvider.value = provider;
  await redirectAction.run();
}

watch([() => props.kind, token], ([kind, nextToken], [previousKind, previousToken]) => {
  // Removing a consumed token must preserve the result screen.
  if (
    kind !== previousKind ||
    (nextToken !== previousToken && (nextToken !== '' || outcome.value === null))
  ) {
    controller?.abort();
    outcome.value = null;
    external.value = null;
    form.reset();
    clearError();
  }
});
onBeforeUnmount(() => {
  controller?.abort();
  form.reset();
  external.value = null;
});

async function onSubmit(): Promise<void> {
  if (!token.value || submitting.value) return;
  if (props.kind !== 'mfa') {
    form.errors.password = corePassword(form.values.password);
    form.errors.confirmation =
      form.values.password === form.values.confirmation ? null : 'validation.passwordMismatch';
    if (form.errors.password || form.errors.confirmation) {
      document
        .getElementById(form.errors.password ? 'core-link-password' : 'core-link-confirmation')
        ?.focus();
      return;
    }
  }
  await run();
}
</script>

<template>
  <AppFormShell
    v-if="outcome === 'external_reauth_required' && providers.length > 0"
    :title="$t('coreAuth.externalTitle')"
    :subtitle="$t('coreAuth.externalSubtitle')"
    :error="redirectAction.message.value"
  >
    <div class="core-auth-form">
      <AppButton
        v-for="provider in providers"
        :key="provider"
        block
        :disabled="redirectAction.submitting.value"
        :loading="redirectAction.submitting.value && selectedProvider === provider"
        @click="continueWith(provider)"
      >
        {{
          $t('coreAuth.continueWith', { provider: provider === 'google' ? 'Google' : 'Telegram' })
        }}
      </AppButton>
      <p class="core-auth-links">
        <RouterLink :to="{ name: 'core-mfa-recovery-request' }">
          {{ $t('auth.requestNewLink') }}
        </RouterLink>
      </p>
    </div>
  </AppFormShell>
  <AppFormShell
    v-else-if="outcome"
    :title="
      $t(
        outcome === 'completed'
          ? `coreAuth.${kind}CompletedTitle`
          : 'coreAuth.additionalRecoveryTitle',
      )
    "
    :subtitle="
      $t(outcome === 'completed' ? `coreAuth.${kind}Completed` : 'coreAuth.additionalRecovery')
    "
  >
    <p
      class="core-auth-links"
      role="status"
    >
      <RouterLink :to="{ name: 'admin-login' }">{{ $t('coreAuth.backToLogin') }}</RouterLink>
    </p>
  </AppFormShell>
  <AppFormShell
    v-else-if="!token"
    :title="$t('coreAuth.linkMissingTitle')"
    :subtitle="$t('coreAuth.linkMissing')"
  >
    <p class="core-auth-links">
      <RouterLink
        v-if="kind !== 'activation'"
        :to="{ name: requestRoute }"
      >
        {{ $t('auth.requestNewLink') }}
      </RouterLink>
      <RouterLink :to="{ name: 'admin-login' }">{{ $t('coreAuth.backToLogin') }}</RouterLink>
    </p>
  </AppFormShell>
  <AppFormShell
    v-else
    :title="$t(`coreAuth.${kind}Title`)"
    :subtitle="$t(`coreAuth.${kind}Subtitle`)"
    :error="visibleError"
  >
    <form
      class="core-auth-form"
      novalidate
      @submit.prevent="onSubmit"
    >
      <AppPasswordInput
        id="core-link-password"
        v-model="form.values.password"
        :label="$t(kind === 'mfa' ? 'admin.password' : 'auth.newPassword')"
        :hint="$t(kind === 'mfa' ? 'coreAuth.mfaPasswordHint' : 'coreAuth.passwordPolicy')"
        :autocomplete="kind === 'mfa' ? 'current-password' : 'new-password'"
        :error="form.errors.password ? $t(form.errors.password) : undefined"
        :required="kind !== 'mfa'"
        :disabled="submitting"
      />
      <AppPasswordInput
        v-if="kind !== 'mfa'"
        id="core-link-confirmation"
        v-model="form.values.confirmation"
        :label="$t('auth.confirmPassword')"
        autocomplete="new-password"
        required
        :disabled="submitting"
        :error="form.errors.confirmation ? $t(form.errors.confirmation) : undefined"
      />
      <AppButton
        type="submit"
        block
        :loading="submitting"
      >
        {{ $t(`coreAuth.${kind}Submit`) }}
      </AppButton>
      <p class="core-auth-links">
        <RouterLink
          v-if="kind !== 'activation'"
          :to="{ name: requestRoute }"
        >
          {{ $t('auth.requestNewLink') }}
        </RouterLink>
        <RouterLink :to="{ name: 'admin-login' }">{{ $t('coreAuth.backToLogin') }}</RouterLink>
      </p>
    </form>
  </AppFormShell>
</template>
