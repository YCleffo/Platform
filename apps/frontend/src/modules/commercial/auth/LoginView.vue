<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { email as emailRule, required } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const redirectTarget = computed(() =>
  typeof route.query.redirect === 'string' ? route.query.redirect : null,
);

const form = useForm({
  initial: { email: '', password: '' },
  fields: {
    email: { id: 'login-email', rules: [emailRule] },
    password: { id: 'login-password', rules: [required] },
  },
});

const { submitting, message, run } = useAsyncAction(async () => {
  await auth.login(form.values.email, form.values.password);
  if (auth.mfaChallenge !== null) {
    await router.push({
      name: 'mfa',
      query: redirectTarget.value !== null ? { redirect: redirectTarget.value } : {},
    });
    return;
  }
  await router.push(redirectTarget.value !== null ? { path: redirectTarget.value } : { name: 'cabinet' });
});

async function onSubmit(): Promise<void> {
  if (!form.validate()) {
    return;
  }
  await run();
}
</script>

<template>
  <AppFormShell :title="$t('auth.signIn')" :subtitle="$t('auth.signInSubtitle')" :error="message">
    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="login-email"
        v-model="form.values.email"
        :label="$t('auth.email')"
        :error="form.errors.email ? $t(form.errors.email) : undefined"
        type="email"
        autocomplete="email"
        inputmode="email"
        required
      />
      <AppPasswordInput
        id="login-password"
        v-model="form.values.password"
        :label="$t('auth.password')"
        :error="form.errors.password ? $t(form.errors.password) : undefined"
        autocomplete="current-password"
        required
      />
      <AppButton type="submit" :loading="submitting" block>{{ $t('auth.signIn') }}</AppButton>
      <p class="auth-form__switch">
        {{ $t('auth.noAccount') }}
        <RouterLink :to="{ name: 'register', query: redirectTarget ? { redirect: redirectTarget } : {} }">
          {{ $t('nav.register') }}
        </RouterLink>
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

.auth-form__switch {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  text-align: center;
}
</style>
