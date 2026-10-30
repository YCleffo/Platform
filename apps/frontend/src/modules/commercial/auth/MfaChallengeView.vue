<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';

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
  <section>
    <h1 class="auth-title">{{ $t('auth.mfaTitle') }}</h1>
    <p class="auth-intro">{{ $t('auth.mfaIntro') }}</p>
    <form class="auth-form" @submit.prevent="onSubmit">
      <FormField id="mfa-code" v-model="code" :label="$t('auth.mfaCode')" type="text" inputmode="numeric" />
      <FormField id="mfa-recovery" v-model="recoveryCode" :label="$t('auth.mfaRecoveryCode')" type="text" />
      <p v-if="errorMessage" class="auth-error">{{ errorMessage }}</p>
      <Button type="submit" :loading="submitting">{{ $t('auth.mfaSubmit') }}</Button>
    </form>
  </section>
</template>

<style scoped>
.auth-title {
  font-size: var(--text-xl);
}

.auth-intro {
  color: var(--color-text-muted);
  font-size: var(--text-sm);
  margin-top: var(--space-2);
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin-top: var(--space-4);
}

.auth-error {
  color: var(--color-danger);
  font-size: var(--text-sm);
}
</style>
