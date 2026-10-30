<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { commercialAuth } from '@/shared/api/commercial';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useToast } from '@/shared/composables/useToast';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { MailCheck } from '@lucide/vue';

const auth = useAuthStore();
const toast = useToast();
const { t } = useI18n();

const email = ref('');
const password = ref('');
const registered = ref(false);
const resending = ref(false);

const { submitting, error, run } = useAsyncAction(async () => {
  await auth.register(email.value, password.value);
  registered.value = true;
});

async function resend(): Promise<void> {
  if (resending.value || email.value === '') {
    return;
  }
  resending.value = true;
  try {
    await commercialAuth.resendVerification(email.value);
    toast.show(t('auth.resendSuccess'), 'success');
  } catch {
    toast.show(t('common.unexpectedError'), 'error');
  } finally {
    resending.value = false;
  }
}
</script>

<template>
  <section class="auth-view">
    <template v-if="registered">
      <div class="auth-view__status">
        <span class="auth-view__status-icon">
          <MailCheck :size="28" aria-hidden="true" />
        </span>
      </div>
      <h1 class="auth-view__title">{{ $t('auth.checkEmail') }}</h1>
      <p class="auth-view__subtitle">{{ $t('auth.registerSuccess') }}</p>
      <div class="auth-view__resend">
        <p class="auth-view__resend-hint">{{ $t('auth.resendTitle') }}</p>
        <AppButton variant="secondary" :loading="resending" :disabled="email === ''" block @click="resend">
          {{ $t('auth.resend') }}
        </AppButton>
      </div>
      <RouterLink class="auth-view__link" :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
    </template>

    <template v-else>
      <h1 class="auth-view__title">{{ $t('auth.signUp') }}</h1>
      <p class="auth-view__subtitle">{{ $t('auth.signUpSubtitle') }}</p>
      <form class="auth-view__form" novalidate @submit.prevent="run()">
        <AppInput
          id="register-email"
          v-model="email"
          :label="$t('auth.email')"
          type="email"
          autocomplete="email"
          inputmode="email"
          required
        />
        <AppPasswordInput
          id="register-password"
          v-model="password"
          :label="$t('auth.password')"
          autocomplete="new-password"
          required
        />
        <p v-if="error" class="auth-view__error" role="alert">{{ error }}</p>
        <AppButton type="submit" :loading="submitting" block>
          {{ $t('auth.register') }}
        </AppButton>
        <p class="auth-view__switch">
          {{ $t('auth.haveAccount') }}
          <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
        </p>
      </form>
    </template>
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

.auth-view__status {
  display: flex;
  justify-content: center;
}

.auth-view__status-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--success-soft);
  color: var(--success);
}

.auth-view__resend {
  display: grid;
  gap: var(--space-8);
}

.auth-view__resend-hint {
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
}

.auth-view__link {
  text-align: center;
  font-size: 14px;
  line-height: 20px;
  font-weight: 500;
}

@media (min-width: 768px) {
  .auth-view__title {
    font-size: 24px;
    line-height: 32px;
  }
}
</style>
