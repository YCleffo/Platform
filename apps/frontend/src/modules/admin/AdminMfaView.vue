<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import QrcodeVue from 'qrcode.vue';
import { coreSecurity, type CoreMfaStatus, type CoreTotpSetup } from '@/shared/api/core/security';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from './useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAdminAuthStore();
const reads = useAdminOperation();
const actions = useAdminOperation();
const status = ref<CoreMfaStatus | null>(null);
const loading = ref(true);
const error = ref('');
const mode = ref<'setup' | 'regenerate' | 'disable' | 'codes' | null>(null);
const setup = ref<CoreTotpSetup | null>(null);
const codes = ref<string[]>([]);
const code = ref('');
const password = ref('');
const busy = ref(false);
const formError = ref('');
const recentRequired = ref(false);
const success = ref('');
const copied = ref(false);

async function load(): Promise<void> {
  const request = reads.begin();
  loading.value = true;
  error.value = '';
  try {
    const result = await coreSecurity.status();
    if (reads.current(request)) status.value = result;
  } catch (caught) {
    if (reads.current(request)) error.value = resolveErrorMessage(caught);
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
function close(): void {
  actions.begin();
  mode.value = null;
  setup.value = null;
  codes.value = [];
  code.value = '';
  password.value = '';
  formError.value = '';
  recentRequired.value = false;
  copied.value = false;
}
onBeforeUnmount(close);
async function copyCodes(): Promise<void> {
  const request = actions.begin();
  formError.value = '';
  try {
    await navigator.clipboard.writeText(codes.value.join('\n'));
    if (actions.current(request)) copied.value = true;
  } catch {
    if (actions.current(request)) formError.value = t('coreSecurity.copyFailed');
  }
}
function fail(caught: unknown): void {
  recentRequired.value = caught instanceof ApiError && caught.code === 'auth.recent_required';
  formError.value = recentRequired.value
    ? t('coreSecurity.recentRequired')
    : resolveErrorMessage(caught);
}
async function startSetup(): Promise<void> {
  if (busy.value) return;
  close();
  mode.value = 'setup';
  busy.value = true;
  const request = actions.begin();
  try {
    const result = await coreSecurity.setup();
    if (actions.current(request)) setup.value = result;
  } catch (caught) {
    if (actions.current(request)) fail(caught);
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
function open(next: 'regenerate' | 'disable'): void {
  close();
  mode.value = next;
}
async function submit(): Promise<void> {
  if (busy.value || mode.value === null || mode.value === 'codes') return;
  if (!/^\d{6}$/.test(code.value)) {
    formError.value = t('auth.mfaInvalid');
    return;
  }
  if (
    mode.value === 'setup' &&
    (setup.value === null || Date.parse(setup.value.expiresAt) <= Date.now())
  ) {
    formError.value = t('coreSecurity.setupExpired');
    return;
  }
  const request = actions.begin();
  const action = mode.value;
  const enteredCode = code.value;
  const enteredPassword = password.value;
  busy.value = true;
  code.value = '';
  password.value = '';
  formError.value = '';
  recentRequired.value = false;
  try {
    const result =
      action === 'setup'
        ? await coreSecurity.confirm(setup.value!.setupId, enteredCode)
        : action === 'regenerate'
          ? await coreSecurity.regenerate(enteredCode)
          : await coreSecurity.disable(enteredPassword || null, enteredCode);
    if (!actions.current(request)) return;
    setup.value = null;
    if (result) {
      codes.value = result;
      mode.value = 'codes';
    } else {
      mode.value = null;
      success.value = t('security.mfaDisabledToast');
    }
    await load();
  } catch (caught) {
    if (actions.current(request)) fail(caught);
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
async function signInAgain(): Promise<void> {
  if (busy.value) return;
  const done = auth.logout();
  const epoch = coreAdminSession.epoch();
  await done;
  if (coreAdminSession.epoch() === epoch)
    await router.replace({ name: 'admin-login', query: { redirect: '/admin/security/mfa' } });
}
onMounted(load);
</script>

<template>
  <section class="core-mfa">
    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="4"
    />
    <AppErrorState
      v-else-if="error"
      :message="error"
    >
      <AppButton
        variant="secondary"
        @click="load"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>
    <AppCard v-else-if="status">
      <h2>{{ $t('security.mfaTitle') }}</h2>
      <p>{{ $t('security.mfaDescription') }}</p>
      <AppBadge :variant="status.totpEnabled ? 'success' : 'warning'">
        {{ $t(status.totpEnabled ? 'security.mfaEnabled' : 'security.mfaDisabled') }}
      </AppBadge>
      <p v-if="status.totpEnabled">
        {{ $t('security.recoveryRemaining', { count: status.recoveryCodesRemaining }) }}
      </p>
      <p v-if="status.verifiedAt">
        {{ $t('security.mfaAt') }}: {{ formatDateTime(status.verifiedAt, locale) }}
      </p>
      <div class="core-mfa__actions">
        <AppButton
          v-if="!status.totpEnabled"
          :loading="busy"
          @click="startSetup"
        >
          {{ $t('security.connectMfa') }}
        </AppButton>
        <template v-else>
          <AppButton
            variant="secondary"
            :disabled="busy"
            @click="open('regenerate')"
          >
            {{ $t('security.regenerateCodes') }}
          </AppButton>
          <AppButton
            variant="danger"
            :disabled="busy"
            @click="open('disable')"
          >
            {{ $t('security.disableMfa') }}
          </AppButton>
        </template>
      </div>
      <p
        v-if="success"
        role="status"
      >
        {{ success }}
      </p>
    </AppCard>

    <AppDialog
      :model-value="mode !== null"
      :title="
        $t(
          mode === 'codes'
            ? 'security.recoveryTitle'
            : mode === 'disable'
              ? 'security.disableMfaTitle'
              : mode === 'regenerate'
                ? 'security.recoveryCodesTitle'
                : 'security.mfaSetupTitle',
        )
      "
      :persistent="busy || mode === 'codes'"
      @update:model-value="
        (value) => {
          if (!value) close();
        }
      "
    >
      <div
        v-if="mode === 'codes'"
        class="core-mfa__content"
      >
        <p>{{ $t('security.recoveryIntro') }}</p>
        <ul class="core-mfa__codes">
          <li
            v-for="item in codes"
            :key="item"
          >
            <code>{{ item }}</code>
          </li>
        </ul>
        <AppButton
          variant="secondary"
          :disabled="busy"
          @click="copyCodes"
        >
          {{ $t(copied ? 'security.copied' : 'security.copyCodes') }}
        </AppButton>
        <p
          v-if="formError"
          role="alert"
        >
          {{ formError }}
        </p>
        <AppButton
          :disabled="busy"
          @click="close"
        >
          {{ $t('security.recoveryDone') }}
        </AppButton>
      </div>
      <form
        v-else
        class="core-mfa__content"
        @submit.prevent="submit"
      >
        <template v-if="mode === 'setup' && setup">
          <p>{{ $t('security.mfaSetupIntro') }}</p>
          <div
            class="core-mfa__qr"
            role="img"
            :aria-label="$t('security.qrLabel')"
          >
            <QrcodeVue
              aria-hidden="true"
              :value="setup.provisioningUri"
              :size="180"
              level="M"
            />
          </div>
          <p>{{ $t('security.manualSecret') }}</p>
          <code class="core-mfa__secret">{{ setup.secret }}</code>
          <p>{{ $t('security.expiresAt') }}: {{ formatDateTime(setup.expiresAt, locale) }}</p>
        </template>
        <p v-if="mode === 'disable'">{{ $t('coreSecurity.disableWarning') }}</p>
        <p v-if="mode === 'regenerate'">{{ $t('coreSecurity.regenerateWarning') }}</p>
        <template v-if="mode !== 'setup' || setup">
          <AppPasswordInput
            v-if="mode === 'disable'"
            id="core-disable-password"
            v-model="password"
            :label="$t('security.currentPassword')"
            :hint="$t('coreSecurity.passwordOptional')"
            autocomplete="current-password"
          />
          <AppInput
            id="core-mfa-code"
            v-model="code"
            :label="$t('security.codeLabel')"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            required
          />
          <AppButton
            type="submit"
            :variant="mode === 'disable' ? 'danger' : 'primary'"
            :loading="busy"
          >
            {{ $t(mode === 'disable' ? 'security.disableMfa' : 'security.confirm') }}
          </AppButton>
        </template>
        <AppSkeleton
          v-else-if="busy"
          :lines="3"
        />
        <AppButton
          v-else-if="!recentRequired"
          variant="secondary"
          @click="startSetup"
        >
          {{ $t('common.retry') }}
        </AppButton>
        <p
          v-if="formError"
          role="alert"
        >
          {{ formError }}
        </p>
        <AppButton
          v-if="recentRequired"
          variant="secondary"
          @click="signInAgain"
        >
          {{ $t('coreSecurity.signInAgain') }}
        </AppButton>
      </form>
    </AppDialog>
  </section>
</template>

<style scoped>
.core-mfa,
.core-mfa__content {
  display: grid;
  gap: var(--space-16);
}
.core-mfa :deep(.app-card) {
  display: grid;
  justify-items: start;
  gap: var(--space-12);
}
.core-mfa h2 {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  color: var(--text-1);
}
.core-mfa p {
  color: var(--text-2);
}
.core-mfa__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
.core-mfa__qr {
  padding: var(--space-12);
  background: white;
  width: fit-content;
  border-radius: var(--radius-control-m);
}
.core-mfa__secret {
  overflow-wrap: anywhere;
  user-select: all;
}
.core-mfa__codes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr));
  gap: var(--space-8);
}
.core-mfa__codes code {
  user-select: all;
  overflow-wrap: anywhere;
}
</style>
