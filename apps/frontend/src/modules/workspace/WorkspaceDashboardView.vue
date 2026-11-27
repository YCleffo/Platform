<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Bell, Clock, Command, Layers, RotateCw, Search, Trash2 } from '@/shared/ui/icons';
import {
  isConfirmationRequiredError,
  workspaceApi,
  type WorkspaceDashboardResponse,
  type WorkspaceQuickAction,
  type WorkspaceSummaryItem,
} from '@/shared/api/workspaceApi';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { useOperationsCenter } from './useOperationsCenter';

const { t } = useI18n();
const router = useRouter();
const toast = useToast();
const operations = useOperationsCenter();

const loading = ref(true);
const error = ref('');
const dashboard = ref<WorkspaceDashboardResponse | null>(null);
const confirmOpen = ref(false);
const confirmBusy = ref(false);
const pendingAction = ref<WorkspaceQuickAction | null>(null);

const summaryCards = computed<WorkspaceSummaryItem[]>(() => {
  const raw = dashboard.value?.summary;
  if (!raw) return [];
  if (Array.isArray(raw)) return raw;
  return Object.entries(raw).map(([key, value]) => ({
    key,
    label: key
      .replace(/([A-Z])/g, ' $1')
      .replace(/[_-]+/g, ' ')
      .trim(),
    value,
  }));
});

const recentOps = computed(() => operations.operations.slice(0, 5));

let loadSeq = 0;

async function loadDashboard(): Promise<void> {
  const seq = ++loadSeq;
  loading.value = true;
  error.value = '';
  try {
    await operations.ensureOrganization();
    if (seq !== loadSeq) return;
    const res = await workspaceApi.getDashboard(operations.organizationId);
    if (seq !== loadSeq) return;
    dashboard.value = res;
    if (res.organizationId && !operations.organizationId) {
      operations.setOrganizationId(res.organizationId);
    }
  } catch (caught) {
    if (seq === loadSeq) {
      error.value = resolveErrorMessage(caught);
    }
  } finally {
    if (seq === loadSeq) {
      loading.value = false;
    }
  }
}

function handleOrganizationChange(nextOrgId: string | string[]): void {
  if (typeof nextOrgId === 'string' && nextOrgId !== '' && nextOrgId !== operations.organizationId) {
    operations.setOrganizationId(nextOrgId);
  }
}

async function runQuickAction(action: WorkspaceQuickAction, confirmed = false): Promise<void> {
  if (action.dangerous && !confirmed) {
    pendingAction.value = action;
    confirmOpen.value = true;
    return;
  }
  if (action.actionCode) {
    try {
      await operations.executeAction({
        actionCode: action.actionCode,
        organizationId: dashboard.value?.organizationId ?? operations.organizationId ?? undefined,
        confirmed,
      });
      toast.success(t('workspace.cmdExecuted'));
      void loadDashboard();
    } catch (caught) {
      if (!confirmed && isConfirmationRequiredError(caught)) {
        pendingAction.value = action;
        confirmOpen.value = true;
        return;
      }
      toast.error(resolveErrorMessage(caught));
    }
    return;
  }
  if (action.route) {
    await router.push(action.route);
  }
}

async function confirmQuickAction(): Promise<void> {
  if (!pendingAction.value) return;
  confirmBusy.value = true;
  try {
    await runQuickAction(pendingAction.value, true);
    confirmOpen.value = false;
    pendingAction.value = null;
  } finally {
    confirmBusy.value = false;
  }
}

watch(
  () => operations.organizationId,
  () => {
    void loadDashboard();
  },
);

onMounted(() => {
  void loadDashboard();
});
</script>

<template>
  <section
    class="ws-dashboard"
    data-testid="workspace-dashboard"
  >
    <header class="ws-dashboard__header">
      <div class="ws-dashboard__title-group">
        <h1 class="ws-dashboard__title">{{ $t('workspace.title') }}</h1>
        <p class="ws-dashboard__subtitle">{{ $t('workspace.subtitle') }}</p>
      </div>
      <div class="ws-dashboard__toolbar">
        <AppButton
          size="s"
          variant="secondary"
          data-testid="workspace-open-palette-btn"
          @click="operations.commandPaletteOpen = true"
        >
          <Command
            :size="16"
            aria-hidden="true"
          />
          {{ $t('workspace.openCommandPalette') }}
        </AppButton>
        <AppButton
          size="s"
          variant="secondary"
          data-testid="workspace-open-operations-btn"
          @click="operations.drawerOpen = true"
        >
          <Clock
            :size="16"
            aria-hidden="true"
          />
          {{ $t('workspace.openOperations') }}
          <AppBadge
            v-if="operations.activeBadgeCount > 0"
            variant="primary"
          >
            {{ operations.activeBadgeCount }}
          </AppBadge>
        </AppButton>
        <AppButton
          size="s"
          variant="ghost"
          @click="loadDashboard"
        >
          <RotateCw
            :size="16"
            aria-hidden="true"
          />
          {{ $t('common.retry') }}
        </AppButton>
      </div>
    </header>

    <nav
      class="ws-dashboard__shortcuts"
      :aria-label="$t('workspace.quickActionsTitle')"
    >
      <RouterLink
        class="ws-shortcut"
        :to="{ name: 'search' }"
      >
        <Search
          :size="16"
          aria-hidden="true"
        />
        <span>{{ $t('workspace.openSearch') }}</span>
      </RouterLink>
      <RouterLink
        class="ws-shortcut"
        :to="{ name: 'trash' }"
      >
        <Trash2
          :size="16"
          aria-hidden="true"
        />
        <span>{{ $t('workspace.openTrash') }}</span>
      </RouterLink>
      <RouterLink
        class="ws-shortcut"
        :to="{ name: 'universal-notifications' }"
      >
        <Bell
          :size="16"
          aria-hidden="true"
        />
        <span>{{ $t('workspace.openNotifications') }}</span>
      </RouterLink>
    </nav>

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
        @click="loadDashboard"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <template v-else-if="dashboard">
      <div class="ws-dashboard__context-bar">
        <div
          v-if="operations.organizations.length > 1"
          class="ws-dashboard__context-item"
          data-testid="workspace-org-select"
        >
          <AppDropdown
            id="workspace-org-dropdown"
            :model-value="operations.organizationId ?? dashboard.organizationId ?? ''"
            :label="$t('workspace.cat_orgs')"
            :options="operations.organizations.map((o) => ({ value: o.id, label: o.name }))"
            @update:model-value="handleOrganizationChange"
          />
        </div>
        <div class="ws-dashboard__context-item">
          <span class="ws-dashboard__meta-label">{{ $t('workspace.roleContext') }}</span>
          <AppBadge variant="primary">{{ dashboard.roleContext }}</AppBadge>
        </div>
        <div class="ws-dashboard__context-item">
          <span class="ws-dashboard__meta-label">{{ $t('workspace.availableModules') }}</span>
          <div class="ws-dashboard__modules">
            <AppBadge
              v-for="mod in dashboard.availableModules"
              :key="mod"
              variant="neutral"
            >
              {{ mod }}
            </AppBadge>
            <span
              v-if="dashboard.availableModules.length === 0"
              class="ws-dashboard__muted"
            >
              {{ $t('workspace.noModules') }}
            </span>
          </div>
        </div>
      </div>

      <!-- Summary Counters -->
      <section
        v-if="summaryCards.length > 0"
        class="ws-dashboard__section"
      >
        <h2 class="ws-dashboard__section-title">{{ $t('workspace.summaryTitle') }}</h2>
        <div class="ws-dashboard__summary-grid">
          <AppCard
            v-for="card in summaryCards"
            :key="card.key"
            class="ws-summary-card"
          >
            <span class="ws-summary-card__label">{{ card.label }}</span>
            <strong class="ws-summary-card__value">{{ card.value }}</strong>
            <RouterLink
              v-if="card.route"
              :to="card.route"
              class="ws-summary-card__link"
            >
              {{ $t('workspace.openItem') }}
            </RouterLink>
          </AppCard>
        </div>
      </section>

      <!-- Quick Actions -->
      <section class="ws-dashboard__section">
        <h2 class="ws-dashboard__section-title">{{ $t('workspace.quickActionsTitle') }}</h2>
        <div
          v-if="dashboard.quickActions.length > 0"
          class="ws-dashboard__actions-grid"
        >
          <AppButton
            v-for="action in dashboard.quickActions"
            :key="action.id"
            :variant="action.dangerous ? 'danger' : 'secondary'"
            @click="runQuickAction(action)"
          >
            {{ action.title }}
          </AppButton>
        </div>
        <p
          v-else
          class="ws-dashboard__muted"
        >
          {{ $t('workspace.noQuickActions') }}
        </p>
      </section>

      <!-- Widgets -->
      <section class="ws-dashboard__section">
        <h2 class="ws-dashboard__section-title">{{ $t('workspace.widgetsTitle') }}</h2>
        <div
          v-if="dashboard.widgets.length > 0"
          class="ws-dashboard__widgets-grid"
        >
          <AppCard
            v-for="widget in dashboard.widgets"
            :key="widget.id"
            class="ws-widget"
          >
            <header class="ws-widget__head">
              <div>
                <h3 class="ws-widget__title">{{ widget.title }}</h3>
                <p
                  v-if="widget.description"
                  class="ws-widget__desc"
                >
                  {{ widget.description }}
                </p>
              </div>
              <RouterLink
                v-if="widget.route"
                :to="widget.route"
                class="ws-widget__more"
              >
                {{ $t('workspace.openItem') }}
              </RouterLink>
            </header>

            <ul
              v-if="widget.items && widget.items.length > 0"
              class="ws-widget__list"
            >
              <li
                v-for="item in widget.items"
                :key="item.id"
                class="ws-widget__item"
              >
                <div class="ws-widget__item-copy">
                  <RouterLink
                    v-if="item.route"
                    :to="item.route"
                    class="ws-widget__item-link"
                  >
                    {{ item.title }}
                  </RouterLink>
                  <strong
                    v-else
                    class="ws-widget__item-title"
                  >
                    {{ item.title }}
                  </strong>
                  <span
                    v-if="item.subtitle"
                    class="ws-widget__item-sub"
                  >
                    {{ item.subtitle }}
                  </span>
                </div>
                <AppBadge
                  v-if="item.badge || item.status"
                  variant="neutral"
                >
                  {{ item.badge ?? item.status }}
                </AppBadge>
              </li>
            </ul>
            <p
              v-else
              class="ws-dashboard__muted"
            >
              {{ widget.emptyText ?? $t('workspace.noRecentActivity') }}
            </p>
          </AppCard>
        </div>
        <AppEmptyState
          v-else
          :title="$t('workspace.noWidgets')"
        />
      </section>

      <!-- Recent Activity & Recent Operations -->
      <div class="ws-dashboard__bottom-grid">
        <AppCard class="ws-widget">
          <h2 class="ws-widget__title">{{ $t('workspace.recentActivityTitle') }}</h2>
          <ul
            v-if="dashboard.recentActivity.length > 0"
            class="ws-widget__list"
          >
            <li
              v-for="entry in dashboard.recentActivity"
              :key="entry.id"
              class="ws-widget__item"
            >
              <div class="ws-widget__item-copy">
                <RouterLink
                  v-if="entry.route"
                  :to="entry.route"
                  class="ws-widget__item-link"
                >
                  {{ entry.title }}
                </RouterLink>
                <strong
                  v-else
                  class="ws-widget__item-title"
                >
                  {{ entry.title }}
                </strong>
                <span class="ws-widget__item-sub">
                  {{ entry.subtitle ? `${entry.subtitle} · ` : ''
                  }}{{ new Date(entry.createdAt).toLocaleString() }}
                </span>
              </div>
              <AppBadge
                v-if="entry.category || entry.status"
                variant="neutral"
              >
                {{ entry.category ?? entry.status }}
              </AppBadge>
            </li>
          </ul>
          <p
            v-else
            class="ws-dashboard__muted"
          >
            {{ $t('workspace.noRecentActivity') }}
          </p>
        </AppCard>

        <AppCard class="ws-widget">
          <header class="ws-widget__head">
            <h2 class="ws-widget__title">{{ $t('workspace.recentOperationsTitle') }}</h2>
            <AppButton
              size="s"
              variant="ghost"
              @click="operations.drawerOpen = true"
            >
              <Layers
                :size="14"
                aria-hidden="true"
              />
              {{ $t('workspace.openOperations') }}
            </AppButton>
          </header>
          <ul
            v-if="recentOps.length > 0"
            class="ws-widget__list"
          >
            <li
              v-for="op in recentOps"
              :key="op.id"
              class="ws-widget__item"
            >
              <div class="ws-widget__item-copy">
                <strong class="ws-widget__item-title">{{ op.title }}</strong>
                <span class="ws-widget__item-sub">
                  {{ new Date(op.updatedAt).toLocaleString() }}
                </span>
              </div>
              <AppBadge
                :variant="
                  op.status === 'failed'
                    ? 'danger'
                    : op.status === 'completed'
                      ? 'success'
                      : 'primary'
                "
              >
                {{ op.status }}
              </AppBadge>
            </li>
          </ul>
          <p
            v-else
            class="ws-dashboard__muted"
          >
            {{ $t('workspace.operationsEmpty') }}
          </p>
        </AppCard>
      </div>
    </template>

    <AppConfirmDialog
      v-model="confirmOpen"
      :title="$t('workspace.cmdConfirmTitle')"
      :description="pendingAction?.description ?? $t('workspace.cmdConfirmDesc')"
      :confirm-label="$t('common.confirm')"
      :busy="confirmBusy"
      :danger="Boolean(pendingAction?.dangerous)"
      @confirm="confirmQuickAction"
    />
  </section>
</template>

<style scoped>
.ws-dashboard {
  display: flex;
  flex-direction: column;
  gap: var(--space-20);
  max-width: 1200px;
  margin: 0 auto;
  width: 100%;
}

.ws-dashboard__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-12);
}

.ws-dashboard__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  color: var(--text-1);
}

.ws-dashboard__subtitle {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.ws-dashboard__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-dashboard__shortcuts {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-shortcut {
  display: inline-flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  transition: var(--theme-transition);
}

.ws-shortcut:hover {
  background: var(--surface-hover);
  border-color: var(--accent);
}

.ws-dashboard__context-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-16);
  padding: var(--space-12) var(--space-16);
  border-radius: var(--radius-card-m);
  background: var(--card);
  border: 1px solid var(--border);
}

.ws-dashboard__context-item {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-dashboard__meta-label {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ws-dashboard__modules {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-6);
}

.ws-dashboard__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

.ws-dashboard__section-title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}

.ws-dashboard__summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: var(--space-12);
}

.ws-summary-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.ws-summary-card__label {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  text-transform: capitalize;
}

.ws-summary-card__value {
  font-size: var(--type-metric-number-size);
  line-height: var(--type-metric-number-line);
  font-weight: var(--type-metric-number-weight);
  color: var(--text-1);
}

.ws-summary-card__link {
  font-size: var(--type-link-small-size);
  line-height: var(--type-link-small-line);
  font-weight: var(--type-link-small-weight);
}

.ws-dashboard__actions-grid {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-dashboard__widgets-grid,
.ws-dashboard__bottom-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: var(--space-16);
}

.ws-widget {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

.ws-widget__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-10);
}

.ws-widget__title {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.ws-widget__desc {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ws-widget__more {
  flex: 0 0 auto;
  font-size: var(--type-link-small-size);
  line-height: var(--type-link-small-line);
  font-weight: var(--type-link-small-weight);
}

.ws-widget__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.ws-widget__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-10);
  padding: var(--space-8) var(--space-10);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
}

.ws-widget__item-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.ws-widget__item-title,
.ws-widget__item-link {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ws-widget__item-sub,
.ws-dashboard__muted {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
</style>
