<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { commercialAuth } from '@/core/api';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  authenticated: [];
  cancelled: [];
}>();

const { t } = useI18n();

const mfaEnabled = ref(false);
const password = ref('');
const mode = ref<'code' | 'recovery'>('code');
const code = ref('');
const recoveryCode = ref('');
const submitting = ref(false);
const formError = ref<string | null>(null);
const passwordError = ref<string | null>(null);
const codeError = ref<string | null>(null);
let succeeded = false;

watch(open, async (value) => {
  if (value) {
    succeeded = false;
    password.value = '';
    code.value = '';
    recoveryCode.value = '';
    mode.value = 'code';
    submitting.value = false;
    formError.value = null;
    passwordError.value = null;
    codeError.value = null;
    mfaEnabled.value = false;
    try {
      const status = await commercialAuth.mfaStatus();
      mfaEnabled.value = status.enabled;
    } catch {
      // Password-only reauthentication remains possible; the API is authoritative.
    }
    return;
  }
  if (!succeeded) {
    emit('cancelled');
  }
}, { immediate: true });

async function submit(): Promise<void> {
  if (submitting.value) {
    return;
  }
  formError.value = null;
  passwordError.value = null;
  codeError.value = null;
  if (password.value === '') {
    passwordError.value = t('validation.required');
    return;
  }
  const hasCode = mode.value === 'code' && code.value.trim() !== '';
  const hasRecovery = mode.value === 'recovery' && recoveryCode.value.trim() !== '';
  if (mfaEnabled.value && !hasCode && !hasRecovery) {
    codeError.value = t('auth.mfaInvalid');
    return;
  }
  submitting.value = true;
  try {
    await commercialAuth.reauthenticate({
      password: password.value,
      code: hasCode ? code.value.trim() : undefined,
      recoveryCode: hasRecovery ? recoveryCode.value.trim() : undefined,
    });
    succeeded = true;
    open.value = false;
    emit('authenticated');
  } catch (failure) {
    if (failure instanceof ApiError) {
      if (failure.code === 'invalid_credentials' || failure.code === 'current_password_invalid') {
        passwordError.value = t('auth.invalidCredentials');
      } else if (failure.code === 'mfa_code_invalid') {
        codeError.value = t('auth.mfaInvalid');
      } else if (failure.code === 'mfa_method_required') {
        // The status probe may have failed while MFA is actually enabled; reveal the code field.
        mfaEnabled.value = true;
        codeError.value = t('auth.mfaInvalid');
      } else if (failure.code === 'mfa_not_enabled') {
        mfaEnabled.value = false;
        formError.value = resolveErrorMessage(failure);
      } else {
        formError.value = resolveErrorMessage(failure);
      }
    } else {
      formError.value = resolveErrorMessage(failure);
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('security.reauthTitle')"
    :description="$t('security.reauthDescription')"
    :persistent="submitting"
  >
    <form class="reauth" novalidate @submit.prevent="submit">
      <AppPasswordInput
        id="reauth-password"
        v-model="password"
        :label="$t('auth.password')"
        :error="passwordError ?? undefined"
        autocomplete="current-password"
        required
      />

      <template v-if="mfaEnabled">
        <div class="reauth__modes" role="group" :aria-label="$t('security.reauthTitle')">
          <AppButton
            type="button"
            :variant="mode === 'code' ? 'primary' : 'secondary'"
            size="s"
            :aria-pressed="mode === 'code'"
            @click="mode = 'code'"
          >
            {{ $t('auth.mfaCode') }}
          </AppButton>
          <AppButton
            type="button"
            :variant="mode === 'recovery' ? 'primary' : 'secondary'"
            size="s"
            :aria-pressed="mode === 'recovery'"
            @click="mode = 'recovery'"
          >
            {{ $t('auth.mfaRecoveryCode') }}
          </AppButton>
        </div>
        <AppInput
          v-if="mode === 'code'"
          id="reauth-code"
          v-model="code"
          :label="$t('auth.mfaCode')"
          :error="codeError ?? undefined"
          inputmode="numeric"
          autocomplete="one-time-code"
        />
        <AppInput
          v-else
          id="reauth-recovery"
          v-model="recoveryCode"
          :label="$t('auth.mfaRecoveryCode')"
          :error="codeError ?? undefined"
          autocomplete="off"
        />
      </template>

      <p v-if="formError" class="reauth__error" role="alert">{{ formError }}</p>

      <div class="reauth__actions">
        <AppButton type="button" variant="secondary" :disabled="submitting" @click="open = false">
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton type="submit" :loading="submitting">{{ $t('security.reauthButton') }}</AppButton>
      </div>
    </form>
  </AppDialog>
</template>

<style scoped>
.reauth {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.reauth__modes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.reauth__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
}

.reauth__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}
</style>
