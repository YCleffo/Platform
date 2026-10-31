<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { CircleAlert, CircleCheck, LoaderCircle } from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const state = ref<'loading' | 'success' | 'error'>('loading');
const errorMessage = ref('');

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : '';
  if (token === '') {
    state.value = 'error';
    errorMessage.value = t('auth.verifyTokenMissing');
    return;
  }
  try {
    await auth.verifyEmail(token);
    state.value = 'success';
  } catch (err) {
    state.value = 'error';
    errorMessage.value = resolveErrorMessage(err);
  }
});
</script>

<template>
  <section class="verify-view">
    <div v-if="state === 'loading'" class="verify-view__state" role="status" aria-live="polite">
      <span class="verify-view__icon verify-view__icon--loading">
        <LoaderCircle :size="28" class="verify-view__spin" aria-hidden="true" />
      </span>
      <h1 class="verify-view__title">{{ $t('auth.verifyTitle') }}</h1>
      <p class="verify-view__text">{{ $t('auth.verifyIntro') }}</p>
    </div>

    <div v-else-if="state === 'success'" class="verify-view__state">
      <span class="verify-view__icon verify-view__icon--success">
        <CircleCheck :size="28" aria-hidden="true" />
      </span>
      <h1 class="verify-view__title">{{ $t('auth.verifySuccess') }}</h1>
      <p class="verify-view__text">{{ $t('auth.verifySuccessText') }}</p>
      <AppButton block @click="router.push({ name: 'login' })">{{ $t('nav.login') }}</AppButton>
    </div>

    <div v-else class="verify-view__state">
      <span class="verify-view__icon verify-view__icon--error">
        <CircleAlert :size="28" aria-hidden="true" />
      </span>
      <h1 class="verify-view__title">{{ $t('auth.verifyFailed') }}</h1>
      <p class="verify-view__text" role="alert">{{ errorMessage }}</p>
      <div class="verify-view__actions">
        <RouterLink class="verify-view__link" :to="{ name: 'register' }">
          {{ $t('auth.resend') }}
        </RouterLink>
        <RouterLink class="verify-view__link" :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
      </div>
    </div>
  </section>
</template>

<style scoped>
.verify-view {
  display: flex;
  justify-content: center;
}

.verify-view__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-8);
  text-align: center;
}

.verify-view__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
}

.verify-view__icon--loading {
  background: var(--accent-soft);
  color: var(--accent-text);
  transition: var(--theme-transition);
}

.verify-view__icon--success {
  background: var(--success-soft);
  color: var(--success);
  transition: var(--theme-transition);
}

.verify-view__icon--error {
  background: var(--danger-soft);
  color: var(--danger);
  transition: var(--theme-transition);
}

.verify-view__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.verify-view__text {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  max-width: 320px;
  transition: var(--theme-transition);
}

.verify-view__actions {
  display: flex;
  align-items: center;
  gap: var(--space-16);
}

.verify-view__link {
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.verify-view__spin {
  animation: verify-spin var(--motion-spinner-duration) var(--motion-linear) infinite;
}

@keyframes verify-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
