<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
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

const mode = ref<'code' | 'recovery'>('code');
const isFocused = ref(false);
const isSuccess = ref(false);
const isShaking = ref(false);
let shakeTimer: ReturnType<typeof setTimeout> | undefined;
const codeInputRef = ref<HTMLInputElement | null>(null);

function triggerShake(): void {
  isShaking.value = true;
  if (shakeTimer) clearTimeout(shakeTimer);
  shakeTimer = setTimeout(() => {
    isShaking.value = false;
  }, 450);
}

onBeforeUnmount(() => {
  active = false;
  controller.abort();
  if (shakeTimer) clearTimeout(shakeTimer);
});

const form = useForm({
  initial: { code: '', recoveryCode: '' },
  fields: {
    code: { id: 'mfa-code', rules: [] },
    recoveryCode: { id: 'mfa-recovery', rules: [] },
  },
});

const digits = computed(() => {
  const chars = form.values.code.split('');
  return Array.from({ length: 6 }, (_, i) => chars[i] ?? '');
});

const activeCellIndex = computed(() => Math.min(form.values.code.length, 5));

function focusCodeInput(): void {
  codeInputRef.value?.focus();
}

function onCodeInput(event: Event): void {
  const target = event.target as HTMLInputElement;
  const cleaned = target.value.replace(/\D/g, '').slice(0, 6);
  form.values.code = cleaned;
  target.value = cleaned;
  if (crossError.value) {
    crossError.value = null;
  }
  if (cleaned.length === 6 && !submitting.value && !isSuccess.value) {
    void onSubmit();
  }
}

function switchToRecovery(): void {
  mode.value = 'recovery';
  crossError.value = null;
  void nextTick(() => {
    document.getElementById('mfa-recovery')?.focus();
  });
}

function switchToCode(): void {
  mode.value = 'code';
  crossError.value = null;
  void nextTick(() => {
    focusCodeInput();
  });
}

const crossError = ref<string | null>(null);

const { submitting, message, run } = useAsyncAction(async () => {
  const code =
    mode.value === 'code' && form.values.code.trim() !== '' ? form.values.code.trim() : undefined;
  const recoveryCode =
    mode.value === 'recovery' && form.values.recoveryCode.trim() !== ''
      ? form.values.recoveryCode.trim()
      : undefined;

  await auth.verifyMfa(code, recoveryCode, controller.signal);
  if (!active) {
    return;
  }
  isSuccess.value = true;
  await new Promise((resolve) => setTimeout(resolve, 450));
  if (!active) {
    return;
  }
  await router.push(redirectTarget.value !== null ? redirectTarget.value : { name: 'cabinet' });
});

onMounted(() => {
  if (auth.mfaChallenge === null) {
    void router.push({ name: 'login' });
  } else {
    void nextTick(() => {
      focusCodeInput();
    });
  }
});

async function onSubmit(): Promise<void> {
  if (submitting.value || isSuccess.value) {
    return;
  }
  if (auth.mfaChallenge === null) {
    await router.push({ name: 'login' });
    return;
  }
  if (mode.value === 'code') {
    const code = form.values.code.trim();
    if (code.length !== 6) {
      crossError.value = t('coreAuth.totpInvalid');
      triggerShake();
      focusCodeInput();
      return;
    }
    form.values.recoveryCode = '';
  } else {
    const recovery = form.values.recoveryCode.trim();
    if (recovery === '') {
      crossError.value = t('auth.mfaInvalid');
      triggerShake();
      return;
    }
    form.values.code = '';
  }
  crossError.value = null;
  const ok = await run();
  if (!ok) {
    triggerShake();
    if (mode.value === 'code') {
      form.values.code = '';
      if (codeInputRef.value) {
        codeInputRef.value.value = '';
      }
      focusCodeInput();
    }
  }
}
</script>

<template>
  <AppFormShell
    :title="$t('auth.mfaTitle')"
    :subtitle="mode === 'code' ? $t('auth.mfaCodeIntro') : $t('auth.mfaRecoveryIntro')"
    :error="message ?? crossError"
    centered
  >
    <template #icon>
      <span
        class="mfa__icon"
        :class="{ 'mfa__icon--success': isSuccess }"
        aria-hidden="true"
      >
        <ShieldCheck :size="28" />
      </span>
    </template>
    <form
      class="auth-form"
      novalidate
      @submit.prevent="onSubmit"
    >
      <!-- Authenticator Code 6-cell Input -->
      <template v-if="mode === 'code'">
        <div class="mfa-pin-wrapper">
          <label
            for="mfa-code"
            class="mfa-pin__label"
          >
            {{ $t('auth.mfaCode') }}
          </label>
          <div
            class="mfa-pin"
            :class="{
              'mfa-pin--focused': isFocused,
              'mfa-pin--error': Boolean(message ?? crossError),
              'mfa-pin--shake': isShaking,
              'mfa-pin--success': isSuccess,
            }"
            @click="focusCodeInput"
          >
            <input
              id="mfa-code"
              ref="codeInputRef"
              v-model="form.values.code"
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              autocomplete="one-time-code"
              maxlength="6"
              class="mfa-pin__input"
              :aria-label="$t('auth.mfaCode')"
              :disabled="submitting || isSuccess"
              @focus="isFocused = true"
              @blur="isFocused = false"
              @input="onCodeInput"
            />
            <div
              class="mfa-pin__cells"
              aria-hidden="true"
            >
              <div
                v-for="idx in 6"
                :key="idx"
                class="mfa-pin__cell"
                :class="{
                  'mfa-pin__cell--active': isFocused && activeCellIndex === idx - 1,
                  'mfa-pin__cell--filled': Boolean(digits[idx - 1]),
                }"
              >
                <span class="mfa-pin__digit">{{ digits[idx - 1] || '' }}</span>
                <span
                  v-if="isFocused && activeCellIndex === idx - 1 && !digits[idx - 1]"
                  class="mfa-pin__caret"
                />
              </div>
            </div>
          </div>
        </div>

        <AppButton
          type="submit"
          :loading="submitting && !isSuccess"
          :disabled="isSuccess"
          block
        >
          {{ isSuccess ? '✓' : $t('auth.mfaSubmit') }}
        </AppButton>

        <p class="auth-form__aux">
          <button
            id="mfa-toggle-recovery"
            type="button"
            class="auth-link-button"
            :disabled="submitting"
            @click="switchToRecovery"
          >
            {{ $t('auth.mfaUseRecovery') }}
          </button>
        </p>
      </template>

      <!-- Recovery Code Input -->
      <template v-else>
        <AppInput
          id="mfa-recovery"
          v-model="form.values.recoveryCode"
          :label="$t('auth.mfaRecoveryCode')"
          autocomplete="off"
          :disabled="submitting"
          placeholder="••••••••••••"
        />

        <AppButton
          type="submit"
          :loading="submitting"
          block
        >
          {{ $t('auth.mfaSubmit') }}
        </AppButton>

        <p class="auth-form__aux">
          <button
            id="mfa-toggle-code"
            type="button"
            class="auth-link-button"
            :disabled="submitting"
            @click="switchToCode"
          >
            {{ $t('auth.mfaUseCode') }}
          </button>
        </p>
      </template>
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
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease),
    background-color var(--motion-fast-duration) var(--motion-ease),
    color var(--motion-fast-duration) var(--motion-ease);
}

.mfa__icon--success {
  background: var(--success-soft);
  color: var(--success);
  transform: scale(1.08);
}

.mfa-pin-wrapper {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
  width: 100%;
}

.mfa-pin__label {
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-emphasis-line);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.mfa-pin {
  position: relative;
  display: flex;
  justify-content: center;
  width: 100%;
}

.mfa-pin--shake {
  animation: mfa-shake var(--motion-control-duration) var(--motion-ease);
}

@keyframes mfa-shake {
  0%,
  100% {
    transform: translateX(0);
  }
  20%,
  60% {
    transform: translateX(-8px);
  }
  40%,
  80% {
    transform: translateX(8px);
  }
}

.mfa-pin__input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0.01;
  background: transparent;
  color: transparent;
  border: none;
  outline: none;
  caret-color: transparent;
  cursor: text;
  z-index: 2;
}

.mfa-pin__cells {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-8);
  width: 100%;
  max-width: 380px;
  margin: 0 auto;
}

@media (max-width: 360px) {
  .mfa-pin__cells {
    gap: var(--space-4);
  }
}

.mfa-pin__cell {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1 1 0;
  min-width: 0;
  max-width: 54px;
  height: 58px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  box-shadow: var(--shadow-soft);
  transition:
    var(--theme-transition),
    border-color var(--motion-fast-duration) var(--motion-ease),
    background-color var(--motion-fast-duration) var(--motion-ease),
    box-shadow var(--motion-fast-duration) var(--motion-ease),
    transform var(--motion-fast-duration) var(--motion-ease);
}

@media (max-width: 360px) {
  .mfa-pin__cell {
    height: 48px;
  }
}

.mfa-pin__cell--active {
  border-color: var(--focus-border);
  box-shadow: none;
  transform: translateY(-2px);
}

.mfa-pin__cell--filled {
  border-color: var(--border-strong, var(--border));
  background: var(--surface-2, var(--surface));
}

.mfa-pin--error .mfa-pin__cell {
  border-color: var(--color-danger-500);
}

.mfa-pin--success .mfa-pin__cell {
  border-color: var(--success);
  background: var(--success-soft);
  box-shadow: 0 0 16px var(--success-soft);
  transform: scale(1.04);
}

.mfa-pin--success .mfa-pin__digit {
  color: var(--success);
}

.mfa-pin__digit {
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  line-height: var(--type-h2-line);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
  user-select: none;
}

@keyframes mfa-pin-caret {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0;
  }
}

.mfa-pin__caret {
  display: inline-block;
  width: 2px;
  height: 24px;
  background: var(--accent);
  border-radius: 1px;
  animation: mfa-pin-caret var(--motion-caret-duration) var(--motion-pulse-ease) infinite;
}

.auth-form__aux {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  text-align: center;
  margin: 0;
}

.auth-link-button {
  display: inline-block;
  background: none;
  border: none;
  padding: 0;
  color: var(--color-link);
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  cursor: pointer;
  text-decoration: none;
  transition: var(--theme-transition);
}

.auth-link-button:hover:not(:disabled) {
  text-decoration: underline;
  color: var(--color-link-hover);
}

.auth-link-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
