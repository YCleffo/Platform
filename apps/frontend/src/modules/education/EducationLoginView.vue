<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { setLocale } from '@/shared/i18n';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import { useEducationI18n } from './i18n';
import { useEducationError } from './resources';
import { educationApi } from './api';
import { educationLanding, isEducationActivationTarget } from './contexts';
import './education.css';
const auth = useAdminAuthStore();
const router = useRouter();
const route = useRoute();
const { t, locale } = useEducationI18n();
const issue = useEducationError();
const email = ref('');
const password = ref('');
const code = ref('');
const recovery = ref(false);
const freshPassword = ref('');
const repeated = ref('');
const busy = ref(false);
const failure = shallowRef<
  { type: 'passwordMismatch' } | { type: 'request'; cause: unknown } | null
>(null);
const error = computed(() => {
  if (!failure.value) return '';
  return failure.value.type === 'request'
    ? issue(failure.value.cause)
    : t('education.passwordMismatch');
});
let active = true;
const step = computed(() =>
  auth.status === 'mfa_required' || auth.status === 'password_change_required'
    ? auth.status
    : 'login',
);
async function submit(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  failure.value = null;
  try {
    if (step.value === 'mfa_required')
      await auth.verifyMfa(
        recovery.value ? null : code.value.trim(),
        recovery.value ? code.value.trim() : null,
      );
    else if (step.value === 'password_change_required') {
      if (freshPassword.value.length < 12 || freshPassword.value !== repeated.value) {
        failure.value = { type: 'passwordMismatch' };
        return;
      }
      await auth.completeRequiredPasswordChange(password.value, freshPassword.value);
    } else await auth.login(email.value, password.value);
    password.value = '';
    code.value = '';
    freshPassword.value = '';
    repeated.value = '';
    if (active && auth.isAuthenticated) {
      const target = typeof route.query.redirect === 'string' ? route.query.redirect : '';
      // Existing Core accounts acquire Education permissions only after accepting the invitation.
      if (isEducationActivationTarget(target)) {
        await router.replace(target);
        return;
      }
      const context = await educationApi.context();
      const home = educationLanding(context);
      await router.replace(
        /^\/(parent|education\/parent-activate|education\/contexts|today|calendar|people|messages|more|lessons|groups|students|student|programs|devices|materials|notifications|attention|homework)(?:[/?#]|$)/.test(
          target,
        )
          ? target
          : home,
      );
    }
  } catch (caught) {
    if (active) failure.value = { type: 'request', cause: caught };
  } finally {
    if (active) busy.value = false;
  }
}
function restart(): void {
  auth.cancelPendingAuthentication();
  password.value = '';
  code.value = '';
  failure.value = null;
}
onBeforeUnmount(() => {
  active = false;
  auth.cancelPendingAuthentication();
  password.value = '';
  code.value = '';
  freshPassword.value = '';
  repeated.value = '';
});
</script>
<template>
  <main class="education-login">
    <AppCard
      class="edu-login-card"
      padding="large"
    >
      <p class="edu-brand">Education</p>
      <h1>
        {{
          t(
            `education.${step === 'mfa_required' ? 'mfaTitle' : step === 'password_change_required' ? 'changePassword' : 'loginTitle'}`,
          )
        }}
      </h1>
      <p>{{ t('education.loginHint') }}</p>
      <form
        class="edu-form"
        @submit.prevent="submit"
      >
        <template v-if="step === 'login'">
          <AppInput
            id="education-email"
            v-model="email"
            type="email"
            :label="t('education.email')"
            autocomplete="username"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="education-password"
            v-model="password"
            :label="t('education.password')"
            autocomplete="current-password"
            required
            :disabled="busy"
          />
        </template>
        <AppInput
          v-else-if="step === 'mfa_required'"
          id="education-code"
          v-model="code"
          :label="t(recovery ? 'education.recoveryCode' : 'education.code')"
          autocomplete="one-time-code"
          required
          :disabled="busy"
        />
        <template v-else>
          <AppPasswordInput
            id="education-current-password"
            v-model="password"
            :label="t('education.currentPassword')"
            autocomplete="current-password"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="education-new-password"
            v-model="freshPassword"
            :label="t('education.newPassword')"
            autocomplete="new-password"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="education-password-repeat"
            v-model="repeated"
            :label="t('education.repeatPassword')"
            autocomplete="new-password"
            required
            :disabled="busy"
          />
        </template>
        <p
          v-if="error"
          role="alert"
          class="edu-error"
        >
          {{ error }}
        </p>
        <AppButton
          type="submit"
          :loading="busy"
        >
          {{
            t(
              busy
                ? 'education.loading'
                : step === 'login'
                  ? 'education.signIn'
                  : 'education.confirm',
            )
          }}
        </AppButton>
      </form>
      <button
        v-if="step === 'mfa_required'"
        type="button"
        class="edu-link"
        @click="
          recovery = !recovery;
          code = '';
        "
      >
        {{ t(recovery ? 'education.useAuthenticator' : 'education.useRecovery') }}
      </button>
      <button
        v-if="step !== 'login'"
        type="button"
        class="edu-link"
        @click="restart"
      >
        {{ t('education.restart') }}
      </button>
      <RouterLink
        v-else
        to="/admin/forgot-password"
        class="edu-link"
      >
        {{ t('education.forgotPassword') }}
      </RouterLink>
      <div class="edu-language">
        <button
          type="button"
          lang="ru"
          :aria-pressed="locale === 'ru'"
          @click="setLocale('ru')"
        >
          Русский
        </button>
        <button
          type="button"
          lang="en"
          :aria-pressed="locale === 'en'"
          @click="setLocale('en')"
        >
          English
        </button>
      </div>
    </AppCard>
  </main>
</template>
