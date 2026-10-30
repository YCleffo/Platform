<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { MailCheck } from '@lucide/vue';
import { useAuthStore } from '@/core/auth/authStore';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { email as emailRule, password as passwordRule } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const auth = useAuthStore();
const route = useRoute();
const redirectTarget = computed(() =>
  typeof route.query.redirect === 'string' ? route.query.redirect : null,
);
const toast = useToast();
const { t } = useI18n();

const registered = ref(false);
const resending = ref(false);

const form = useForm({
  initial: { email: '', password: '' },
  fields: {
    email: { id: 'register-email', rules: [emailRule] },
    password: { id: 'register-password', rules: [passwordRule] },
  },
});

const { submitting, message, run } = useAsyncAction(async () => {
  await auth.register(form.values.email, form.values.password);
  registered.value = true;
});

async function onSubmit(): Promise<void> {
  if (!form.validate()) {
    return;
  }
  await run();
}

async function resend(): Promise<void> {
  if (resending.value || form.values.email === '') {
    return;
  }
  resending.value = true;
  try {
    await auth.resendVerification(form.values.email);
    toast.show(t('auth.resendSuccess'), 'success');
  } catch (err) {
    const text = resolveErrorMessage(err);
    if (text !== '') {
      toast.show(text, 'error');
    }
  } finally {
    resending.value = false;
  }
}
</script>

<template>
  <AppFormShell
    v-if="registered"
    :title="$t('auth.checkEmail')"
    :subtitle="$t('auth.registerSuccess')"
    centered
  >
    <template #icon>
      <span class="register__icon" aria-hidden="true">
        <MailCheck :size="28" />
      </span>
    </template>
    <div class="register__resend">
      <p class="register__hint">{{ $t('auth.resendTitle') }}</p>
      <AppButton variant="secondary" :loading="resending" :disabled="form.values.email === ''" block @click="resend">
        {{ $t('auth.resend') }}
      </AppButton>
      <RouterLink class="register__link" :to="{ name: 'login', query: redirectTarget ? { redirect: redirectTarget } : {} }">
        {{ $t('nav.login') }}
      </RouterLink>
    </div>
  </AppFormShell>

  <AppFormShell v-else :title="$t('auth.signUp')" :subtitle="$t('auth.signUpSubtitle')" :error="message">
    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="register-email"
        v-model="form.values.email"
        :label="$t('auth.email')"
        :error="form.errors.email ? $t(form.errors.email) : undefined"
        type="email"
        autocomplete="email"
        inputmode="email"
        required
      />
      <AppPasswordInput
        id="register-password"
        v-model="form.values.password"
        :label="$t('auth.password')"
        :error="form.errors.password ? $t(form.errors.password) : undefined"
        autocomplete="new-password"
        required
      />
      <AppButton type="submit" :loading="submitting" block>{{ $t('auth.register') }}</AppButton>
      <p class="auth-form__switch">
        {{ $t('auth.haveAccount') }}
        <RouterLink :to="{ name: 'login', query: redirectTarget ? { redirect: redirectTarget } : {} }">
          {{ $t('nav.login') }}
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

.register__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--success-soft);
  color: var(--success);
}

.register__resend {
  display: grid;
  gap: var(--space-12);
  width: 100%;
}

.register__hint {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}

.register__link {
  text-align: center;
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
</style>
