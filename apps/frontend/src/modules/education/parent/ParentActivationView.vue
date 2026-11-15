<script setup lang="ts">
import { onScopeDispose, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession } from '@/core/admin/session';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import { parentApi } from './api';
import '../education.css';
const auth = useAdminAuthStore();
const router = useRouter();
const { t } = useEducationI18n();
const issue = useEducationError();
const code = ref(''),
  email = ref(''),
  password = ref(''),
  repeated = ref('');
const registering = ref(false),
  busy = ref(false),
  error = ref('');
let active = true;
let controller: AbortController | null = null;
watch(
  () => auth.isAuthenticated,
  (value) => {
    if (!value) {
      controller?.abort();
      code.value = '';
      password.value = '';
      repeated.value = '';
    }
  },
);
async function activate(): Promise<void> {
  if (busy.value) return;
  error.value = '';
  if (registering.value && (password.value.length < 12 || password.value !== repeated.value)) {
    error.value = t('education.passwordMismatch');
    return;
  }
  busy.value = true;
  const request = new AbortController();
  controller = request;
  const epoch = coreAdminSession.epoch();
  try {
    if (registering.value && !auth.isAuthenticated) {
      await parentApi.register(
        { code: code.value.trim(), email: email.value.trim(), password: password.value },
        request.signal,
      );
      if (!active || request.signal.aborted || epoch !== coreAdminSession.epoch()) return;
      await auth.login(email.value, password.value);
    } else await parentApi.activate(code.value.trim(), request.signal);
    if (active && !request.signal.aborted && auth.isAuthenticated) {
      code.value = '';
      await router.replace('/parent/home');
    }
  } catch (failure) {
    if (active && !request.signal.aborted) error.value = issue(failure);
  } finally {
    password.value = '';
    repeated.value = '';
    if (active) busy.value = false;
  }
}
onScopeDispose(() => {
  active = false;
  controller?.abort();
  code.value = '';
  password.value = '';
  repeated.value = '';
});
</script>
<template>
  <main class="education-login">
    <section class="edu-login-card">
      <p class="edu-brand">Education</p>
      <h1>{{ t('education.activateParentInvitation') }}</h1>
      <p>{{ t('education.parentActivationHint') }}</p>
      <template v-if="!auth.isAuthenticated && !registering">
        <p>
          <RouterLink
            :to="{ name: 'education-login', query: { redirect: '/parent/activate' } }"
            class="edu-button edu-button--primary"
          >
            {{ t('education.parentExistingAccount') }}
          </RouterLink>
        </p>
        <button
          type="button"
          class="edu-link"
          @click="registering = true"
        >
          {{ t('education.parentNewAccount') }}
        </button>
      </template>
      <form
        v-else
        class="edu-form"
        @submit.prevent="activate"
      >
        <AppInput
          id="parent-invite-code"
          v-model="code"
          :label="t('education.inviteCode')"
          maxlength="128"
          autocomplete="off"
          required
          :disabled="busy"
        />
        <template v-if="registering && !auth.isAuthenticated">
          <AppInput
            id="parent-invite-email"
            v-model="email"
            type="email"
            :label="t('education.email')"
            autocomplete="username"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="parent-invite-password"
            v-model="password"
            :label="t('education.newPassword')"
            autocomplete="new-password"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="parent-invite-repeat"
            v-model="repeated"
            :label="t('education.repeatPassword')"
            autocomplete="new-password"
            required
            :disabled="busy"
          />
          <p class="edu-subtle">{{ t('education.parentNewAccountHint') }}</p>
        </template>
        <p
          v-if="error"
          class="edu-error"
          role="alert"
        >
          {{ error }}
        </p>
        <button
          type="submit"
          class="edu-button edu-button--primary"
          :disabled="busy"
        >
          {{ t(busy ? 'education.loading' : 'education.activate') }}
        </button>
      </form>
      <p>
        <RouterLink
          v-if="auth.isAuthenticated"
          to="/parent/home"
          class="edu-link"
        >
          {{ t('education.parentCabinet') }}
        </RouterLink>
        <RouterLink
          v-else
          to="/education/login"
          class="edu-link"
        >
          {{ t('education.back') }}
        </RouterLink>
      </p>
    </section>
  </main>
</template>
