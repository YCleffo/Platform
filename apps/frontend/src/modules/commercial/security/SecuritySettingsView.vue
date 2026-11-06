<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import type { ControlSession } from '@/shared/types';
import { useMfaStatus } from './useMfaStatus';
import { useSessions } from './useSessions';
import { useSensitiveAction } from './useSensitiveAction';
import MfaSetupDialog from './MfaSetupDialog.vue';
import PasswordChangeDialog from './PasswordChangeDialog.vue';
import ReauthenticateDialog from './ReauthenticateDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const auth = useAuthStore();
const { t, locale } = useI18n();
const toast = useToast();

const {
  status,
  loading: statusLoading,
  errorMessage: statusError,
  load: loadStatus,
} = useMfaStatus();
const {
  sessions,
  loading: sessionsLoading,
  errorMessage: sessionsError,
  load: loadSessions,
} = useSessions();

const setupOpen = ref(false);
const passwordOpen = ref(false);
const codesOpen = ref(false);
const recoveryCodes = ref<string[]>([]);
const actionBusy = ref(false);

const confirmOpen = ref(false);
const confirmTitle = ref('');
const confirmDescription = ref('');
const confirmLabel = ref('');
const confirmAction = ref<null | (() => Promise<void>)>(null);

const {
  reauthOpen: sensitiveReauthOpen,
  runSensitive,
  onReauthenticated: retryPending,
} = useSensitiveAction(
  (message) => {
    toast.show(message, 'error', 7000);
  },
  () => {
    setupOpen.value = true;
    toast.show(t('security.mfaRequired'), 'error', 7000);
  },
);

const otherSessions = computed(() => sessions.value.filter((session) => !session.current));

onMounted(() => {
  void loadStatus();
  void loadSessions();
});

function methodLabel(method: string): string {
  return t(`security.method_${method}`);
}

function deviceLabel(session: ControlSession): string {
  return session.user_agent?.trim() || t('security.unknownDevice');
}

async function onSensitiveReauthenticated(): Promise<void> {
  await retryPending();
  await Promise.all([loadStatus(), loadSessions()]);
}

function onMfaEnabled(): void {
  toast.show(t('security.setupSuccess'), 'success');
  void loadStatus();
}

function askConfirm(
  title: string,
  description: string,
  label: string,
  action: () => Promise<void>,
): void {
  confirmTitle.value = title;
  confirmDescription.value = description;
  confirmLabel.value = label;
  confirmAction.value = action;
  confirmOpen.value = true;
}

async function confirm(): Promise<void> {
  const action = confirmAction.value;
  if (action === null) {
    return;
  }
  confirmOpen.value = false;
  actionBusy.value = true;
  try {
    await action();
  } catch (error) {
    toast.show(resolveErrorMessage(error), 'error', 7000);
  } finally {
    actionBusy.value = false;
  }
}

function ensureMfa(): boolean {
  if (status.value !== null && !status.value.enabled) {
    setupOpen.value = true;
    toast.show(t('security.mfaRequired'), 'error', 7000);
    return false;
  }
  return true;
}

function openPasswordChange(): void {
  if (!ensureMfa()) return;
  passwordOpen.value = true;
}

function onPasswordChanged(): void {
  toast.show(t('security.passwordChanged'), 'success');
}

async function regenerateCodes(): Promise<void> {
  actionBusy.value = true;
  try {
    await runSensitive(async () => {
      const result = await auth.regenerateRecoveryCodes();
      recoveryCodes.value = result.recovery_codes;
      codesOpen.value = true;
      await loadStatus();
    });
  } finally {
    actionBusy.value = false;
  }
}

function disableMfa(): void {
  askConfirm(
    t('security.disableMfaTitle'),
    t('security.disableMfaText'),
    t('security.disableMfa'),
    async () => {
      await runSensitive(async () => {
        await auth.disableMfa();
        toast.show(t('security.mfaDisabledToast'), 'success');
        await Promise.all([loadStatus(), loadSessions()]);
      });
    },
  );
}

function revokeSession(session: ControlSession): void {
  if (!ensureMfa()) return;
  askConfirm(
    t('security.revokeSessionTitle'),
    t('security.revokeSessionText', { device: deviceLabel(session) }),
    t('security.revokeSession'),
    async () => {
      await runSensitive(async () => {
        await auth.revokeSession(session.id);
        toast.show(t('security.sessionRevoked'), 'success');
        await loadSessions();
      });
    },
  );
}

function revokeOtherSessions(): void {
  if (!ensureMfa()) return;
  askConfirm(
    t('security.revokeOthersTitle'),
    t('security.revokeOthersText'),
    t('security.revokeOthers'),
    async () => {
      await runSensitive(async () => {
        await auth.revokeOtherSessions();
        toast.show(t('security.sessionsRevoked'), 'success');
        await loadSessions();
      });
    },
  );
}
</script>

<template>
  <section class="security-view">
    <header class="security-view__header">
      <h2 class="security-view__title">{{ $t('security.title') }}</h2>
      <p class="security-view__subtitle">{{ $t('security.subtitle') }}</p>
    </header>

    <div class="security-view__grid">
      <AppCard
        padding="default"
        class="security-view__card"
      >
        <h2 class="security-view__card-title">{{ $t('security.mfaTitle') }}</h2>
        <AppSkeleton
          v-if="statusLoading"
          variant="text"
          :lines="2"
        />
        <AppErrorState
          v-else-if="statusError"
          :message="statusError"
        >
          <AppButton
            variant="secondary"
            @click="loadStatus"
          >
            {{ $t('common.retry') }}
          </AppButton>
        </AppErrorState>
        <template v-else-if="status">
          <AppBadge
            :variant="status.enabled ? 'success' : 'warning'"
            dot
          >
            {{ status.enabled ? $t('security.mfaEnabled') : $t('security.mfaDisabled') }}
          </AppBadge>
          <p class="security-view__muted">{{ $t('security.mfaDescription') }}</p>
          <p
            v-if="status.enabled"
            class="security-view__muted"
          >
            {{ $t('security.recoveryRemaining', { count: status.recovery_codes_remaining }) }}
          </p>
          <div class="security-view__actions">
            <AppButton
              v-if="!status.enabled"
              @click="setupOpen = true"
            >
              {{ $t('security.connectMfa') }}
            </AppButton>
            <template v-else>
              <AppButton
                variant="secondary"
                :loading="actionBusy"
                @click="regenerateCodes"
              >
                {{ $t('security.regenerateCodes') }}
              </AppButton>
              <AppButton
                variant="danger"
                :loading="actionBusy"
                @click="disableMfa"
              >
                {{ $t('security.disableMfa') }}
              </AppButton>
            </template>
          </div>
        </template>
      </AppCard>

      <AppCard
        padding="default"
        class="security-view__card"
      >
        <h2 class="security-view__card-title">{{ $t('security.passwordTitle') }}</h2>
        <p class="security-view__muted">{{ $t('security.passwordHint') }}</p>
        <button
          type="button"
          class="security-view__link"
          @click="openPasswordChange"
        >
          {{ $t('security.changePassword') }}
        </button>
      </AppCard>

      <AppCard
        padding="default"
        class="security-view__card security-view__session"
      >
        <div class="security-view__sessions-head">
          <div>
            <h2 class="security-view__card-title">{{ $t('security.sessionsTitle') }}</h2>
            <p class="security-view__muted">{{ $t('security.sessionsDescription') }}</p>
          </div>
          <AppButton
            v-if="otherSessions.length > 0"
            size="s"
            variant="danger"
            :loading="actionBusy"
            @click="revokeOtherSessions"
          >
            {{ $t('security.revokeOthers') }}
          </AppButton>
        </div>
        <AppSkeleton
          v-if="sessionsLoading"
          variant="text"
          :lines="3"
        />
        <AppErrorState
          v-else-if="sessionsError"
          :message="sessionsError"
        >
          <AppButton
            variant="secondary"
            @click="loadSessions"
          >
            {{ $t('common.retry') }}
          </AppButton>
        </AppErrorState>
        <div
          v-else-if="sessions.length > 0"
          class="security-view__session-list"
        >
          <div
            v-for="session in sessions"
            :key="session.id"
            class="security-view__session-row"
          >
            <div class="security-view__session-main">
              <div class="security-view__session-title">
                <strong>{{ deviceLabel(session) }}</strong>
                <AppBadge
                  v-if="session.current"
                  variant="success"
                >
                  {{ $t('security.currentSession') }}
                </AppBadge>
              </div>
              <p class="security-view__muted">
                {{ methodLabel(session.authentication_method) }} Р’В· {{ $t('security.issuedAt') }}
                {{ formatDateTime(session.issued_at, locale) }}
              </p>
              <p class="security-view__muted">
                {{ $t('security.lastUsedAt') }}:
                {{
                  session.last_used_at ? formatDateTime(session.last_used_at, locale) : 'РІР‚вЂќ'
                }}
                Р’В· {{ $t('security.expiresAt') }}:
                {{ formatDateTime(session.expires_at, locale) }}
              </p>
            </div>
            <AppButton
              v-if="!session.current && session.revoked_at === null"
              size="s"
              variant="danger"
              :loading="actionBusy"
              @click="revokeSession(session)"
            >
              {{ $t('security.revokeSession') }}
            </AppButton>
          </div>
        </div>
        <p
          v-else
          class="security-view__muted"
        >
          {{ $t('security.noSession') }}
        </p>
      </AppCard>
    </div>

    <MfaSetupDialog
      v-model="setupOpen"
      @enabled="onMfaEnabled"
    />
    <PasswordChangeDialog
      v-model="passwordOpen"
      @completed="onPasswordChanged"
      @enrollment-required="setupOpen = true"
    />
    <ReauthenticateDialog
      v-model="sensitiveReauthOpen"
      @authenticated="onSensitiveReauthenticated"
    />
    <AppConfirmDialog
      v-model="confirmOpen"
      :title="confirmTitle"
      :description="confirmDescription"
      :confirm-label="confirmLabel"
      :busy="actionBusy"
      @confirm="confirm"
    />
    <AppDialog
      v-model="codesOpen"
      :title="$t('security.recoveryCodesTitle')"
      :description="$t('security.recoveryCodesDescription')"
    >
      <ol class="security-view__codes">
        <li
          v-for="code in recoveryCodes"
          :key="code"
        >
          <code>{{ code }}</code>
        </li>
      </ol>
      <AppButton @click="codesOpen = false">{{ $t('common.close') }}</AppButton>
    </AppDialog>
  </section>
</template>

<style scoped>
.security-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
  max-width: none;
  margin: 0;
}

.security-view__header {
  display: grid;
  gap: var(--space-4);
}

.security-view__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.security-view__subtitle,
.security-view__muted {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.security-view__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

.security-view__card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-12);
}

.security-view__card-title {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.security-view__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.security-view__link {
  padding: 0;
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-align: left;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.security-view__sessions-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
  width: 100%;
}

.security-view__session-list {
  display: grid;
  gap: var(--space-8);
  width: 100%;
}

.security-view__session-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
}

.security-view__session-main {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}

.security-view__session-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
  color: var(--text-1);
  overflow-wrap: anywhere;
}

.security-view__codes {
  display: grid;
  gap: var(--space-8);
  margin-bottom: var(--space-16);
  padding-left: var(--space-20);
}

.security-view__codes code {
  font-family: var(--font-mono);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
}

@media (min-width: 768px) {
  .security-view__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-20);
  }

  .security-view__session {
    grid-column: 1 / -1;
  }
}
</style>
