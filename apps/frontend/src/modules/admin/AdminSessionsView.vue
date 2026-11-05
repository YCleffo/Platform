<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreSecurity, type CoreOwnSession } from '@/shared/api/core/security';
import { formatDateTime } from '@/shared/formatting/date';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from './useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';

const { t, locale } = useI18n();
const reads = useAdminOperation();
const actions = useAdminOperation();
const sessions = ref<CoreOwnSession[]>([]);
const loading = ref(true);
const error = ref('');
const actionError = ref('');
const success = ref('');
const busy = ref(false);
const target = ref<CoreOwnSession | 'others' | null>(null);
const others = computed(() => sessions.value.filter((item) => !item.current));
function device(item: CoreOwnSession): string {
  return (
    item.deviceName?.trim() ||
    [item.browser.name, item.os.name].filter(Boolean).join(' · ') ||
    t('security.unknownDevice')
  );
}
function location(item: CoreOwnSession): string {
  return (
    [
      item.approximateLocation.city,
      item.approximateLocation.region,
      item.approximateLocation.countryCode,
    ]
      .filter(Boolean)
      .join(', ') || '—'
  );
}
async function load(): Promise<void> {
  const request = reads.begin();
  loading.value = true;
  error.value = '';
  try {
    const result = await coreSecurity.sessions();
    if (reads.current(request)) sessions.value = result;
  } catch (caught) {
    if (reads.current(request)) error.value = resolveErrorMessage(caught);
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
function confirm(item: CoreOwnSession | 'others'): void {
  target.value = item;
  actionError.value = '';
}
async function revoke(): Promise<void> {
  if (busy.value || target.value === null || (target.value !== 'others' && target.value.current))
    return;
  const request = actions.begin();
  const item = target.value;
  busy.value = true;
  actionError.value = '';
  try {
    if (item === 'others') await coreSecurity.revokeOthers();
    else await coreSecurity.revoke(item.id);
    if (!actions.current(request)) return;
    target.value = null;
    success.value = t(item === 'others' ? 'security.sessionsRevoked' : 'security.sessionRevoked');
    await load();
  } catch (caught) {
    if (actions.current(request)) actionError.value = resolveErrorMessage(caught);
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
onMounted(load);
</script>

<template>
  <section class="core-sessions">
    <header class="core-sessions__head">
      <div>
        <h2>{{ $t('coreSecurity.sessionsTitle') }}</h2>
        <p>{{ $t('coreSecurity.sessionsHint') }}</p>
      </div>
      <div class="core-sessions__actions">
        <AppButton
          variant="secondary"
          :loading="loading"
          :disabled="busy"
          @click="load"
        >
          {{ $t('coreSecurity.refreshSessions') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :disabled="loading || busy || others.length === 0"
          @click="confirm('others')"
        >
          {{ $t('security.revokeOthers') }}
        </AppButton>
      </div>
    </header>
    <p
      v-if="success"
      role="status"
    >
      {{ success }}
    </p>
    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="5"
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
    <AppEmptyState
      v-else-if="sessions.length === 0"
      :title="$t('security.noSession')"
    />
    <template v-else>
      <AppCard
        v-for="item in sessions"
        :key="item.id"
        class="core-sessions__item"
        :data-session-id="item.id"
      >
        <div class="core-sessions__head">
          <strong>{{ device(item) }}</strong>
          <AppBadge
            v-if="item.current"
            variant="success"
          >
            {{ $t('security.currentSession') }}
          </AppBadge>
        </div>
        <dl>
          <div>
            <dt>{{ $t('coreSecurity.ipAddress') }}</dt>
            <dd>{{ item.ipAddress ?? '—' }}</dd>
          </div>
          <div>
            <dt>{{ $t('coreSecurity.approximateLocation') }}</dt>
            <dd>{{ location(item) }}</dd>
          </div>
          <div>
            <dt>{{ $t('security.issuedAt') }}</dt>
            <dd>{{ formatDateTime(item.createdAt, locale) }}</dd>
          </div>
          <div>
            <dt>{{ $t('security.lastUsedAt') }}</dt>
            <dd>{{ formatDateTime(item.lastSeenAt, locale) }}</dd>
          </div>
          <div>
            <dt>{{ $t('security.expiresAt') }}</dt>
            <dd>{{ formatDateTime(item.expiresAt, locale) }}</dd>
          </div>
        </dl>
        <AppButton
          v-if="!item.current"
          variant="danger"
          :disabled="busy"
          @click="confirm(item)"
        >
          {{ $t('security.revokeSession') }}
        </AppButton>
      </AppCard>
    </template>
    <AppConfirmDialog
      :model-value="target !== null"
      :title="
        $t(target === 'others' ? 'security.revokeOthersTitle' : 'security.revokeSessionTitle')
      "
      :description="
        target === 'others'
          ? $t('security.revokeOthersText')
          : $t('security.revokeSessionText', { device: target ? device(target) : '' })
      "
      :confirm-label="$t('security.revokeSession')"
      :busy="busy"
      danger
      @update:model-value="
        (value) => {
          if (!value) target = null;
        }
      "
      @confirm="revoke"
    >
      <p
        v-if="actionError"
        role="alert"
      >
        {{ actionError }}
      </p>
    </AppConfirmDialog>
  </section>
</template>

<style scoped>
.core-sessions {
  display: grid;
  gap: var(--space-16);
}
.core-sessions__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
}
.core-sessions__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
.core-sessions h2 {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  color: var(--text-1);
}
.core-sessions p,
.core-sessions dt {
  color: var(--text-2);
}
.core-sessions__item {
  display: grid;
  gap: var(--space-16);
}
.core-sessions dl {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: var(--space-12);
}
.core-sessions dd,
.core-sessions strong {
  color: var(--text-1);
  overflow-wrap: anywhere;
}
.core-sessions__item > button {
  justify-self: start;
}
</style>
