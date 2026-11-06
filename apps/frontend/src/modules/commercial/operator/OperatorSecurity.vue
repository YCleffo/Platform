<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDisclosure from '@/shared/ui/surfaces/AppDisclosure.vue';
import QrCode from '@/shared/ui/media/QrCode.vue';
import { createCommercialAuthApi } from '@/shared/api/commercial/auth';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { TotpSetup } from '@/shared/types';
import { operatorHttp, operatorEpoch } from './session';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
const { t } = useI18n();
const uid = useId();
const api = createCommercialAuthApi(operatorHttp);
const password = ref('');
const code = ref('');
const recovery = ref(false);
const busy = ref(false);
const message = ref('');
const setup = ref<TotpSetup | null>(null);
const recoveryCodes = ref<string[]>([]);
let alive = true;
async function run(action: () => Promise<void>): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  message.value = '';
  const epoch = operatorEpoch.value;
  try {
    await action();
    if (alive && epoch === operatorEpoch.value) message.value = t('workbench.completed');
  } catch (e) {
    if (alive && epoch === operatorEpoch.value) message.value = resolveErrorMessage(e);
  } finally {
    password.value = '';
    code.value = '';
    if (alive) busy.value = false;
  }
}
async function reauth(): Promise<void> {
  await run(async () => {
    await api.reauthenticate({
      password: password.value,
      ...(recovery.value
        ? { recoveryCode: code.value || undefined }
        : { code: code.value || undefined }),
    });
  });
}
async function begin(): Promise<void> {
  await run(async () => {
    setup.value = await api.beginTotpSetup();
  });
}
async function confirm(): Promise<void> {
  if (!setup.value) return;
  const factor = setup.value.factor_id;
  await run(async () => {
    const result = await api.confirmTotpSetup(factor, code.value);
    setup.value = null;
    recoveryCodes.value = result.recovery_codes;
  });
}
onBeforeUnmount(() => {
  alive = false;
  password.value = '';
  code.value = '';
  setup.value = null;
  recoveryCodes.value = [];
});
</script>
<template>
  <AppDisclosure
    class="operator-security"
    :label="t('operator.sessionSecurity')"
  >
    <div class="operator-security__body">
      <p>{{ t('operator.securityHint') }}</p>
      <form @submit.prevent="reauth">
        <AppPasswordInput
          :id="uid + 'operator-reauth-password'"
          v-model="password"
          :label="t('auth.password')"
          autocomplete="current-password"
          :disabled="busy"
        />
        <AppInput
          :id="uid + 'operator-reauth-code'"
          v-model="code"
          :label="t('operator.mfaCode')"
          :disabled="busy"
        />
        <AppCheckbox
          :id="uid + 'operator-recovery-toggle'"
          v-model="recovery"
          :label="t('operator.useRecovery')"
          :disabled="busy"
        />
        <AppButton
          type="submit"
          :loading="busy"
        >
          {{ t('operator.reauthenticate') }}
        </AppButton>
      </form>
      <AppButton
        variant="secondary"
        :disabled="busy"
        @click="begin"
      >
        {{ t('operator.enroll') }}
      </AppButton>
      <form
        v-if="setup"
        @submit.prevent="confirm"
      >
        <QrCode
          :value="setup.otpauth_uri"
          :size="180"
          :label="t('security.qrLabel')"
        />
        <code>{{ setup.secret }}</code>
        <AppInput
          :id="uid + 'operator-setup-code'"
          v-model="code"
          :label="t('operator.mfaCode')"
          :disabled="busy"
        />
        <AppButton
          type="submit"
          :loading="busy"
        >
          {{ t('operator.confirmMfa') }}
        </AppButton>
      </form>
      <div v-if="recoveryCodes.length">
        <p>{{ t('operator.saveRecovery') }}</p>
        <ul>
          <li
            v-for="value in recoveryCodes"
            :key="value"
          >
            <code>{{ value }}</code>
          </li>
        </ul>
        <AppButton
          variant="secondary"
          @click="recoveryCodes = []"
        >
          {{ t('operator.savedRecovery') }}
        </AppButton>
      </div>
      <p
        v-if="message"
        role="status"
      >
        {{ message }}
      </p>
    </div>
  </AppDisclosure>
</template>
<style scoped>
.operator-security {
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  padding: var(--space-8) var(--space-12);
}
.operator-security__body,
.operator-security form {
  display: grid;
  gap: var(--space-12);
  min-width: 0;
}
.operator-security__body {
  margin-top: var(--space-8);
}
@media (max-width: 767px) {
  .operator-security {
    padding: 0 var(--space-12);
  }
  .operator-security__body {
    padding-bottom: var(--space-12);
  }
}
.operator-security code {
  overflow-wrap: anywhere;
}
</style>
