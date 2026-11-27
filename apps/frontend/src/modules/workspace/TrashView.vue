<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RotateCw, Trash2, Undo2 } from '@/shared/ui/icons';
import {
  extractRestoreConflict,
  workspaceApi,
  type RestoreConflictDetails,
  type TrashItem,
} from '@/shared/api/workspaceApi';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import { useOperationsCenter } from './useOperationsCenter';

const TRASH_CATEGORIES = [
  'all',
  'files',
  'documents',
  'lessons',
  'homework',
  'tests',
  'chats',
] as const;

const { t } = useI18n();
const toast = useToast();
const operations = useOperationsCenter();

const loading = ref(true);
const error = ref('');
const items = ref<TrashItem[]>([]);
const total = ref(0);
const category = ref<string>('all');
const busyId = ref<string | null>(null);
const nowMs = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | null = null;

// Conflict modal state
const conflictOpen = ref(false);
const conflictBusy = ref(false);
const conflictItem = ref<TrashItem | null>(null);
const conflictDetails = ref<RestoreConflictDetails | null>(null);

// Purge single item modal state
const purgeOpen = ref(false);
const purgeBusy = ref(false);
const purgeTarget = ref<TrashItem | null>(null);

// Empty trash modal state
const emptyOpen = ref(false);
const emptyBusy = ref(false);

const categoryOptions = computed(() => {
  const dynamic = new Set<string>(TRASH_CATEGORIES);
  for (const item of items.value) {
    if (item.category) dynamic.add(item.category);
  }
  return Array.from(dynamic);
});

function categoryLabel(cat: string): string {
  const key = `workspace.cat_${cat}`;
  const translated = t(key);
  return translated === key ? cat : translated;
}

function formatExpiryCountdown(expiresAt?: string | null): string {
  if (!expiresAt) return '';
  const diffMs = Date.parse(expiresAt) - nowMs.value;
  if (!Number.isFinite(diffMs)) return '';
  if (diffMs <= 0) return t('workspace.trashExpired');
  const totalSec = Math.floor(diffMs / 1000);
  const days = Math.floor(totalSec / 86400);
  const hours = Math.floor((totalSec % 86400) / 3600);
  const minutes = Math.floor((totalSec % 3600) / 60);
  if (days > 0) return t('workspace.trashExpiresIn', { time: `${days}d ${hours}h` });
  if (hours > 0) return t('workspace.trashExpiresIn', { time: `${hours}h ${minutes}m` });
  return t('workspace.trashExpiresIn', { time: `${Math.max(1, minutes)}m` });
}

async function loadTrash(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    await operations.ensureOrganization();
    const res = await workspaceApi.listTrash({
      category: category.value,
      limit: 100,
      organizationId: operations.organizationId,
    });
    items.value = res.items ?? [];
    total.value = res.total ?? items.value.length;
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function handleRestore(item: TrashItem, renameOnConflict = false): Promise<void> {
  busyId.value = item.id;
  try {
    const res = await workspaceApi.restoreTrashItem(item.id, {
      renameOnConflict,
      organizationId: operations.organizationId,
    });
    if (res.restored) {
      items.value = items.value.filter((entry) => entry.id !== item.id);
      total.value = Math.max(0, total.value - 1);
      toast.success(t('workspace.trashRestored'));
      conflictOpen.value = false;
      conflictItem.value = null;
      conflictDetails.value = null;
    }
  } catch (caught) {
    const conflict = extractRestoreConflict(caught);
    if (conflict && !renameOnConflict) {
      conflictItem.value = item;
      conflictDetails.value = conflict;
      conflictOpen.value = true;
      return;
    }
    toast.error(resolveErrorMessage(caught));
  } finally {
    busyId.value = null;
  }
}

async function confirmConflictRename(): Promise<void> {
  if (!conflictItem.value) return;
  conflictBusy.value = true;
  try {
    await handleRestore(conflictItem.value, true);
  } finally {
    conflictBusy.value = false;
  }
}

function askPurge(item: TrashItem): void {
  purgeTarget.value = item;
  purgeOpen.value = true;
}

async function confirmPurge(): Promise<void> {
  if (!purgeTarget.value) return;
  purgeBusy.value = true;
  try {
    await workspaceApi.purgeTrashItem(purgeTarget.value.id, operations.organizationId);
    items.value = items.value.filter((entry) => entry.id !== purgeTarget.value!.id);
    total.value = Math.max(0, total.value - 1);
    purgeOpen.value = false;
    purgeTarget.value = null;
    toast.success(t('workspace.trashPurged'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    purgeBusy.value = false;
  }
}

async function confirmEmptyTrash(): Promise<void> {
  emptyBusy.value = true;
  try {
    const res = await workspaceApi.emptyTrash(operations.organizationId);
    items.value = [];
    total.value = 0;
    emptyOpen.value = false;
    toast.success(t('workspace.trashEmptied', { count: res.purgedCount }));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    emptyBusy.value = false;
  }
}

watch([category, () => operations.organizationId], () => {
  void loadTrash();
});

onMounted(() => {
  void loadTrash();
  ticker = setInterval(() => {
    nowMs.value = Date.now();
  }, 60_000);
});

onBeforeUnmount(() => {
  if (ticker) clearInterval(ticker);
});
</script>

<template>
  <section
    class="ws-trash"
    data-testid="trash-page"
  >
    <header class="ws-trash__header">
      <div>
        <h1 class="ws-trash__title">{{ $t('workspace.trashTitle') }}</h1>
        <p class="ws-trash__subtitle">{{ $t('workspace.trashSubtitle') }}</p>
      </div>
      <div class="ws-trash__actions">
        <AppButton
          size="s"
          variant="ghost"
          @click="loadTrash"
        >
          <RotateCw
            :size="16"
            aria-hidden="true"
          />
          {{ $t('common.retry') }}
        </AppButton>
        <AppButton
          v-if="items.length > 0"
          size="s"
          variant="danger"
          data-testid="trash-empty-btn"
          @click="emptyOpen = true"
        >
          <Trash2
            :size="16"
            aria-hidden="true"
          />
          {{ $t('workspace.trashEmptyAction') }}
        </AppButton>
      </div>
    </header>

    <div
      class="ws-trash__tabs"
      role="tablist"
      :aria-label="$t('workspace.trashTitle')"
    >
      <button
        v-for="cat in categoryOptions"
        :key="cat"
        type="button"
        role="tab"
        class="ws-trash__tab"
        :class="{ 'ws-trash__tab--active': category === cat }"
        :aria-selected="category === cat"
        @click="category = cat"
      >
        {{ categoryLabel(cat) }}
      </button>
    </div>

    <AppSkeleton
      v-if="loading"
      variant="list"
      :lines="4"
    />

    <AppErrorState
      v-else-if="error"
      :message="error"
    >
      <AppButton
        variant="secondary"
        @click="loadTrash"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <AppEmptyState
      v-else-if="items.length === 0"
      :title="$t('workspace.trashEmptyState')"
    />

    <div
      v-else
      class="ws-trash__list"
    >
      <AppCard
        v-for="item in items"
        :key="item.id"
        class="ws-trash__item"
        data-testid="trash-item"
      >
        <div class="ws-trash__item-row">
          <div class="ws-trash__item-info">
            <div class="ws-trash__item-head">
              <strong class="ws-trash__item-title">{{ item.title }}</strong>
              <AppBadge variant="neutral">{{ categoryLabel(item.category) }}</AppBadge>
              <AppBadge
                v-if="item.expiresAt"
                variant="warning"
              >
                {{ formatExpiryCountdown(item.expiresAt) }}
              </AppBadge>
            </div>
            <p
              v-if="item.subtitle"
              class="ws-trash__item-meta"
            >
              {{ item.subtitle }}
            </p>
            <p class="ws-trash__item-meta">
              {{ $t('workspace.trashDeletedAt') }}: {{ new Date(item.deletedAt).toLocaleString() }}
              <template v-if="item.originalPath">
                · {{ $t('workspace.trashOriginalPath') }}: {{ item.originalPath }}
              </template>
            </p>
          </div>

          <div class="ws-trash__item-actions">
            <AppButton
              v-if="item.canRestore !== false"
              size="s"
              variant="secondary"
              :loading="busyId === item.id"
              data-testid="trash-restore-btn"
              @click="handleRestore(item)"
            >
              <Undo2
                :size="14"
                aria-hidden="true"
              />
              {{ $t('workspace.trashRestore') }}
            </AppButton>
            <AppButton
              size="s"
              variant="danger"
              :disabled="busyId === item.id"
              data-testid="trash-purge-btn"
              @click="askPurge(item)"
            >
              <Trash2
                :size="14"
                aria-hidden="true"
              />
              {{ $t('workspace.trashPurge') }}
            </AppButton>
          </div>
        </div>
      </AppCard>
    </div>

    <!-- Restore Conflict Dialog -->
    <div data-testid="trash-conflict-dialog">
      <AppConfirmDialog
        v-model="conflictOpen"
        :title="$t('workspace.trashConflictTitle')"
        :description="
          conflictDetails?.suggestedName
            ? $t('workspace.trashConflictDesc', { suggested: conflictDetails.suggestedName })
            : conflictDetails?.message
        "
        :confirm-label="
          conflictDetails?.suggestedName
            ? $t('workspace.trashConflictRename', { suggested: conflictDetails.suggestedName })
            : $t('workspace.trashRestore')
        "
        :busy="conflictBusy"
        @confirm="confirmConflictRename"
      />
    </div>

    <!-- Purge Single Confirmation -->
    <AppConfirmDialog
      v-model="purgeOpen"
      :title="$t('workspace.trashPurgeConfirmTitle')"
      :description="$t('workspace.trashPurgeConfirmDesc', { name: purgeTarget?.title ?? '' })"
      :confirm-label="$t('workspace.trashPurge')"
      :busy="purgeBusy"
      danger
      @confirm="confirmPurge"
    />

    <!-- Empty Trash Confirmation -->
    <AppConfirmDialog
      v-model="emptyOpen"
      :title="$t('workspace.trashEmptyConfirmTitle')"
      :description="$t('workspace.trashEmptyConfirmDesc')"
      :confirm-label="$t('workspace.trashEmptyAction')"
      :busy="emptyBusy"
      danger
      @confirm="confirmEmptyTrash"
    />
  </section>
</template>

<style scoped>
.ws-trash {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
  max-width: 1080px;
  margin: 0 auto;
  width: 100%;
}

.ws-trash__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-12);
}

.ws-trash__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  color: var(--text-1);
}

.ws-trash__subtitle {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.ws-trash__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-trash__tabs {
  display: flex;
  flex-wrap: nowrap;
  overflow-x: auto;
  gap: var(--space-6);
  padding-bottom: var(--space-4);
}

.ws-trash__tab {
  flex: 0 0 auto;
  padding: var(--space-6) var(--space-12);
  border-radius: var(--radius-badge-pill);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  transition: var(--theme-transition);
}

.ws-trash__tab--active {
  background: var(--selected-surface);
  border-color: var(--selected-accent);
  color: var(--accent-text);
}

.ws-trash__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-10);
}

.ws-trash__item-row {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-12);
}

.ws-trash__item-info {
  min-width: 0;
  flex: 1 1 260px;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.ws-trash__item-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
}

.ws-trash__item-title {
  font-size: var(--type-body-large-size);
  line-height: var(--type-body-large-line);
  font-weight: var(--type-body-large-emphasis-weight);
  color: var(--text-1);
}

.ws-trash__item-meta {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ws-trash__item-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
</style>
