<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ShieldCheck } from '@lucide/vue';
import { useAuthStore } from '@/core/auth/authStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const auth = useAuthStore();
const router = useRouter();
const { t } = useI18n();

const code = ref('');
const recoveryCode = ref('');
const submitting = ref(false);
const errorMessage = ref<string | null>(null);

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return;
  }
  const hasCode = code.value.trim() !== '';
  const hasRecovery = recoveryCode.value.trim() !== '';
  if (hasCode === hasRecovery) {
    errorMessage.value = t('auth.mfaInvalid');
    return;
  }
  submitting.value = true;
  errorMessage.value = null;
  try {
    await auth.verifyMfa(hasCode ? code.value.trim() : undefined, hasRecovery ? recoveryCode.value.trim() : undefined);
    await router.push({ name: 'cabinet' });
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
    <div class="auth-view__mfa-head">
      <span class="auth-view__mfa-icon" aria-hidden="true">
        <ShieldCheck :size="28" />
      </span>
    </div>
    <h1 class="auth-view__title">{{ $t('auth.mfaTitle') }}</h1>
    <p class="auth-view__subtitle">{{ $t('auth.mfaIntro') }}</p>

    <form class="auth-view__form" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="mfa-code"
        v-model="code"
        :label="$t('auth.mfaCode')"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="••••••"
      />
      <AppInput
        id="mfa-recovery"
        v-model="recoveryCode"
        :label="$t('auth.mfaRecoveryCode')"
        autocomplete="off"
      />
      <p v-if="errorMessage" class="auth-view__error" role="alert">{{ errorMessage }}</p>
      <AppButton type="submit" :loading="submitting" block>
        {{ $t('auth.mfaSubmit') }}
      </AppButton>
    </form>
  </section>
</template>

<style scoped>
.auth-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-8);
}

.auth-view__mfa-head {
  display: flex;
  justify-content: center;
}

.auth-view__mfa-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--accent-soft);
  color: var(--accent);
}

.auth-view__title {
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  letter-spacing: -0.005em;
  color: var(--text-1);
  text-align: center;
}

.auth-view__subtitle {
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
  text-align: center;
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

@media (min-width: 768px) {
  .auth-view__title {
    font-size: 24px;
    line-height: 32px;
  }
}
</style>
