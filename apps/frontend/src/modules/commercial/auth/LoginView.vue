<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';

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
  <section>
    <h1 class="auth-title">{{ $t('auth.signIn') }}</h1>
    <form class="auth-form" @submit.prevent="onSubmit">
      <FormField id="login-email" v-model="email" :label="$t('auth.email')" type="email" autocomplete="email" required />
      <FormField
        id="login-password"
        v-model="password"
        :label="$t('auth.password')"
        type="password"
        autocomplete="current-password"
        required
      />
      <p v-if="errorMessage" class="auth-error">{{ errorMessage }}</p>
      <Button type="submit" :loading="submitting">{{ $t('auth.signIn') }}</Button>
      <p class="auth-switch">
        {{ $t('auth.noAccount') }}
        <RouterLink :to="{ name: 'register' }">{{ $t('nav.register') }}</RouterLink>
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
</style>
