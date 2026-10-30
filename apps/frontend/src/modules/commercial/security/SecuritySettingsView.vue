<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import { useMfaStatus } from './useMfaStatus';
import { useSessions } from './useSessions';
import MfaSetupDialog from './MfaSetupDialog.vue';
import ReauthenticateDialog from './ReauthenticateDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const { t, locale } = useI18n();
const toast = useToast();

const {
  status,
  loading: statusLoading,
  errorMessage: statusError,
  load: loadStatus,
} = useMfaStatus();
const {
  current,
  loading: sessionsLoading,
  errorMessage: sessionsError,
  load: loadSessions,
} = useSessions();

const setupOpen = ref(false);
const reauthOpen = ref(false);

onMounted(() => {
  void loadStatus();
  void loadSessions();
});

function methodLabel(method: string): string {
  return t(`security.method_${method}`);
}

async function onReauthenticated(): Promise<void> {
  toast.show(t('security.reauthSuccess'), 'success');
  await Promise.all([loadStatus(), loadSessions()]);
}

function onMfaEnabled(): void {
  toast.show(t('security.setupSuccess'), 'success');
  void loadStatus();
}
</script>

<template>
  <section class="security-view">
    <header class="security-view__header">
      <h1 class="security-view__title">{{ $t('security.title') }}</h1>
      <p class="security-view__subtitle">{{ $t('security.subtitle') }}</p>
    </header>

    <div class="security-view__grid">
      <AppCard padding="default" class="security-view__card">
        <h2 class="security-view__card-title">{{ $t('security.mfaTitle') }}</h2>
        <AppSkeleton v-if="statusLoading" variant="text" :lines="2" />
        <AppErrorState v-else-if="statusError" :message="statusError">
          <AppButton variant="secondary" @click="loadStatus">{{ $t('common.retry') }}</AppButton>
        </AppErrorState>
        <template v-else-if="status">
          <AppBadge :variant="status.enabled ? 'success' : 'warning'" dot>
            {{ status.enabled ? $t('security.mfaEnabled') : $t('security.mfaDisabled') }}
          </AppBadge>
          <p class="security-view__muted">{{ $t('security.mfaDescription') }}</p>
          <p v-if="status.enabled" class="security-view__muted">
            {{ $t('security.recoveryRemaining', { count: status.recovery_codes_remaining }) }}
          </p>
          <AppButton v-if="!status.enabled" @click="setupOpen = true">
            {{ $t('security.connectMfa') }}
          </AppButton>
        </template>
      </AppCard>

      <AppCard padding="default" class="security-view__card">
        <h2 class="security-view__card-title">{{ $t('security.reauthTitle') }}</h2>
        <p class="security-view__muted">{{ $t('security.reauthDescription') }}</p>
        <AppButton variant="secondary" @click="reauthOpen = true">
          {{ $t('security.reauthButton') }}
        </AppButton>
      </AppCard>

      <AppCard padding="default" class="security-view__card security-view__session">
        <h2 class="security-view__card-title">{{ $t('security.sessionsTitle') }}</h2>
        <p class="security-view__muted">{{ $t('security.sessionsDescription') }}</p>
        <AppSkeleton v-if="sessionsLoading" variant="text" :lines="3" />
        <AppErrorState v-else-if="sessionsError" :message="sessionsError">
          <AppButton variant="secondary" @click="loadSessions">{{ $t('common.retry') }}</AppButton>
        </AppErrorState>
        <dl v-else-if="current" class="security-view__details">
          <div class="security-view__detail">
            <dt>{{ $t('security.issuedAt') }}</dt>
            <dd>{{ formatDateTime(current.issued_at, locale) }}</dd>
          </div>
          <div class="security-view__detail">
            <dt>{{ $t('security.lastUsedAt') }}</dt>
            <dd>{{ formatDateTime(current.last_used_at, locale) }}</dd>
          </div>
          <div class="security-view__detail">
            <dt>{{ $t('security.expiresAt') }}</dt>
            <dd>{{ formatDateTime(current.expires_at, locale) }}</dd>
          </div>
          <div class="security-view__detail">
            <dt>{{ $t('security.authenticationMethod') }}</dt>
            <dd>{{ methodLabel(current.authentication_method) }}</dd>
          </div>
          <div class="security-view__detail">
            <dt>{{ $t('security.mfaAt') }}</dt>
            <dd>{{ current.mfa_authenticated_at ? formatDateTime(current.mfa_authenticated_at, locale) : '—' }}</dd>
          </div>
        </dl>
        <p v-else class="security-view__muted">{{ $t('security.noSession') }}</p>
      </AppCard>
    </div>

    <MfaSetupDialog v-model="setupOpen" @enabled="onMfaEnabled" />
    <ReauthenticateDialog v-model="reauthOpen" @authenticated="onReauthenticated" />
  </section>
</template>

<style scoped>
.security-view {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
  max-width: 960px;
  margin: 0 auto;
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
}

.security-view__subtitle {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
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
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.security-view__muted {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}

.security-view__details {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-8);
  margin: 0;
  width: 100%;
}

.security-view__detail {
  display: flex;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-8) 0;
  border-bottom: 1px solid var(--border);
}

.security-view__detail:last-child {
  border-bottom: none;
}

.security-view__detail dt {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}

.security-view__detail dd {
  margin: 0;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
  text-align: right;
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
