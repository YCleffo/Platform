<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ShieldCheck } from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const redirectTarget = computed(() =>
  typeof route.query.redirect === 'string' ? route.query.redirect : null,
);
const { t } = useI18n();
const controller = new AbortController();
let active = true;

onBeforeUnmount(() => {
  active = false;
  controller.abort();
});

const form = useForm({
  initial: { code: '', recoveryCode: '' },
  fields: {
    code: { id: 'mfa-code', rules: [] },
    recoveryCode: { id: 'mfa-recovery', rules: [] },
  },
});

const crossError = ref<string | null>(null);

const { submitting, message, run } = useAsyncAction(async () => {
  await auth.verifyMfa(
    form.values.code.trim() !== '' ? form.values.code.trim() : undefined,
    form.values.recoveryCode.trim() !== '' ? form.values.recoveryCode.trim() : undefined,
    controller.signal,
  );
  if (!active) {
    return;
  }
  await router.push(redirectTarget.value !== null ? { path: redirectTarget.value } : { name: 'cabinet' });
});

onMounted(() => {
  if (auth.mfaChallenge === null) {
    void router.push({ name: 'login' });
  }
});

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return;
  }
  if (auth.mfaChallenge === null) {
    await router.push({ name: 'login' });
    return;
  }
  const hasCode = form.values.code.trim() !== '';
  const hasRecovery = form.values.recoveryCode.trim() !== '';
  if (hasCode === hasRecovery) {
    crossError.value = t('auth.mfaInvalid');
    return;
  }
  crossError.value = null;
  await run();
}
</script>

<template>
  <AppFormShell
    :title="$t('auth.mfaTitle')"
    :subtitle="$t('auth.mfaIntro')"
    :error="message ?? crossError"
    centered
  >
    <template #icon>
      <span class="mfa__icon" aria-hidden="true">
        <ShieldCheck :size="28" />
      </span>
    </template>
    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="mfa-code"
        v-model="form.values.code"
        :label="$t('auth.mfaCode')"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="••••••"
      />
      <AppInput
        id="mfa-recovery"
        v-model="form.values.recoveryCode"
        :label="$t('auth.mfaRecoveryCode')"
        autocomplete="off"
      />
      <AppButton type="submit" :loading="submitting" block>{{ $t('auth.mfaSubmit') }}</AppButton>
    </form>
  </AppFormShell>
</template>

<style scoped>
.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
  text-align: left;
}

.mfa__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--accent-soft);
  color: var(--accent-text);
  transition: var(--theme-transition);
}
</style>
