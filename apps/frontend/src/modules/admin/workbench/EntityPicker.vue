<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { coreAdminSession } from '@/core/admin/session';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { rowsFrom, type Field } from './form';
const props = defineProps<{ field: Field; path?: string; disabled?: boolean }>();
const model = defineModel<string | string[]>({ required: true });
const { t } = useI18n();
const auth = useAdminAuthStore();
const entries = ref<Record<string, unknown>[]>([]);
const error = ref('');
const loading = ref(false);
const total = ref(0);
const manualValue = ref('');
function addManual(): void {
  const value = manualValue.value.trim();
  const values = Array.isArray(model.value) ? model.value : [];
  if (value && !values.includes(value)) {
    model.value = [...values, value];
    manualValue.value = '';
  }
}
let generation = 0;
let controller: AbortController | undefined;
const permissions: Record<string, string> = {
  persons: 'core.persons.read',
  accounts: 'core.accounts.read',
  roles: 'core.rbac.read',
  permissions: 'core.rbac.read',
};
const canLoad = computed(() =>
  props.field.permission
    ? auth.can(props.field.permission)
    : props.field.source
      ? auth.can(permissions[props.field.source]!)
      : true,
);
const options = computed(() =>
  entries.value.map((row) => ({
    value: String(
      props.field.source === 'permissions'
        ? row.code
        : props.field.name === 'personId' && row.personId
          ? row.personId
          : row.id,
    ),
    label: String(
      row.displayName || row.name || row.label || row.code || row.contactType || row.id,
    ),
    description: typeof row.id === 'string' ? row.id : undefined,
  })),
);
async function load(reset = false): Promise<void> {
  if (!canLoad.value || loading.value) return;
  const version = ++generation;
  const epoch = coreAdminSession.epoch();
  controller?.abort();
  controller = new AbortController();
  loading.value = true;
  error.value = '';
  const path = props.path ?? '/core/' + props.field.source;
  const paged =
    ['persons', 'accounts', 'roles', 'permissions'].includes(props.field.source ?? '') ||
    path.includes('/privacy/work-items/');
  const offset = reset ? 0 : entries.value.length;
  const timeout = setTimeout(() => controller?.abort(), 10000);
  try {
    const data = await coreAdminHttp.get<unknown>(
      path + (paged ? `?limit=100&offset=${offset}` : ''),
      controller.signal,
    );
    if (version !== generation || epoch !== coreAdminSession.epoch()) return;
    const result = rowsFrom(data);
    entries.value = reset ? result.items : [...entries.value, ...result.items];
    total.value = result.total;
  } catch (e) {
    if (version === generation) {
      entries.value = [];
      error.value = resolveErrorMessage(e);
    }
  } finally {
    clearTimeout(timeout);
    if (version === generation) loading.value = false;
  }
}
watch(
  () => [props.path, props.field.source],
  () => {
    generation++;
    controller?.abort();
    loading.value = false;
    entries.value = [];
    void load(true);
  },
  { immediate: true },
);
onBeforeUnmount(() => {
  generation++;
  controller?.abort();
  entries.value = [];
});
</script>
<template>
  <div class="wb-picker">
    <AppDropdown
      v-if="canLoad"
      :model-value="model"
      @update:model-value="model = $event"
      :label="t('workbench.fields.' + field.name)"
      :options="options"
      :multiple="field.kind === 'multi'"
      :searchable="true"
      :clearable="field.optional"
      :disabled="disabled || loading"
    />
    <div
      v-else-if="field.kind === 'multi'"
      class="wb-picker"
    >
      <AppInput
        :id="'wb-manual-' + field.name"
        v-model="manualValue"
        :label="t('workbench.fields.' + field.name)"
        :disabled="disabled"
        @keydown.enter.prevent="addManual"
      />
      <p>{{ t('workbench.manualValues') }}</p>
      <AppButton
        type="button"
        variant="secondary"
        :disabled="disabled"
        @click="addManual"
      >
        {{ t('workbench.add') }}
      </AppButton>
      <ul>
        <li
          v-for="value in Array.isArray(model) ? model : []"
          :key="value"
        >
          <span>{{ value }}</span>
          <AppButton
            type="button"
            size="s"
            variant="secondary"
            :disabled="disabled"
            @click="model = (Array.isArray(model) ? model : []).filter((v) => v !== value)"
          >
            {{ t('workbench.removeValue', { value }) }}
          </AppButton>
        </li>
      </ul>
    </div>
    <AppInput
      v-else
      :id="'wb-manual-' + field.name"
      :model-value="String(model)"
      @update:model-value="model = String($event)"
      :label="t('workbench.fields.' + field.name)"
      :hint="t('workbench.manualId')"
      :disabled="disabled"
    />
    <p
      v-if="error"
      role="alert"
    >
      {{ error }}
    </p>
    <AppButton
      v-if="error || (canLoad && entries.length < total)"
      size="s"
      variant="secondary"
      :loading="loading"
      :disabled="disabled"
      @click="load(!!error)"
    >
      {{ t(error ? 'common.retry' : 'workbench.loadMore') }}
    </AppButton>
    <p
      v-if="canLoad && entries.length"
      class="wb-picker__hint"
    >
      {{ t('workbench.loaded', { count: entries.length, total }) }}
    </p>
  </div>
</template>
<style scoped>
.wb-picker {
  display: grid;
  gap: var(--space-8);
  min-width: 0;
}
.wb-picker__hint {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
}
</style>
