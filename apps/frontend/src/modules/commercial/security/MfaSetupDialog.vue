<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { QrcodeSvg } from 'qrcode.vue';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useMfaEnrollment } from './useMfaEnrollment';
import { useSensitiveAction } from '@/shared/composables/useSensitiveAction';
import ReauthenticateDialog from './ReauthenticateDialog.vue';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  enabled: [];
}>();

const {
  setup,
  recoveryCodes,
  loading: enrollmentLoading,
  error: enrollmentError,
  begin,
  confirm: confirmCode,
  reset,
} = useMfaEnrollment();

const { run, resume, cancel, reauthOpen, busy, error: actionError } = useSensitiveAction();

const code = ref('');

const errorText = computed(() => {
  const failure = actionError.value ?? enrollmentError.value;
  return failure === null || failure === undefined ? null : resolveErrorMessage(failure);
});

watch(
  open,
  (value) => {
    if (value) {
      reset();
      code.value = '';
      void start();
    } else {
      reset();
    }
  },
  { immediate: true },
);

async function start(): Promise<void> {
  const completed = await run(async () => {
    await begin();
  });
  if (!completed && setup.value === null && errorText.value === null) {
    open.value = false;
  }
}

async function confirm(): Promise<void> {
  if (code.value.trim() === '' || busy.value) {
    return;
  }
  const completed = await run(async () => {
    await confirmCode(code.value.trim());
  });
  if (completed && recoveryCodes.value.length > 0) {
    emit('enabled');
  }
}

function done(): void {
  open.value = false;
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('security.mfaSetupTitle')"
    :persistent="busy || enrollmentLoading"
  >
    <div
      v-if="recoveryCodes.length > 0"
      class="mfa-setup"
    >
      <h3 class="mfa-setup__title">{{ $t('security.recoveryTitle') }}</h3>
      <RecoveryCodesPanel :codes="recoveryCodes">
        <AppButton
          type="button"
          @click="done"
        >
          {{ $t('security.recoveryDone') }}
        </AppButton>
      </RecoveryCodesPanel>
    </div>

    <div
      v-else-if="setup"
      class="mfa-setup"
    >
      <p class="mfa-setup__intro">{{ $t('security.mfaSetupIntro') }}</p>
      <div class="mfa-setup__qr">
        <QrcodeSvg
          :value="setup.otpauth_uri"
          :size="200"
          level="M"
          :margin="2"
          :aria-label="$t('security.qrLabel')"
        />
      </div>
      <div class="mfa-setup__manual">
        <span class="mfa-setup__manual-label">{{ $t('security.manualSecret') }}</span>
        <code class="mfa-setup__secret">{{ setup.secret }}</code>
      </div>
      <AppInput
        id="totp-code"
        v-model="code"
        :label="$t('security.codeLabel')"
        inputmode="numeric"
        autocomplete="one-time-code"
      />
      <p
        v-if="errorText"
        class="mfa-setup__error"
        role="alert"
      >
        {{ errorText }}
      </p>
      <AppButton
        type="button"
        :loading="busy"
        @click="confirm"
      >
        {{ $t('security.confirm') }}
      </AppButton>
    </div>

    <div
      v-else
      class="mfa-setup"
    >
      <p
        v-if="errorText"
        class="mfa-setup__error"
        role="alert"
      >
        {{ errorText }}
      </p>
      <p
        v-else
        class="mfa-setup__intro"
      >
        {{ $t('common.loading') }}
      </p>
      <AppButton
        v-if="errorText"
        type="button"
        variant="secondary"
        @click="start"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </div>

    <ReauthenticateDialog
      v-model="reauthOpen"
      @authenticated="resume"
      @cancelled="cancel"
    />
  </AppDialog>
</template>

<style scoped>
.mfa-setup {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.mfa-setup__title {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.mfa-setup__intro {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.mfa-setup__qr {
  align-self: center;
  display: inline-flex;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  background: var(--color-white);
  transition: var(--theme-transition);
}

.mfa-setup__manual {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.mfa-setup__manual-label {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.mfa-setup__secret {
  font-family: var(--font-mono);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
  overflow-wrap: anywhere;
  transition: var(--theme-transition);
}

.mfa-setup__error {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
  transition: var(--theme-transition);
}
</style>
