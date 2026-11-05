<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { ApiError } from '@/shared/api/errors';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { email, required } from '@/shared/validation/rules';
import { corePassword } from './auth/validation';
import { coreAdminAuth, type CoreExternalProvider } from '@/shared/api/core/adminAuth';
import { prepareRecoveryRedirect, requireRecoveryStorage } from './auth/externalRecovery';
import { i18n } from '@/shared/i18n';
import { passkeyAvailability } from '@/shared/webauthn';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import './auth/core-auth.css';

const auth = useAdminAuthStore();
const router = useRouter();
const route = useRoute();
const step = ref<'login' | 'mfa_required' | 'password_change_required'>('login');
const mode = ref<'totp' | 'recovery'>('totp');
const code = ref('');
const recoveryCode = ref('');
const mfaError = ref<string | null>(null);
const loginMethod = ref<'password' | 'passkey'>('password');
const passkeySupport = passkeyAvailability();
let active = true;
const providers = ref<CoreExternalProvider[]>([]);
const providerController = new AbortController();
const providerAction = useAsyncAction(async () => {
  const provider = selectedProvider.value;
  if (!provider) return;
  requireRecoveryStorage();
  const start = await coreAdminAuth.startExternalLogin(provider, providerController.signal);
  if (!active) return;
  const url = await prepareRecoveryRedirect(provider, start, 'login');
  if (active) window.location.assign(url);
});
const selectedProvider = ref<CoreExternalProvider | null>(null);
onMounted(async () => {
  try {
    const values = await coreAdminAuth.providers(providerController.signal);
    if (active) providers.value = values;
  } catch {
    /* Password login remains available when external provider discovery is unavailable. */
  }
});
async function signInWith(provider: CoreExternalProvider): Promise<void> {
  if (submitting.value || providerAction.submitting.value) return;
  selectedProvider.value = provider;
  await providerAction.run();
}
const login = useForm({
  initial: { identifier: '', password: '' },
  fields: {
    identifier: { id: 'admin-identifier', rules: [email] },
    password: { id: 'admin-password', rules: [required] },
  },
});
const change = useForm({
  initial: { current: '', password: '', confirmation: '' },
  fields: {
    current: { id: 'admin-current-password', rules: [required] },
    password: { id: 'admin-new-password', rules: [corePassword] },
    confirmation: { id: 'admin-password-confirmation', rules: [required] },
  },
});

const { submitting, message, error, run, clearError } = useAsyncAction(async () => {
  if (step.value === 'mfa_required') {
    await auth.verifyMfa(
      mode.value === 'totp' ? code.value.trim() : null,
      mode.value === 'recovery' ? recoveryCode.value.trim() : null,
    );
  } else if (step.value === 'password_change_required') {
    await auth.completeRequiredPasswordChange(change.values.current, change.values.password);
    change.reset();
  } else {
    if (loginMethod.value === 'passkey') await auth.loginWithPasskey();
    else await auth.login(login.values.identifier, login.values.password);
    login.values.password = '';
  }
  if (active && auth.isAuthenticated) {
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '';
    await router.replace(
      /^\/admin(?:\/|$|\?)/.test(redirect) && !redirect.startsWith('/admin/login')
        ? redirect
        : '/admin',
    );
  }
});

const visibleError = computed(() =>
  step.value === 'password_change_required' &&
  error.value instanceof ApiError &&
  error.value.code === 'auth.invalid_credentials'
    ? i18n.global.t('coreAuth.changeRejected')
    : message.value,
);

watch(
  () => auth.status,
  (status) => {
    if (
      status === 'mfa_required' ||
      status === 'password_change_required' ||
      status === 'anonymous'
    ) {
      step.value = status === 'anonymous' ? 'login' : status;
      clearError();
      mfaError.value = null;
    }
  },
  { immediate: true },
);
watch(mode, () => {
  clearError();
  mfaError.value = null;
});
onBeforeUnmount(() => {
  active = false;
  providerController.abort();
  auth.cancelPendingAuthentication();
  login.reset();
  change.reset();
  code.value = '';
  recoveryCode.value = '';
});

function restart(): void {
  auth.cancelPendingAuthentication();
  change.reset();
  code.value = '';
  recoveryCode.value = '';
  clearError();
}

async function onSubmit(): Promise<void> {
  if (submitting.value || providerAction.submitting.value) return;
  loginMethod.value = 'password';
  if (step.value === 'login' && !login.validate()) return;
  if (step.value === 'password_change_required') {
    if (!change.validate()) return;
    if (change.values.password !== change.values.confirmation) {
      change.errors.confirmation = 'validation.passwordMismatch';
      document.getElementById('admin-password-confirmation')?.focus();
      return;
    }
    if (change.values.password === change.values.current) {
      change.errors.password = 'coreAuth.passwordDifferent';
      document.getElementById('admin-new-password')?.focus();
      return;
    }
  }
  if (step.value === 'mfa_required') {
    mfaError.value =
      mode.value === 'totp'
        ? /^\d{6}$/.test(code.value.trim())
          ? null
          : 'coreAuth.totpInvalid'
        : recoveryCode.value.trim()
          ? null
          : 'validation.required';
    if (mfaError.value) {
      document.getElementById(mode.value === 'totp' ? 'admin-totp' : 'admin-recovery')?.focus();
      return;
    }
  }
  await run();
}
async function signInWithPasskey(): Promise<void> {
  if (submitting.value || providerAction.submitting.value || passkeySupport !== 'available') return;
  loginMethod.value = 'passkey';
  await run();
}
</script>

<template>
  <AppFormShell
    :title="
      $t(
        step === 'mfa_required'
          ? 'admin.mfaTitle'
          : step === 'password_change_required'
            ? 'coreAuth.changeTitle'
            : 'admin.loginTitle',
      )
    "
    :subtitle="
      $t(
        step === 'mfa_required'
          ? 'admin.mfaSubtitle'
          : step === 'password_change_required'
            ? 'coreAuth.changeSubtitle'
            : 'admin.loginSubtitle',
      )
    "
    :error="visibleError"
  >
    <form
      class="core-auth-form"
      novalidate
      @submit.prevent="onSubmit"
    >
      <template v-if="step === 'login'">
        <AppButton
          variant="secondary"
          block
          :loading="submitting && loginMethod === 'passkey'"
          :disabled="
            submitting || providerAction.submitting.value || passkeySupport !== 'available'
          "
          @click="signInWithPasskey"
        >
          {{ $t('coreCredentials.signIn') }}
        </AppButton>
        <p
          v-if="passkeySupport !== 'available'"
          class="core-auth-hint"
        >
          {{ $t(`coreCredentials.${passkeySupport}`) }}
        </p>
        <AppButton
          v-if="submitting && loginMethod === 'passkey'"
          variant="secondary"
          @click="restart"
        >
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton
          v-for="provider in providers"
          :key="provider"
          variant="secondary"
          block
          :loading="providerAction.submitting.value && selectedProvider === provider"
          :disabled="submitting || providerAction.submitting.value"
          @click="signInWith(provider)"
        >
          {{
            $t('coreAuth.continueWith', { provider: provider === 'google' ? 'Google' : 'Telegram' })
          }}
        </AppButton>
        <p
          v-if="providerAction.message.value"
          class="core-auth-notice"
          role="alert"
        >
          {{ providerAction.message.value }}
        </p>
        <AppInput
          id="admin-identifier"
          v-model="login.values.identifier"
          :label="$t('admin.email')"
          type="email"
          autocomplete="username"
          :error="login.errors.identifier ? $t(login.errors.identifier) : undefined"
          :disabled="submitting"
          required
        />
        <AppPasswordInput
          id="admin-password"
          v-model="login.values.password"
          :label="$t('admin.password')"
          autocomplete="current-password"
          :error="login.errors.password ? $t(login.errors.password) : undefined"
          :disabled="submitting"
          required
        />
      </template>
      <template v-else-if="step === 'password_change_required'">
        <AppPasswordInput
          id="admin-current-password"
          v-model="change.values.current"
          :label="$t('coreAuth.currentPassword')"
          autocomplete="current-password"
          :error="change.errors.current ? $t(change.errors.current) : undefined"
          :disabled="submitting"
          required
        />
        <AppPasswordInput
          id="admin-new-password"
          v-model="change.values.password"
          :label="$t('auth.newPassword')"
          autocomplete="new-password"
          :hint="$t('coreAuth.passwordPolicy')"
          :error="change.errors.password ? $t(change.errors.password) : undefined"
          :disabled="submitting"
          required
        />
        <AppPasswordInput
          id="admin-password-confirmation"
          v-model="change.values.confirmation"
          :label="$t('auth.confirmPassword')"
          autocomplete="new-password"
          :error="change.errors.confirmation ? $t(change.errors.confirmation) : undefined"
          :disabled="submitting"
          required
        />
      </template>
      <template v-else>
        <div
          class="admin-login__mode"
          role="group"
          :aria-label="$t('admin.mfaTitle')"
        >
          <AppButton
            variant="secondary"
            :aria-pressed="mode === 'totp'"
            :disabled="submitting"
            @click="mode = 'totp'"
          >
            {{ $t('admin.totp') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :aria-pressed="mode === 'recovery'"
            :disabled="submitting"
            @click="mode = 'recovery'"
          >
            {{ $t('admin.recoveryCode') }}
          </AppButton>
        </div>
        <AppInput
          v-if="mode === 'totp'"
          id="admin-totp"
          v-model="code"
          :label="$t('admin.totpCode')"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          :error="mfaError ? $t(mfaError) : undefined"
          :disabled="submitting"
          required
        />
        <AppInput
          v-else
          id="admin-recovery"
          v-model="recoveryCode"
          :label="$t('admin.recoveryCode')"
          autocomplete="off"
          :error="mfaError ? $t(mfaError) : undefined"
          :disabled="submitting"
          required
        />
      </template>
      <AppButton
        type="submit"
        block
        :loading="submitting"
      >
        {{
          $t(
            step === 'login'
              ? 'admin.signIn'
              : step === 'mfa_required'
                ? 'admin.verify'
                : 'coreAuth.changeSubmit',
          )
        }}
      </AppButton>
      <AppButton
        v-if="step !== 'login'"
        variant="ghost"
        :disabled="submitting"
        @click="restart"
      >
        {{ $t('coreAuth.restartLogin') }}
      </AppButton>
      <p class="core-auth-links">
        <RouterLink :to="{ name: 'core-password-reset-request' }">
          {{ $t('coreAuth.passwordRequestTitle') }}
        </RouterLink>
        <RouterLink :to="{ name: 'core-mfa-recovery-request' }">
          {{ $t('coreAuth.mfaRequestTitle') }}
        </RouterLink>
        <RouterLink :to="{ name: 'catalog' }">{{ $t('admin.backToSite') }}</RouterLink>
      </p>
    </form>
  </AppFormShell>
</template>

<style scoped>
.admin-login__mode {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-8);
}

.admin-login__mode [aria-pressed='true'] {
  background: var(--accent-soft);
  border-color: var(--accent);
  color: var(--accent);
}
</style>
