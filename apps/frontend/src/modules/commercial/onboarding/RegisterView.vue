<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { commercialAuth } from '@/shared/api/commercial';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useToast } from '@/shared/composables/useToast';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';

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
  <section>
    <h1 class="auth-title">{{ $t('auth.signUp') }}</h1>

    <div v-if="registered" class="auth-success">
      <p>{{ $t('auth.registerSuccess') }}</p>
      <p class="auth-resend">{{ $t('auth.resendTitle') }}</p>
      <Button variant="secondary" :loading="resending" :disabled="email === ''" @click="resend">
        {{ $t('auth.resend') }}
      </Button>
      <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
    </div>

    <form v-else class="auth-form" @submit.prevent="run()">
      <FormField id="email" v-model="email" :label="$t('auth.email')" type="email" autocomplete="email" required />
      <FormField
        id="password"
        v-model="password"
        :label="$t('auth.password')"
        type="password"
        autocomplete="new-password"
        required
      />
      <p v-if="error" class="auth-error">{{ error }}</p>
      <Button type="submit" :loading="submitting">{{ $t('auth.register') }}</Button>
      <p class="auth-switch">
        {{ $t('auth.haveAccount') }}
        <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
      </p>
    </form>
  </section>
</template>

<style scoped>
.auth-title {
  font-size: var(--text-xl);
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.auth-error {
  color: var(--color-danger);
  font-size: var(--text-sm);
}

.auth-switch {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}

.auth-success {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.auth-resend {
  color: var(--color-text-muted);
  font-size: var(--text-sm);
}
</style>
