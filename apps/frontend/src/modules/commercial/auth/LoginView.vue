<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const { t } = useI18n();

const email = ref('');
const password = ref('');
const submitting = ref(false);
const errorMessage = ref<string | null>(null);

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return;
  }
  submitting.value = true;
  errorMessage.value = null;
  try {
    await auth.login(email.value, password.value);
    if (auth.mfaChallenge !== null) {
      await router.push({ name: 'mfa' });
      return;
    }
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null;
    await router.push(redirect ? { path: redirect } : { name: 'cabinet' });
  } catch (err) {
    const key = errorCodeKey(err);
    errorMessage.value = key !== null ? t(key) : toErrorMessage(err);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <section class="auth-view">
    <h1 class="auth-view__title">{{ $t('auth.signIn') }}</h1>
    <p class="auth-view__subtitle">{{ $t('auth.signInSubtitle') }}</p>

    <form class="auth-view__form" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="login-email"
        v-model="email"
        :label="$t('auth.email')"
        type="email"
        autocomplete="email"
        inputmode="email"
        required
      />
      <AppPasswordInput
        id="login-password"
        v-model="password"
        :label="$t('auth.password')"
        autocomplete="current-password"
        required
      />
      <p v-if="errorMessage" class="auth-view__error" role="alert">{{ errorMessage }}</p>
      <AppButton type="submit" :loading="submitting" block>
        {{ $t('auth.signIn') }}
      </AppButton>
      <p class="auth-view__switch">
        {{ $t('auth.noAccount') }}
        <RouterLink :to="{ name: 'register' }">{{ $t('nav.register') }}</RouterLink>
      </p>
    </form>
  </section>
</template>

<style scoped>
.auth-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-8);
}

.auth-view__title {
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  letter-spacing: -0.005em;
  color: var(--text-1);
}

.auth-view__subtitle {
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
}

.auth-view__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
  margin-top: var(--space-8);
}

.auth-view__error {
  font-size: 14px;
  line-height: 20px;
  color: var(--danger-text);
}

.auth-view__switch {
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
  text-align: center;
}

@media (min-width: 768px) {
  .auth-view__title {
    font-size: 24px;
    line-height: 32px;
  }
}
</style>
