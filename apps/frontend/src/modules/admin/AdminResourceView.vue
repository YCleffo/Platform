<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { cachedAdminGet, peekAdminCache } from './adminCache';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { AdminResourceAction, AdminResourceConfig } from './types';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import { Info } from '@/shared/ui/icons';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppJsonResponseDialog from '@/shared/ui/overlays/AppJsonResponseDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const props = defineProps<{ config: AdminResourceConfig }>();
const { t } = useI18n();
const toast = useToast();
const items = ref<Record<string, unknown>[]>([]);
const total = ref(0);
const offset = ref(0);
const loading = ref(true);
const refreshing = ref(false);
const hoveredColumn = ref<number | null>(null);
const errorMessage = ref<string | null>(null);
const createOpen = ref(false);
const createBody = ref('{}');
const createError = ref('');
const actionOpen = ref(false);
const actionError = ref('');
const busy = ref(false);
const selectedAction = ref<AdminResourceAction | null>(null);
const selectedRow = ref<Record<string, unknown>>({});
const actionInputs = ref<Record<string, string>>({});
const actionBody = ref('{}');
const response = ref<unknown>(null);
const responseOpen = ref(false);
const pageSize = ref(props.config.pageSize ?? 25);
const pageSizeOptions = computed(() => (props.config.pageSizeOptions ?? [10, 25, 50, 100]).map(size => ({ value: String(size), label: String(size) })));
const columns = computed(() => {
  if (props.config.columns !== undefined) return props.config.columns;
  const first = items.value[0];
  if (first === undefined) return [];
  const preferred = ['id', 'code', 'name', 'displayName', 'email', 'status', 'role', 'label', 'createdAt', 'created_at'];
  const keys = Object.keys(first).filter(key => {
    const value = first[key];
    return value === null || ['string', 'number', 'boolean'].includes(typeof value);
  });
  const sorted = [
    ...preferred.filter(key => keys.includes(key)),
    ...keys.filter(key => !preferred.includes(key)),
  ];
  return sorted.slice(0, 7);
});

function onPageSizeChange(value: string | number | string[]): void {
  pageSize.value = Number(Array.isArray(value) ? value[0] : value);
  offset.value = 0;
  void load();
}

function listPath(): string {
  if (props.config.paginated === false) return props.config.listPath;
  const separator = props.config.listPath.includes('?') ? '&' : '?';
  return `${props.config.listPath}${separator}limit=${pageSize.value}&offset=${offset.value}`;
}

function extractList(data: unknown): { items: Record<string, unknown>[]; total: number } {
  if (Array.isArray(data)) {
    return { items: data.filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null), total: data.length };
  }
  if (typeof data === 'object' && data !== null) {
    const record = data as Record<string, unknown>;
    if (Array.isArray(record.items)) {
      const pagination = typeof record.pagination === 'object' && record.pagination !== null
        ? record.pagination as Record<string, unknown>
        : null;
      return {
        items: record.items.filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null),
        total: typeof record.total === 'number'
          ? record.total
          : typeof pagination?.total === 'number'
            ? pagination.total
            : record.items.length,
      };
    }
  }
  return { items: [], total: 0 };
}

async function decorate(rows: Record<string, unknown>[]): Promise<Record<string, unknown>[]> {
  return props.config.decorate === undefined ? rows : props.config.decorate(rows);
}

async function load(): Promise<void> {
  const path = listPath();
  const cached = peekAdminCache<unknown>(path);
  errorMessage.value = null;
  if (cached === undefined) {
    loading.value = true;
  } else {
    const cachedResult = extractList(cached);
    items.value = await decorate(cachedResult.items);
    total.value = cachedResult.total;
    refreshing.value = true;
  }
  try {
    const data = await cachedAdminGet<unknown>(path, cached !== undefined);
    const result = extractList(data);
    items.value = await decorate(result.items);
    total.value = result.total;
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
    refreshing.value = false;
  }
}


function cell(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
  return JSON.stringify(value);
}

function rowActions(row: Record<string, unknown>): AdminResourceAction[] {
  return props.config.actions?.(row) ?? [];
}

function detailsAction(row: Record<string, unknown>): AdminResourceAction | null {
  return rowActions(row).find(action => action.label === t('admin.details')) ?? null;
}

function menuActions(row: Record<string, unknown>): AdminResourceAction[] {
  return rowActions(row).filter(action => action.label !== t('admin.details'));
}

function openDetails(row: Record<string, unknown>): void {
  const action = detailsAction(row);
  if (action === null) {
    openRow(row);
    return;
  }
  openAction(action, row);
}

function rowActionOptions(row: Record<string, unknown>): Array<{ value: string; label: string }> {
  return menuActions(row).map((action, index) => ({ value: String(index), label: action.label }));
}

function runRowAction(row: Record<string, unknown>, value: string | number | string[]): void {
  const index = Number(Array.isArray(value) ? value[0] : value);
  const action = menuActions(row)[index];
  if (action !== undefined) openAction(action, row);
}

function openCreate(): void {
  createBody.value = JSON.stringify(props.config.create?.template ?? {}, null, 2);
  createError.value = '';
  createOpen.value = true;
}

async function submitCreate(): Promise<void> {
  if (props.config.create === undefined) return;
  createError.value = '';
  let body: unknown;
  try {
    body = JSON.parse(createBody.value);
  } catch {
    createError.value = t('admin.invalidJson');
    return;
  }
  busy.value = true;
  try {
    await coreAdminHttp.post(props.config.create.path, body);
    toast.show(t('admin.created'), 'success');
    createOpen.value = false;
    await load();
  } catch (error) {
    createError.value = resolveErrorMessage(error);
  } finally {
    busy.value = false;
  }
}

function openAction(action: AdminResourceAction, row: Record<string, unknown>): void {
  if (action.method === 'GET' && (action.inputs?.length ?? 0) === 0 && action.body === undefined) {
    selectedAction.value = action;
    selectedRow.value = row;
    actionInputs.value = {};
    actionBody.value = '{}';
    actionError.value = '';
    void executeAction();
    return;
  }
  selectedAction.value = action;
  selectedRow.value = row;
  actionInputs.value = Object.fromEntries((action.inputs ?? []).map(input => [input.name, '']));
  actionBody.value = JSON.stringify(action.body ?? {}, null, 2);
  actionError.value = '';
  actionOpen.value = true;
}

async function executeAction(): Promise<void> {
  const action = selectedAction.value;
  if (action === null) return;
  actionError.value = '';
  let body: unknown;
  try {
    body = JSON.parse(actionBody.value);
  } catch {
    actionError.value = t('admin.invalidJson');
    return;
  }
  busy.value = true;
  try {
    const path = action.path(selectedRow.value, actionInputs.value);
    const mergedBody = typeof body === 'object' && body !== null && !Array.isArray(body)
      ? { ...body, ...actionInputs.value }
      : body;
    const result = action.method === 'GET'
      ? await coreAdminHttp.get<unknown>(path)
      : action.method === 'DELETE'
        ? await coreAdminHttp.delete<unknown>(path)
        : action.method === 'PATCH'
          ? await coreAdminHttp.patch<unknown>(path, mergedBody)
          : action.method === 'PUT'
            ? await coreAdminHttp.put<unknown>(path, mergedBody)
            : await coreAdminHttp.post<unknown>(path, mergedBody);
    actionOpen.value = false;
    response.value = result ?? null;
    responseOpen.value = true;
    toast.show(t('admin.actionDone'), 'success');
    if (action.method !== 'GET') {
      await load();
    }
  } catch (error) {
    actionError.value = resolveErrorMessage(error);
  } finally {
    busy.value = false;
  }
}

function nextPage(): void {
  if (offset.value + pageSize.value >= total.value) return;
  offset.value += pageSize.value;
  void load();
}

function previousPage(): void {
  offset.value = Math.max(0, offset.value - pageSize.value);
  void load();
}

function openRow(row: Record<string, unknown>): void {
  response.value = row;
  responseOpen.value = true;
}

onMounted(load);
watch(() => props.config.listPath, () => { offset.value = 0; void load(); });
</script>

<template>
  <section class="admin-resource">
    <header class="admin-resource__head">
      <div class="admin-resource__actions">
        <AppButton v-for="(action, index) in config.globalActions ?? []" :key="index" :variant="action.danger ? 'danger' : 'secondary'" @click="openAction(action, {})">{{ action.label }}</AppButton>
        <AppButton v-if="config.create" @click="openCreate">{{ $t('admin.create') }}</AppButton>
        <AppButton variant="secondary" :loading="loading || refreshing" @click="load">{{ $t('common.retry') }}</AppButton>
      </div>
    </header>

    <AppSkeleton v-if="loading && items.length === 0" variant="card" :lines="6" />
    <p v-if="errorMessage && items.length > 0" class="admin-resource__refresh-error" role="alert">{{ errorMessage }}</p>
    <AppErrorState v-else-if="errorMessage && items.length === 0" :message="errorMessage">
      <AppButton variant="secondary" @click="load">{{ $t('common.retry') }}</AppButton>
    </AppErrorState>

    <div v-else-if="items.length > 0" class="admin-resource__card">
      <div class="admin-resource__table-wrap">
        <table class="admin-resource__table" @mouseleave="hoveredColumn = null">
          <thead>
            <tr>
              <th v-for="(column, columnIndex) in columns" :key="column" :class="{ 'admin-resource__cell--column': hoveredColumn === columnIndex }" @mouseenter="hoveredColumn = columnIndex">{{ column }}</th>
              <th :class="{ 'admin-resource__cell--column': hoveredColumn === columns.length }" @mouseenter="hoveredColumn = columns.length">{{ $t('admin.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in items" :key="String(row[config.idField ?? 'id'] ?? index)">
              <td v-for="(column, columnIndex) in columns" :key="column" :title="cell(row[column])" :class="{ 'admin-resource__cell--column': hoveredColumn === columnIndex }" @mouseenter="hoveredColumn = columnIndex">{{ cell(row[column]) }}</td>
              <td :class="{ 'admin-resource__cell--column': hoveredColumn === columns.length }" @mouseenter="hoveredColumn = columns.length">
                <div class="admin-resource__row-actions">
                  <AppIconButton size="s" :label="$t('admin.details')" @click="openDetails(row)"><Info :size="18" aria-hidden="true" /></AppIconButton>
                  <div v-if="menuActions(row).length > 0" class="admin-resource__row-menu">
                    <AppDropdown
                      size="s"
                      :model-value="''"
                      :label="$t('admin.actions')"
                      :placeholder="$t('admin.actions')"
                      :options="rowActionOptions(row)"
                      @update:model-value="value => runRowAction(row, value)"
                    />
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="admin-resource__pager">
        <div class="admin-resource__pager-left">
          <div v-if="config.paginated !== false" class="admin-resource__page-size">
            <AppDropdown id="admin-page-size" size="s" :model-value="String(pageSize)" :label="$t('admin.pageSize')" :options="pageSizeOptions" @update:model-value="onPageSizeChange" />
          </div>
          <span>{{ $t('admin.range', { from: offset + 1, to: Math.min(offset + pageSize, total), total }) }}</span>
        </div>
        <div class="admin-resource__actions">
          <AppButton size="s" variant="secondary" :disabled="offset === 0" @click="previousPage">{{ $t('admin.previous') }}</AppButton>
          <AppButton size="s" variant="secondary" :disabled="offset + pageSize >= total" @click="nextPage">{{ $t('admin.next') }}</AppButton>
        </div>
      </div>
    </div>

    <AppEmptyState v-else :title="$t('admin.emptyTitle')" :description="$t('admin.emptyText')" />

    <AppJsonResponseDialog v-model="responseOpen" :title="$t('admin.response')" :data="response" />

    <AppDialog v-model="createOpen" :title="$t('admin.createTitle', { resource: config.title })" :persistent="busy">
      <div class="admin-resource__editor">
        <p class="admin-resource__subtitle">{{ $t('admin.jsonHint') }}</p>
        <textarea v-model="createBody" :aria-label="$t('admin.jsonHint')" class="admin-resource__textarea" spellcheck="false" rows="12" />
        <p v-if="createError" class="admin-resource__error" role="alert">{{ createError }}</p>
        <div class="admin-resource__actions">
          <AppButton variant="secondary" :disabled="busy" @click="createOpen = false">{{ $t('common.cancel') }}</AppButton>
          <AppButton :loading="busy" @click="submitCreate">{{ $t('admin.create') }}</AppButton>
        </div>
      </div>
    </AppDialog>

    <AppDialog v-model="actionOpen" :title="selectedAction?.label ?? $t('admin.actions')" :persistent="busy">
      <div v-if="selectedAction" class="admin-resource__editor">
        <AppInput
          v-for="input in selectedAction.inputs ?? []"
          :id="`admin-action-${input.name}`"
          :key="input.name"
          :model-value="actionInputs[input.name]"
          :label="input.label"
          :placeholder="input.placeholder"
          @update:model-value="(value) => actionInputs[input.name] = String(value)"
        />
        <p class="admin-resource__subtitle">{{ $t('admin.jsonHint') }}</p>
        <textarea v-model="actionBody" :aria-label="$t('admin.jsonHint')" class="admin-resource__textarea" spellcheck="false" rows="10" />
        <p v-if="actionError" class="admin-resource__error" role="alert">{{ actionError }}</p>
        <div class="admin-resource__actions">
          <AppButton variant="secondary" :disabled="busy" @click="actionOpen = false">{{ $t('common.cancel') }}</AppButton>
          <AppButton :variant="selectedAction.danger ? 'danger' : 'primary'" :loading="busy" @click="executeAction">{{ $t('admin.execute') }}</AppButton>
        </div>
      </div>
    </AppDialog>
  </section>
</template>

<style scoped>
.admin-resource { display: grid; grid-template-columns: minmax(0, 1fr); min-width: 0; gap: var(--space-16); }
.admin-resource__head { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-8); }
.admin-resource__title { font-size: var(--type-h2-size); line-height: var(--type-h2-line); font-weight: var(--type-h2-weight); color: var(--text-1); }
.admin-resource__subtitle { margin-top: var(--space-4); max-width: 760px; font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); }
.admin-resource__actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-start; min-width: 0; gap: var(--space-8); }
.admin-resource__refresh-error { padding: var(--space-8) var(--space-12); border-radius: var(--radius-control-s); background: var(--danger-soft); color: var(--danger-text); font-size: var(--type-body-small-size); }
.admin-resource__card { min-width: 0; overflow: visible; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card-m); box-shadow: var(--shadow-soft); }
.admin-resource__table-wrap { min-width: 0; max-width: 100%; overflow-x: auto; }
@media (min-width: 1200px) { .admin-resource__table-wrap { overflow: visible; } }
@media (max-width: 1199px) { .admin-resource__table { table-layout: auto; min-width: 900px; } }
.admin-resource__table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); }
.admin-resource__table th:last-child, .admin-resource__table td:last-child { width: 200px; }
.admin-resource__table thead th:first-child { border-top-left-radius: calc(var(--radius-card-m) - 1px); }
.admin-resource__table thead th:last-child { border-top-right-radius: calc(var(--radius-card-m) - 1px); }
.admin-resource__table thead th { position: sticky; top: var(--admin-topbar-height); z-index: 3; background: var(--card); box-shadow: inset 0 -1px 0 var(--border); }
.admin-resource__table th, .admin-resource__table td { max-width: 240px; padding: var(--space-8) var(--space-12); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: var(--theme-transition); }
.admin-resource__table tbody tr:hover td:not(.admin-resource__cell--column) { background: var(--table-row-hover); }
.admin-resource__table td.admin-resource__cell--column { background: var(--table-column-hover); }
.admin-resource__table tbody tr:hover td.admin-resource__cell--column { background: var(--table-cell-hover); }
.admin-resource__table thead th.admin-resource__cell--column { background: var(--table-head-column-hover); }
.admin-resource__table th { color: var(--text-3); font-weight: var(--type-body-small-emphasis-weight); text-transform: uppercase; letter-spacing: .03em; }
.admin-resource__table td { color: var(--text-1); }
.admin-resource__row-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-8); }
.admin-resource__row-menu { min-width: 120px; }
.admin-resource__link { padding: 0; background: none; color: var(--accent); font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); }
.admin-resource__link:hover { text-decoration: underline; }
.admin-resource__link--danger { color: var(--danger); }
.admin-resource__pager { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--space-8); padding: var(--space-8) var(--space-12); border-top: 1px solid var(--border); color: var(--text-2); font-size: var(--type-body-small-size); }
.admin-resource__pager-left { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-12); }
.admin-resource__page-size { width: 96px; flex: 0 0 auto; }
.admin-resource__editor { display: grid; gap: var(--space-12); }
.admin-resource__textarea { width: 100%; min-height: 180px; padding: var(--space-12); border: 1px solid var(--border); border-radius: var(--radius-control-m); background: var(--surface-2); color: var(--text-1); font-family: var(--font-mono); font-size: var(--type-body-small-size); line-height: 1.5; resize: vertical; }
.admin-resource__error { color: var(--danger); font-size: var(--type-body-small-size); }
</style>
