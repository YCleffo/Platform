<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { cachedAdminGet, peekAdminCache } from './adminCache';
import { useAdminOperation } from './useAdminOperation';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';

const auth = useAdminAuthStore();
const operationGuard = useAdminOperation();
const { t } = useI18n();
const toast = useToast();
const refreshing = ref(false);
const counts = ref<Record<string, number>>({});
const accountStatuses = ref<Array<{ label: string; count: number }>>([]);
const failed = ref<string[]>([]);

interface CountResource {
  key: string;
  permission: string;
  path: string;
}

const resources: CountResource[] = [
  { key: 'organizations', permission: 'core.organizations.read', path: '/core/organizations' },
  { key: 'persons', permission: 'core.persons.read', path: '/core/persons' },
  { key: 'accounts', permission: 'core.accounts.read', path: '/core/accounts' },
  { key: 'roles', permission: 'core.rbac.read', path: '/core/roles' },
  { key: 'modules', permission: 'core.modules.read', path: '/core/modules' },
  { key: 'flags', permission: 'core.feature_flags.read', path: '/core/feature-flags' },
  { key: 'privacy', permission: 'core.privacy.requests.read', path: '/core/privacy/requests' },
  {
    key: 'legalHolds',
    permission: 'core.privacy.legal_holds.read',
    path: '/core/privacy/legal-holds',
  },
  { key: 'audit', permission: 'core.superadmin', path: '/core/audit-events' },
];

function countOf(data: unknown): { count: number; items: Record<string, unknown>[] } {
  if (Array.isArray(data)) {
    return {
      count: data.length,
      items: data.filter(
        (item): item is Record<string, unknown> => typeof item === 'object' && item !== null,
      ),
    };
  }
  if (typeof data === 'object' && data !== null) {
    const record = data as Record<string, unknown>;
    const pagination =
      typeof record.pagination === 'object' && record.pagination !== null
        ? (record.pagination as Record<string, unknown>)
        : null;
    const items = Array.isArray(record.items) ? record.items : [];
    const count =
      typeof record.total === 'number'
        ? record.total
        : typeof pagination?.total === 'number'
          ? pagination.total
          : items.length;
    return {
      count,
      items: items.filter(
        (item): item is Record<string, unknown> => typeof item === 'object' && item !== null,
      ),
    };
  }
  return { count: 0, items: [] };
}

function pathFor(resource: CountResource): string {
  return resource.path.includes('/privacy/legal-holds')
    ? resource.path
    : `${resource.path}?limit=100&offset=0`;
}

function apply(resource: CountResource, data: unknown): void {
  const result = countOf(data);
  counts.value = { ...counts.value, [resource.key]: result.count };
  if (resource.key !== 'accounts') return;
  const statusMap = new Map<string, number>();
  for (const account of result.items) {
    const status = typeof account.status === 'string' ? account.status : 'unknown';
    statusMap.set(status, (statusMap.get(status) ?? 0) + 1);
  }
  accountStatuses.value = [...statusMap.entries()]
    .map(([label, count]) => ({ label, count }))
    .sort((a, b) => b.count - a.count);
}

async function load(): Promise<void> {
  const operation = operationGuard.begin();
  failed.value = [];
  const accessible = resources.filter((resource) => auth.can(resource.permission));
  for (const resource of accessible) {
    const cached = peekAdminCache<unknown>(pathFor(resource));
    if (cached !== undefined) apply(resource, cached);
  }
  refreshing.value = accessible.length > 0;
  for (const resource of accessible) {
    const path = pathFor(resource);
    try {
      if (!operationGuard.current(operation)) return;
      const result = await cachedAdminGet<unknown>(path, peekAdminCache(path) !== undefined);
      if (!operationGuard.current(operation)) return;
      apply(resource, result);
    } catch (error) {
      if (!operationGuard.current(operation)) return;
      const next = { ...counts.value };
      delete next[resource.key];
      counts.value = next;
      if (resource.key === 'accounts') accountStatuses.value = [];
      failed.value.push(`${resource.key}: ${resolveErrorMessage(error)}`);
    }
  }
  if (!operationGuard.current(operation)) return;
  refreshing.value = false;
  if (failed.value.length > 0) {
    toast.show(t('admin.partialDashboard'), 'error', 5000);
  }
}

const maxCount = computed(() => Math.max(1, ...Object.values(counts.value)));
const maxStatus = computed(() => Math.max(1, ...accountStatuses.value.map((item) => item.count)));
const cards = computed(() => {
  const items: Array<{ key: string; label: string; to: RouteLocationRaw }> = [
    { key: 'organizations', label: t('admin.organizations'), to: { name: 'admin-organizations' } },
    { key: 'persons', label: t('admin.persons'), to: { name: 'admin-persons' } },
    { key: 'accounts', label: t('admin.accounts'), to: { name: 'admin-accounts' } },
    { key: 'roles', label: t('admin.rbac'), to: { name: 'admin-rbac' } },
    { key: 'modules', label: t('admin.modules'), to: { name: 'admin-modules' } },
    {
      key: 'flags',
      label: t('admin.featureFlags'),
      to: { name: 'admin-settings', query: { tab: 'flags' } },
    },
    { key: 'privacy', label: t('admin.privacyRequests'), to: { name: 'admin-privacy' } },
    {
      key: 'legalHolds',
      label: t('admin.legalHolds'),
      to: { name: 'admin-privacy', query: { tab: 'holds' } },
    },
    { key: 'audit', label: t('admin.audit'), to: { name: 'admin-audit' } },
  ];
  return items.filter(({ key }) =>
    resources.some((resource) => resource.key === key && auth.can(resource.permission)),
  );
});

onMounted(load);
</script>

<template>
  <section class="admin-dashboard">
    <header class="admin-dashboard__head">
      <div>
        <h2 class="admin-dashboard__title">{{ $t('admin.dashboardTitle') }}</h2>
        <p class="admin-dashboard__subtitle">{{ $t('admin.dashboardSubtitle') }}</p>
      </div>
      <p class="admin-dashboard__roles">{{ auth.roles.join(' · ') }}</p>
    </header>

    <template v-if="cards.length">
      <div class="admin-dashboard__cards">
        <RouterLink
          v-for="card in cards"
          :key="card.key"
          :to="card.to"
          class="admin-dashboard__card"
        >
          <span>{{ card.label }}</span>
          <strong>{{ counts[card.key] ?? '…' }}</strong>
        </RouterLink>
      </div>

      <div class="admin-dashboard__grid">
        <AppCard>
          <h3>{{ $t('admin.platformCounts') }}</h3>
          <div
            v-for="card in cards"
            :key="card.key"
            class="admin-dashboard__bar-row"
          >
            <span>{{ card.label }}</span>
            <span class="admin-dashboard__bar-track">
              <span
                class="admin-dashboard__bar"
                :style="{ width: `${Math.round(((counts[card.key] ?? 0) / maxCount) * 100)}%` }"
              />
            </span>
            <span class="admin-dashboard__bar-value">{{ counts[card.key] }}</span>
          </div>
        </AppCard>
        <AppCard v-if="auth.can('core.accounts.read')">
          <h3>{{ $t('admin.accountsByStatus') }}</h3>
          <p
            v-if="accountStatuses.length === 0"
            class="admin-dashboard__muted"
          >
            {{ $t('admin.noAccountData') }}
          </p>
          <div
            v-for="item in accountStatuses"
            :key="item.label"
            class="admin-dashboard__bar-row"
          >
            <span>{{ item.label }}</span>
            <span class="admin-dashboard__bar-track">
              <span
                class="admin-dashboard__bar admin-dashboard__bar--accent"
                :style="{ width: `${Math.round((item.count / maxStatus) * 100)}%` }"
              />
            </span>
            <span class="admin-dashboard__bar-value">{{ item.count }}</span>
          </div>
        </AppCard>
      </div>

      <AppCard v-if="failed.length > 0">
        <h3>{{ $t('admin.permissionLimited') }}</h3>
        <ul class="admin-dashboard__failed">
          <li
            v-for="item in failed"
            :key="item"
          >
            {{ item }}
          </li>
        </ul>
      </AppCard>
    </template>
  </section>
</template>

<style scoped>
.admin-dashboard {
  display: grid;
  gap: var(--space-16);
}
.admin-dashboard__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-8);
}
.admin-dashboard__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.admin-dashboard__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
.admin-dashboard__roles {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}
.admin-dashboard__cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr));
  gap: var(--space-12);
}
.admin-dashboard__card {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-16);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--card);
  color: var(--text-1);
  text-decoration: none;
  transition: var(--theme-transition);
}
.admin-dashboard__card:hover {
  border-color: var(--accent);
  text-decoration: none;
}
.admin-dashboard__card span {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.admin-dashboard__card strong {
  font-size: var(--type-h1-size);
  letter-spacing: var(--type-h1-tracking);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
}
.admin-dashboard__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
  gap: var(--space-16);
}
.admin-dashboard__grid h3 {
  margin-bottom: var(--space-12);
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}
.admin-dashboard__bar-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 2fr auto;
  align-items: center;
  gap: var(--space-8);
  margin-top: var(--space-8);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
.admin-dashboard__bar-track {
  height: 8px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--surface-hover);
}
.admin-dashboard__bar {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
}
.admin-dashboard__bar--accent {
  background: var(--success);
}
.admin-dashboard__bar-value {
  font-variant-numeric: tabular-nums;
  color: var(--text-1);
}
.admin-dashboard__muted {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}
.admin-dashboard__failed {
  display: grid;
  gap: var(--space-4);
  padding-left: var(--space-20);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
</style>
