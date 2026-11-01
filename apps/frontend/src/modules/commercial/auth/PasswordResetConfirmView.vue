<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { CircleAlert } from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { password as passwordRule, required } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const hasToken = computed(() => token.value !== '');

const form = useForm({
  initial: { password: '', confirmation: '' },
  fields: {
    password: { id: 'reset-password', rules: [passwordRule] },
    confirmation: { id: 'reset-password-confirm', rules: [required] },
  },
});

const { submitting, message, run } = useAsyncAction(async () => {
  await auth.confirmPasswordReset(token.value, form.values.password);
  await router.replace({ name: 'login', query: { reset: 'success' } });
});

async function onSubmit(): Promise<void> {
  if (!form.validate()) {
    return;
  }
  if (form.values.password !== form.values.confirmation) {
    form.errors.confirmation = 'validation.passwordMismatch';
    document.getElementById('reset-password-confirm')?.focus();
    return;
  }
  await run();
}
</script>

<template>
  <AppFormShell
    v-if="!hasToken"
    :title="$t('auth.resetFailed')"
    :subtitle="$t('auth.resetTokenMissing')"
    centered
  >
    <template #icon>
      <span class="reset-view__icon reset-view__icon--error" aria-hidden="true">
        <CircleAlert :size="28" />
      </span>
    </template>
    <div class="reset-view__actions">
      <RouterLink class="reset-view__link" :to="{ name: 'password-reset-request' }">
        {{ $t('auth.requestNewLink') }}
      </RouterLink>
      <RouterLink class="reset-view__link" :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
    </div>
  </AppFormShell>

  <AppFormShell
    v-else
    :title="$t('auth.resetTitle')"
    :subtitle="$t('auth.resetSubtitle')"
    :error="message"
  >
    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <AppPasswordInput
        id="reset-password"
        v-model="form.values.password"
        :label="$t('auth.newPassword')"
        :error="form.errors.password ? $t(form.errors.password) : undefined"
        autocomplete="new-password"
        required
      />
      <AppPasswordInput
        id="reset-password-confirm"
        v-model="form.values.confirmation"
        :label="$t('auth.confirmPassword')"
        :error="form.errors.confirmation ? $t(form.errors.confirmation) : undefined"
        autocomplete="new-password"
        required
      />
      <AppButton type="submit" :loading="submitting" block>{{ $t('auth.resetSubmit') }}</AppButton>
      <p class="auth-form__switch">
        <RouterLink :to="{ name: 'password-reset-request' }">{{ $t('auth.requestNewLink') }}</RouterLink>
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
.reset-view__link {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  text-align: center;
  transition: var(--theme-transition);
}

.reset-view__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--space-16);
}

.reset-view__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--danger-soft);
  color: var(--danger);
  transition: var(--theme-transition);
}
</style>
