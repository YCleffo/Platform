<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { realtimeClient } from '@/shared/realtime/realtimeClient';
import {
  notificationsApi,
  type NotificationGroup,
  type NotificationItem,
  type NotificationPreference,
  type PushStatusInfo,
} from '@/shared/api/notificationsApi';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { Archive, Bell, Check } from '@/shared/ui/icons';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import { chatT } from '@/modules/chat/chatI18n';

const props = defineProps<{
  organizationId: string;
  personId: string;
}>();

const router = useRouter();
const { t } = useI18n();
const toast = useToast();

const items = ref<NotificationItem[]>([]);
const serverGroups = ref<NotificationGroup[] | null>(null);
const unreadCount = ref(0);
const categoryFilter = ref<string>('');
const groupedView = ref(false);
const unreadOnly = ref(false);
const selectedIds = ref<string[]>([]);
const bulkBusy = ref(false);
const preferences = ref<NotificationPreference[]>([]);
const showPreferences = ref(false);
const serverPushStatus = ref<PushStatusInfo | null>(null);
const browserPermission = ref<NotificationPermission | 'unsupported'>(
  typeof Notification === 'undefined' ? 'unsupported' : Notification.permission,
);
let unsubRealtime: (() => void) | null = null;
let notifBroadcast: BroadcastChannel | null = null;

function notifyOtherTabs(): void {
  try {
    notifBroadcast?.postMessage({ orgId: props.organizationId, personId: props.personId });
  } catch {
    // Ignore closed channel
  }
}

const effectivePushState = computed<{
  status: 'active' | 'permission_denied' | 'not_configured' | 'unsupported' | 'unsubscribed';
  badgeVariant: 'success' | 'danger' | 'warning' | 'neutral';
  label: string;
  detail?: string;
}>(() => {
  if (serverPushStatus.value && !serverPushStatus.value.configured) {
    return {
      status: 'not_configured',
      badgeVariant: 'warning',
      label: t('workspace.notificationsPushNotConfigured'),
      detail: serverPushStatus.value.reason,
    };
  }
  if (typeof Notification === 'undefined') {
    return {
      status: 'unsupported',
      badgeVariant: 'neutral',
      label: t('workspace.notificationsPushUnsupported'),
    };
  }
  if (browserPermission.value === 'denied') {
    return {
      status: 'permission_denied',
      badgeVariant: 'danger',
      label: t('workspace.notificationsPushDenied'),
    };
  }
  if (
    browserPermission.value === 'granted' &&
    (!serverPushStatus.value || serverPushStatus.value.status === 'active')
  ) {
    return {
      status: 'active',
      badgeVariant: 'success',
      label: t('workspace.notificationsPushActive'),
    };
  }
  return {
    status: 'unsubscribed',
    badgeVariant: 'neutral',
    label: t('workspace.notificationsPushUnsubscribed'),
  };
});

const displayedGroups = computed<NotificationGroup[]>(() => {
  if (!groupedView.value) {
    return [{ key: 'all', count: items.value.length, items: items.value }];
  }
  if (serverGroups.value && serverGroups.value.length > 0) {
    return serverGroups.value;
  }
  const map = new Map<string, NotificationItem[]>();
  for (const item of items.value) {
    const key = item.category || 'general';
    const list = map.get(key) ?? [];
    list.push(item);
    map.set(key, list);
  }
  return Array.from(map.entries()).map(([key, groupItems]) => ({
    key,
    category: key,
    label: key,
    count: groupItems.length,
    unreadCount: groupItems.filter((it) => !it.readAt).length,
    items: groupItems,
  }));
});

const allSelected = computed(
  () => items.value.length > 0 && selectedIds.value.length === items.value.length,
);

function isSelected(id: string): boolean {
  return selectedIds.value.includes(id);
}

function toggleSelect(id: string): void {
  if (isSelected(id)) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function toggleSelectAll(): void {
  if (allSelected.value) {
    selectedIds.value = [];
  } else {
    selectedIds.value = items.value.map((it) => it.id);
  }
}

async function refresh(): Promise<void> {
  if (!props.organizationId) return;
  try {
    const res = await notificationsApi.list(props.organizationId, {
      category: categoryFilter.value || undefined,
      unreadOnly: unreadOnly.value || undefined,
      grouped: groupedView.value || undefined,
    });
    items.value = res.items ?? [];
    serverGroups.value = res.groups ?? null;
    unreadCount.value = res.unreadCount ?? 0;
    serverPushStatus.value = res.pushStatus ?? null;
    selectedIds.value = selectedIds.value.filter((id) =>
      items.value.some((item) => item.id === id),
    );
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  }
}

async function markRead(item: NotificationItem): Promise<void> {
  const res = await notificationsApi.markRead(props.organizationId, item.id);
  item.readAt = new Date().toISOString();
  unreadCount.value = res.unreadCount;
  notifyOtherTabs();
}

async function markAllRead(): Promise<void> {
  const res = await notificationsApi.markAllRead(
    props.organizationId,
    categoryFilter.value || undefined,
  );
  for (const it of items.value) {
    if (!it.readAt) it.readAt = new Date().toISOString();
  }
  unreadCount.value = res.unreadCount;
  notifyOtherTabs();
}

async function handleBulkRead(): Promise<void> {
  if (selectedIds.value.length === 0) return;
  bulkBusy.value = true;
  try {
    const res = await notificationsApi.bulkRead(props.organizationId, {
      ids: selectedIds.value,
      category: categoryFilter.value || undefined,
    });
    const now = new Date().toISOString();
    for (const item of items.value) {
      if (selectedIds.value.includes(item.id) && !item.readAt) {
        item.readAt = now;
      }
    }
    unreadCount.value = res.unreadCount;
    selectedIds.value = [];
    notifyOtherTabs();
    toast.success(t('workspace.notificationsBulkReadDone'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    bulkBusy.value = false;
  }
}

async function handleBulkArchive(readOnly = false): Promise<void> {
  if (!readOnly && selectedIds.value.length === 0) return;
  bulkBusy.value = true;
  try {
    const res = await notificationsApi.bulkArchive(
      props.organizationId,
      readOnly
        ? { readOnly: true, category: categoryFilter.value || undefined }
        : { ids: selectedIds.value, category: categoryFilter.value || undefined },
    );
    unreadCount.value = res.unreadCount;
    if (readOnly) {
      items.value = items.value.filter((it) => !it.readAt);
    } else {
      items.value = items.value.filter((it) => !selectedIds.value.includes(it.id));
    }
    selectedIds.value = [];
    notifyOtherTabs();
    toast.success(t('workspace.notificationsBulkArchivedDone'));
    if (groupedView.value) {
      void refresh();
    }
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    bulkBusy.value = false;
  }
}

async function openResource(item: NotificationItem): Promise<void> {
  if (item.resourceAvailable === false || !item.resourcePath) return;
  await router.push(item.resourcePath);
}

async function togglePrefs(): Promise<void> {
  showPreferences.value = !showPreferences.value;
  if (showPreferences.value && preferences.value.length === 0) {
    const res = await notificationsApi.getPreferences(props.organizationId);
    preferences.value = res.items;
  }
}

async function savePref(pref: NotificationPreference): Promise<void> {
  const res = await notificationsApi.updatePreference(props.organizationId, pref);
  preferences.value = res.items;
}

async function enableBrowserPush(): Promise<void> {
  if (typeof Notification === 'undefined') return;
  if (serverPushStatus.value && !serverPushStatus.value.configured) {
    toast.info(t('workspace.notificationsPushNotConfigured'));
    return;
  }
  const perm = await Notification.requestPermission();
  browserPermission.value = perm;
  if (perm === 'granted') {
    await notificationsApi.registerPushSubscription(props.organizationId, {
      endpoint: `browser://${props.personId}/${navigator.userAgent.slice(0, 40)}`,
      deviceLabel: 'Web Browser',
    });
    void refresh();
  }
}

watch([categoryFilter, groupedView, unreadOnly], () => {
  void refresh();
});

watch(
  () => props.organizationId,
  (org) => {
    if (!org) return;
    void realtimeClient.connect(org);
    unsubRealtime?.();
    if (props.personId) {
      unsubRealtime = realtimeClient.subscribe(`user:${props.personId}`, (ev) => {
        if (ev.eventType === 'notification.created') {
          const item = ev.payload as unknown as NotificationItem;
          if (!items.value.some((x) => x.id === item.id)) {
            items.value.unshift(item);
            unreadCount.value += 1;
          }
          if (effectivePushState.value.status === 'active' && typeof Notification !== 'undefined') {
            try {
              new Notification(item.title || 'Platform', { body: item.body });
            } catch {
              // Ignore restricted notification contexts
            }
          }
        } else if (
          ev.eventType === 'notification.read' ||
          ev.eventType === 'notification.read_all' ||
          ev.eventType === 'notification.archived'
        ) {
          void refresh();
        }
      });
    }
    void refresh();
  },
  { immediate: true },
);

onMounted(() => {
  if (typeof BroadcastChannel !== 'undefined') {
    notifBroadcast = new BroadcastChannel('platform_notifications_sync_v1');
    notifBroadcast.onmessage = (event: MessageEvent<{ orgId?: string; personId?: string }>) => {
      if (
        event.data?.orgId === props.organizationId &&
        event.data?.personId === props.personId
      ) {
        void refresh();
      }
    };
  }
  if (props.organizationId) void refresh();
});

onBeforeUnmount(() => {
  unsubRealtime?.();
  notifBroadcast?.close();
  notifBroadcast = null;
});
</script>

<template>
  <section
    class="unotif"
    data-testid="notifications-center"
  >
    <header class="unotif__header">
      <div class="unotif__heading">
        <h2 class="unotif__title">{{ chatT('notifTitle') }}</h2>
        <AppBadge variant="primary">{{ chatT('notifUnread') }}: {{ unreadCount }}</AppBadge>
      </div>
      <div class="unotif__actions">
        <AppButton
          size="s"
          variant="secondary"
          data-testid="notif-mark-all-read"
          @click="markAllRead"
        >
          {{ chatT('notifMarkAllRead') }}
        </AppButton>
        <AppButton
          size="s"
          variant="ghost"
          @click="togglePrefs"
        >
          {{ chatT('notifPrefs') }}
        </AppButton>
      </div>
    </header>

    <!-- Honest Web Push Status Panel -->
    <div
      class="unotif__push-panel"
      data-testid="push-status-panel"
    >
      <div class="unotif__push-copy">
        <Bell
          :size="16"
          aria-hidden="true"
        />
        <span class="unotif__push-title">{{ $t('workspace.notificationsPushTitle') }}</span>
        <AppBadge :variant="effectivePushState.badgeVariant">
          {{ effectivePushState.label }}
        </AppBadge>
        <span
          v-if="effectivePushState.detail"
          class="unotif__push-detail"
        >
          {{ effectivePushState.detail }}
        </span>
      </div>
      <AppButton
        v-if="effectivePushState.status === 'unsubscribed'"
        size="s"
        variant="secondary"
        data-testid="notif-enable-push"
        @click="enableBrowserPush"
      >
        {{ chatT('notifEnablePush') }}
      </AppButton>
    </div>

    <!-- Category tabs -->
    <div
      class="unotif__tabs"
      role="tablist"
    >
      <button
        v-for="cat in ['', 'message', 'homework', 'assessment', 'activity', 'family', 'security']"
        :key="cat || 'all'"
        type="button"
        role="tab"
        class="unotif__tab"
        :class="{ 'unotif__tab--active': categoryFilter === cat }"
        :aria-selected="categoryFilter === cat"
        @click="categoryFilter = cat"
      >
        {{
          cat === ''
            ? chatT('catAll')
            : cat === 'message'
              ? chatT('catMessage')
              : cat === 'homework'
                ? chatT('catHomework')
                : cat === 'assessment'
                  ? chatT('catAssessment')
                  : cat === 'activity'
                    ? chatT('catActivity')
                    : cat === 'family'
                      ? chatT('catFamily')
                      : chatT('catSecurity')
        }}
      </button>
    </div>

    <!-- View & Bulk Toolbar -->
    <div class="unotif__toolbar">
      <div class="unotif__toggles">
        <label class="unotif__toggle">
          <input
            v-model="groupedView"
            type="checkbox"
            data-testid="notif-grouped-toggle"
          />
          <span>{{ $t('workspace.notificationsGroupToggle') }}</span>
        </label>
        <label class="unotif__toggle">
          <input
            v-model="unreadOnly"
            type="checkbox"
            data-testid="notif-unread-toggle"
          />
          <span>{{ $t('workspace.notificationsUnreadOnly') }}</span>
        </label>
        <label
          v-if="items.length > 0"
          class="unotif__toggle"
        >
          <input
            type="checkbox"
            :checked="allSelected"
            data-testid="notif-select-all"
            @change="toggleSelectAll"
          />
          <span>{{ $t('workspace.notificationsSelectAll') }}</span>
        </label>
      </div>

      <div class="unotif__bulk-actions">
        <span
          v-if="selectedIds.length > 0"
          class="unotif__selected-count"
        >
          {{ $t('workspace.notificationsSelectedCount', { count: selectedIds.length }) }}
        </span>
        <AppButton
          v-if="selectedIds.length > 0"
          size="s"
          variant="secondary"
          :loading="bulkBusy"
          data-testid="notif-bulk-read"
          @click="handleBulkRead"
        >
          <Check
            :size="14"
            aria-hidden="true"
          />
          {{ $t('workspace.notificationsBulkRead') }}
        </AppButton>
        <AppButton
          v-if="selectedIds.length > 0"
          size="s"
          variant="secondary"
          :loading="bulkBusy"
          data-testid="notif-bulk-archive"
          @click="handleBulkArchive(false)"
        >
          <Archive
            :size="14"
            aria-hidden="true"
          />
          {{ $t('workspace.notificationsBulkArchive') }}
        </AppButton>
        <AppButton
          size="s"
          variant="ghost"
          :loading="bulkBusy"
          data-testid="notif-archive-read"
          @click="handleBulkArchive(true)"
        >
          {{ $t('workspace.notificationsArchiveRead') }}
        </AppButton>
      </div>
    </div>

    <div
      v-if="showPreferences"
      class="unotif__prefs"
    >
      <div
        v-for="pref in preferences"
        :key="pref.category"
        class="unotif__pref-row"
      >
        <strong>{{ pref.category }}</strong>
        <label>
          <input
            v-model="pref.inApp"
            type="checkbox"
            @change="savePref(pref)"
          />
          {{ chatT('prefInApp') }}
        </label>
        <label>
          <input
            v-model="pref.realtime"
            type="checkbox"
            @change="savePref(pref)"
          />
          {{ chatT('prefRealtime') }}
        </label>
        <label>
          <input
            v-model="pref.webPush"
            type="checkbox"
            @change="savePref(pref)"
          />
          {{ chatT('prefWebPush') }}
        </label>
        <label>
          <input
            v-model="pref.email"
            type="checkbox"
            @change="savePref(pref)"
          />
          {{ chatT('prefEmail') }}
        </label>
        <label>
          <input
            v-model="pref.sound"
            type="checkbox"
            @change="savePref(pref)"
          />
          {{ chatT('prefSound') }}
        </label>
      </div>
    </div>

    <p
      v-if="items.length === 0"
      class="unotif__empty"
    >
      {{ chatT('notifEmpty') }}
    </p>

    <div
      v-else
      class="unotif__groups"
    >
      <section
        v-for="group in displayedGroups"
        :key="group.key"
        class="unotif__group"
      >
        <header
          v-if="groupedView"
          class="unotif__group-head"
        >
          <strong>{{ group.label ?? group.key }}</strong>
          <AppBadge variant="neutral">{{ group.count }}</AppBadge>
        </header>

        <ul class="unotif__list">
          <li
            v-for="item in group.items"
            :key="item.id"
            class="unotif__item"
            :class="{
              'unotif__item--unread': !item.readAt,
              'unotif__item--unavailable': item.resourceAvailable === false,
            }"
            data-testid="notif-item"
          >
            <div class="unotif__item-lead">
              <input
                type="checkbox"
                class="unotif__checkbox"
                :checked="isSelected(item.id)"
                :aria-label="item.title"
                data-testid="notif-item-checkbox"
                @change="toggleSelect(item.id)"
              />
              <div class="unotif__item-body">
                <div class="unotif__item-title-row">
                  <strong>{{ item.title }}</strong>
                  <AppBadge
                    v-if="item.resourceAvailable === false"
                    variant="warning"
                    data-testid="notif-unavailable-badge"
                  >
                    {{ $t('workspace.notificationsUnavailable') }}
                  </AppBadge>
                </div>
                <p v-if="item.body">{{ item.body }}</p>
                <small class="unotif__item-meta">
                  {{ item.category }} · {{ new Date(item.createdAt).toLocaleString() }}
                </small>
              </div>
            </div>

            <div class="unotif__item-actions">
              <AppButton
                v-if="item.resourcePath"
                size="s"
                variant="secondary"
                :disabled="item.resourceAvailable === false"
                data-testid="notif-open-target"
                @click="openResource(item)"
              >
                {{ $t('workspace.notificationsOpenTarget') }}
              </AppButton>
              <AppButton
                v-if="!item.readAt"
                size="s"
                variant="ghost"
                :aria-label="chatT('notifMarkAllRead')"
                @click="markRead(item)"
              >
                ✓
              </AppButton>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>

<style scoped>
.unotif {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
  padding: var(--space-16);
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
}

.unotif__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-10);
}

.unotif__heading {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.unotif__title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
}

.unotif__actions,
.unotif__tabs,
.unotif__bulk-actions,
.unotif__toggles {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-6);
}

.unotif__push-panel {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-10);
  padding: var(--space-10) var(--space-12);
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--surface);
}

.unotif__push-copy {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
}

.unotif__push-title {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.unotif__push-detail {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.unotif__tab {
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-2);
  border-radius: var(--radius-control-s);
  padding: var(--space-6) var(--space-10);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  cursor: pointer;
  transition: var(--theme-transition);
}

.unotif__tab--active {
  border-color: var(--accent);
  background: var(--selected-surface);
  color: var(--text-1);
}

.unotif__toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-10);
  padding: var(--space-8) var(--space-10);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
}

.unotif__toggle {
  display: inline-flex;
  align-items: center;
  gap: var(--space-6);
  cursor: pointer;
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.unotif__selected-count {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.unotif__prefs {
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  padding: var(--space-10);
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
  background: var(--surface);
}

.unotif__pref-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-12);
  align-items: center;
}

.unotif__groups {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

.unotif__group {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.unotif__group-head {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding-bottom: var(--space-4);
  border-bottom: 1px solid var(--border);
}

.unotif__list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.unotif__item {
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  padding: var(--space-10) var(--space-12);
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-10);
  transition: var(--theme-transition);
}

.unotif__item--unread {
  border-color: var(--accent);
  background: var(--surface);
}

.unotif__item--unavailable {
  opacity: 0.8;
}

.unotif__item-lead {
  display: flex;
  align-items: flex-start;
  gap: var(--space-10);
  flex: 1 1 240px;
  min-width: 0;
}

.unotif__checkbox {
  margin-top: var(--space-4);
  flex: 0 0 auto;
}

.unotif__item-body {
  min-width: 0;
}

.unotif__item-title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-6);
}

.unotif__item-body p {
  margin: var(--space-4) 0;
}

.unotif__item-meta {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.unotif__item-actions {
  display: flex;
  align-items: center;
  gap: var(--space-6);
}

.unotif__empty {
  color: var(--text-2);
  padding: var(--space-16) 0;
}
</style>
