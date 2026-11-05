<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import OperationDialog from './OperationDialog.vue';
import RecordView from './RecordView.vue';
import { rowsFrom, serialize, type Operation, type FormData, type Field } from './form';
import TypedFields from './TypedFields.vue';
import { coreOperations, type Resource } from './catalog';
const props = defineProps<{ config: Resource }>();
const auth = useAdminAuthStore();
const { t, te } = useI18n();
const items = ref<Record<string, unknown>[]>([]);
const total = ref(0);
const offset = ref(0);
const pageSize = ref('25');
const query = ref('');
const auditFilters = ref<FormData>({ action: '', success: '', actorAccountId: '' });
const appliedFilters = ref('');
const filterError = ref('');
const auditFields: Field[] = [
  { name: 'action', optional: true, max: 180 },
  { name: 'success', kind: 'select', options: ['true', 'false'], optional: true, initial: '' },
  { name: 'actorAccountId', kind: 'entity', source: 'accounts', rule: 'uuid', optional: true },
];
function applyFilters(): void {
  try {
    const values = serialize(auditFields, auditFilters.value);
    appliedFilters.value = new URLSearchParams(
      Object.entries(values).map(([key, value]) => [key, String(value)]),
    ).toString();
    offset.value = 0;
    filterError.value = '';
    void load();
  } catch {
    filterError.value = t('workbench.invalidField');
  }
}
const error = ref('');
const loading = ref(true);
const refreshing = ref(false);
const selected = ref<Operation | null>(null);
const target = ref<Record<string, unknown>>({});
const open = ref(false);
const detail = ref<unknown>(null);
const detailOpen = ref(false);
const epoch = computed(() => coreAdminSession.epoch());
let generation = 0;
let controller: AbortController | undefined;
const canRead = computed(() =>
  props.config.key === 'audit'
    ? auth.roles?.includes('core.superadmin')
    : auth.can(props.config.permission),
);
const shown = computed(() =>
  items.value.filter(
    (row) =>
      !query.value ||
      props.config.columns.some((c) =>
        String(row[c] ?? '')
          .toLocaleLowerCase()
          .includes(query.value.toLocaleLowerCase()),
      ),
  ),
);
function operation(key: string): Operation | undefined {
  const found = coreOperations.find((o) => o.key === key);
  return found && (!found.permission || auth.can(found.permission)) ? found : undefined;
}
const globals = computed(() =>
  (props.config.global ?? []).flatMap((key) => {
    const op = operation(key);
    return op ? [op] : [];
  }),
);
const create = computed(() => (props.config.create ? operation(props.config.create) : undefined));
function actions(row: Record<string, unknown>): Operation[] {
  return props.config.actions.flatMap((key) => {
    const op = operation(key);
    return op && !op.blocked?.(row) ? [op] : [];
  });
}
function label(column: string): string {
  return te('workbench.fields.' + column) ? t('workbench.fields.' + column) : column;
}
function cell(value: unknown): string {
  return value === null || value === undefined
    ? '—'
    : typeof value === 'boolean'
      ? t(value ? 'configuration.yes' : 'configuration.no')
      : typeof value === 'object'
        ? t('workbench.open')
        : String(value);
}
async function load(): Promise<void> {
  controller?.abort();
  const version = ++generation;
  const requestEpoch = epoch.value;
  controller = new AbortController();
  error.value = '';
  if (!canRead.value) {
    items.value = [];
    loading.value = false;
    return;
  }
  loading.value = items.value.length === 0;
  refreshing.value = !loading.value;
  const timer = setTimeout(() => controller?.abort(), 10000);
  try {
    let path =
      props.config.path +
      (props.config.paginated ? `?limit=${pageSize.value}&offset=${offset.value}` : '');
    if (props.config.key === 'audit' && appliedFilters.value) path += '&' + appliedFilters.value;
    const result = rowsFrom(await coreAdminHttp.get<unknown>(path, controller.signal));
    if (version !== generation || requestEpoch !== coreAdminSession.epoch()) return;
    items.value = result.items;
    total.value = result.total;
  } catch (e) {
    if (version !== generation) return;
    error.value = resolveErrorMessage(e);
    if (e instanceof ApiError && [401, 403].includes(e.status)) {
      items.value = [];
      total.value = 0;
      offset.value = 0;
      open.value = false;
      detailOpen.value = false;
      detail.value = null;
    }
  } finally {
    clearTimeout(timer);
    if (version === generation) {
      loading.value = false;
      refreshing.value = false;
    }
  }
}
function start(op: Operation, row: Record<string, unknown> = {}): void {
  if (error.value || refreshing.value) return;
  selected.value = op;
  target.value = row;
  open.value = true;
}
function choose(row: Record<string, unknown>, key: string | string[]): void {
  const op = actions(row).find((o) => o.key === key);
  if (op) start(op, row);
}
function page(delta: number): void {
  offset.value = Math.max(0, offset.value + delta * Number(pageSize.value));
  void load();
}
watch(
  () => props.config.path,
  () => {
    items.value = [];
    offset.value = 0;
    query.value = '';
    appliedFilters.value = '';
    auditFilters.value = { action: '', success: '', actorAccountId: '' };
    open.value = false;
    detailOpen.value = false;
    detail.value = null;
    void load();
  },
  { immediate: true },
);
watch(epoch, () => {
  generation++;
  controller?.abort();
  items.value = [];
  target.value = {};
  open.value = false;
  detailOpen.value = false;
  detail.value = null;
});
watch(pageSize, () => {
  offset.value = 0;
  void load();
});
watch(detailOpen, (value) => {
  if (!value) detail.value = null;
});
onBeforeUnmount(() => {
  generation++;
  controller?.abort();
  items.value = [];
  detail.value = null;
});
</script>
<template>
  <section class="admin-resource">
    <header class="admin-resource__head">
      <div class="admin-resource__actions">
        <AppButton
          v-for="op in globals"
          :key="op.key"
          :variant="op.danger ? 'danger' : 'secondary'"
          :disabled="!!error || refreshing"
          @click="start(op)"
        >
          {{ t(op.label) }}
        </AppButton>
        <AppButton
          v-if="create"
          :disabled="!!error || refreshing"
          @click="start(create)"
        >
          {{ t('admin.create') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="refreshing"
          @click="load"
        >
          {{ t('configuration.refresh') }}
        </AppButton>
      </div>
    </header>
    <form
      v-if="config.key === 'audit'"
      class="admin-resource__filters"
      @submit.prevent="applyFilters"
    >
      <TypedFields
        v-model="auditFilters"
        :fields="auditFields"
        :disabled="loading || refreshing"
      />
      <p
        v-if="filterError"
        role="alert"
      >
        {{ filterError }}
      </p>
      <AppButton
        type="submit"
        :disabled="loading || refreshing"
      >
        {{ t('workbench.applyFilters') }}
      </AppButton>
    </form>
    <AppInput
      v-else
      id="wb-search"
      v-model="query"
      :label="t('workbench.searchPage')"
      size="m"
    />
    <p
      v-if="!canRead"
      role="status"
    >
      {{ t('workbench.noAccess') }}
    </p>
    <AppErrorState
      v-else-if="error"
      :message="error"
      @retry="load"
    />
    <AppSkeleton v-if="loading" />
    <template v-else-if="canRead && (!error || items.length > 0)">
      <div class="admin-resource__table-wrap">
        <table v-if="shown.length">
          <thead>
            <tr>
              <th
                v-for="column in config.columns"
                :key="column"
              >
                {{ label(column) }}
              </th>
              <th>{{ t('admin.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in shown"
              :key="String(row.id ?? index)"
            >
              <td
                v-for="column in config.columns"
                :key="column"
              >
                {{ cell(row[column]) }}
              </td>
              <td>
                <div class="admin-resource__row-actions">
                  <AppButton
                    size="s"
                    variant="secondary"
                    @click="
                      detail = row;
                      detailOpen = true;
                    "
                  >
                    {{ t('admin.details') }}
                  </AppButton>
                  <AppDropdown
                    v-if="actions(row).length"
                    :model-value="''"
                    :label="t('admin.actions')"
                    :placeholder="t('admin.actions')"
                    size="s"
                    :options="actions(row).map((o) => ({ value: o.key, label: t(o.label) }))"
                    :disabled="!!error || refreshing"
                    @update:model-value="choose(row, $event)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <p
          v-else
          class="admin-resource__empty"
        >
          {{ t('workbench.noRecords') }}
        </p>
      </div>
      <div class="admin-resource__pager">
        <AppDropdown
          v-if="config.paginated"
          v-model="pageSize"
          :label="t('admin.pageSize')"
          :options="['10', '25', '50', '100'].map((value) => ({ value, label: value }))"
          size="s"
        />
        <span>
          {{
            t('admin.range', {
              from: total ? offset + 1 : 0,
              to: Math.min(offset + Number(pageSize), total),
              total,
            })
          }}
        </span>
        <AppButton
          v-if="config.paginated"
          size="s"
          variant="secondary"
          :disabled="offset === 0 || refreshing"
          @click="page(-1)"
        >
          {{ t('admin.previous') }}
        </AppButton>
        <AppButton
          v-if="config.paginated"
          size="s"
          variant="secondary"
          :disabled="offset + Number(pageSize) >= total || refreshing"
          @click="page(1)"
        >
          {{ t('admin.next') }}
        </AppButton>
      </div>
    </template>
    <OperationDialog
      v-model="open"
      :operation="selected"
      :row="target"
      :session-key="epoch"
      @done="load"
    />
    <AppDialog
      v-model="detailOpen"
      :title="t('admin.details')"
      size="full"
    >
      <RecordView :data="detail" />
    </AppDialog>
  </section>
</template>
<style scoped>
.admin-resource {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
}
.admin-resource__head {
  display: flex;
  justify-content: end;
  min-width: 0;
}
.admin-resource__actions,
.admin-resource__row-actions,
.admin-resource__pager {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  flex-wrap: wrap;
}
.admin-resource__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--surface);
}
table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
td,
th {
  padding: var(--space-12);
  border-bottom: 1px solid var(--border);
  overflow-wrap: anywhere;
  max-width: 280px;
  min-width: 100px;
}
th {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
}
.admin-resource__row-actions :deep(.app-dropdown) {
  min-width: 150px;
}
.admin-resource__pager {
  justify-content: end;
}
.admin-resource__pager :deep(.app-dropdown) {
  width: calc(var(--space-24) * 4);
  flex: 0 0 calc(var(--space-24) * 4);
}
.admin-resource__empty {
  padding: var(--space-20);
}
.admin-resource__filters {
  display: grid;
  gap: var(--space-12);
}
.admin-resource__filters :deep(.wb-fields) {
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
}
</style>
