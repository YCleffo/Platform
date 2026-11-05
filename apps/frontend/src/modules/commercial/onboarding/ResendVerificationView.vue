<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { MailCheck } from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { email as emailRule } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const auth = useAuthStore();
const sent = ref(false);

const form = useForm({
  initial: { email: '' },
  fields: {
    email: { id: 'resend-email', rules: [emailRule] },
  },
});

const { submitting, message, run } = useAsyncAction(async () => {
  await auth.resendVerification(form.values.email.trim());
  sent.value = true;
});

async function onSubmit(): Promise<void> {
  if (!form.validate()) {
    return;
  }
  await run();
}
</script>

<template>
  <AppFormShell
    v-if="sent"
    :title="$t('auth.checkEmail')"
    :subtitle="$t('auth.resendPageSuccess')"
    centered
  >
    <template #icon>
      <span
        class="resend-view__icon"
        aria-hidden="true"
      >
        <MailCheck :size="28" />
      </span>
    </template>
    <RouterLink
      class="resend-view__link"
      :to="{ name: 'login' }"
    >
      {{ $t('nav.login') }}
    </RouterLink>
  </AppFormShell>

  <AppFormShell
    v-else
    :title="$t('auth.resendPageTitle')"
    :subtitle="$t('auth.resendPageSubtitle')"
    :error="message"
  >
    <form
      class="auth-form"
      novalidate
      @submit.prevent="onSubmit"
    >
      <AppInput
        id="resend-email"
        v-model="form.values.email"
        :label="$t('auth.email')"
        :error="form.errors.email ? $t(form.errors.email) : undefined"
        type="email"
        autocomplete="email"
        inputmode="email"
        required
      />
      <AppButton
        type="submit"
        :loading="submitting"
        block
      >
        {{ $t('auth.resendPageSubmit') }}
      </AppButton>
      <p class="auth-form__switch">
        <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
      </p>
    </form>
  </AppFormShell>
</template>

<style scoped>
.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.auth-form__switch,
.resend-view__link {
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  text-align: center;
  transition: var(--theme-transition);
}

.resend-view__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--success-soft);
  color: var(--success);
  transition: var(--theme-transition);
}
</style>
