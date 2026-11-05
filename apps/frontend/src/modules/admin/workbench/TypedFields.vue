<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Field, FormData, FormValue } from './form';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import EntityPicker from './EntityPicker.vue';
const props = defineProps<{
  fields: Field[];
  row?: Record<string, unknown>;
  disabled?: boolean;
  errorField?: string;
}>();
const data = defineModel<FormData>({ required: true });
const { t, te } = useI18n();
const uid = useId();
const pending = ref<Record<string, string>>({});
const visible = computed(() => props.fields.filter((f) => !f.when || f.when(data.value)));
function set(name: string, value: FormValue): void {
  data.value = { ...data.value, [name]: value };
}
function label(key: string): string {
  return te('workbench.options.' + key) ? t('workbench.options.' + key) : key;
}
function path(field: Field): string | undefined {
  return field.path?.replace(/\{(\w+)\}/g, (_, key: string) =>
    encodeURIComponent(String(props.row?.[key] ?? data.value[key] ?? '')),
  );
}
function selection(name: string): string | string[] {
  const value = data.value[name];
  return Array.isArray(value) ? value : String(value ?? '');
}
function inputValue(name: string): string | number {
  const value = data.value[name];
  return typeof value === 'number' ? value : String(value ?? '');
}
function list(name: string): string[] {
  return Array.isArray(data.value[name]) ? (data.value[name] as string[]) : [];
}
function add(field: Field): void {
  const value = pending.value[field.name]?.trim();
  if (!value || list(field.name).includes(value) || list(field.name).length >= (field.max ?? 32))
    return;
  set(field.name, [...list(field.name), value]);
  pending.value[field.name] = '';
}
</script>
<template>
  <div class="wb-fields">
    <div
      v-for="field in visible"
      :key="field.name"
      class="wb-fields__field"
      :data-field="field.name"
    >
      <EntityPicker
        v-if="field.kind === 'entity' || field.source"
        :field="field"
        :path="path(field)"
        :disabled="disabled"
        :model-value="selection(field.name)"
        @update:model-value="set(field.name, $event)"
      />
      <AppSwitch
        v-else-if="field.kind === 'boolean'"
        :id="uid + field.name"
        :label="t('workbench.fields.' + field.name)"
        :disabled="disabled"
        :model-value="data[field.name] === true"
        @update:model-value="set(field.name, $event)"
      />
      <AppDropdown
        v-else-if="field.kind === 'select' || field.kind === 'multi'"
        :model-value="selection(field.name)"
        @update:model-value="set(field.name, $event)"
        :label="t('workbench.fields.' + field.name)"
        :multiple="field.kind === 'multi'"
        :clearable="field.optional"
        :disabled="disabled"
        :options="(field.options ?? []).map((v) => ({ value: v, label: label(v) }))"
      />
      <fieldset
        v-else-if="field.kind === 'codes'"
        class="wb-fields__tokens"
        :disabled="disabled"
      >
        <legend>{{ t('workbench.fields.' + field.name) }}</legend>
        <div class="wb-fields__token-entry">
          <AppInput
            :id="uid + field.name"
            v-model="pending[field.name]"
            :label="t('workbench.addValue')"
            @keydown.enter.prevent="add(field)"
          />
          <AppButton
            type="button"
            variant="secondary"
            @click="add(field)"
          >
            {{ t('workbench.add') }}
          </AppButton>
        </div>
        <ul class="wb-fields__chips">
          <li
            v-for="value in list(field.name)"
            :key="value"
          >
            <span>{{ value }}</span>
            <button
              type="button"
              :aria-label="t('workbench.removeValue', { value })"
              @click="
                set(
                  field.name,
                  list(field.name).filter((v) => v !== value),
                )
              "
            >
              ×
            </button>
          </li>
        </ul>
      </fieldset>
      <AppInput
        v-else
        :id="uid + field.name"
        :label="t('workbench.fields.' + field.name)"
        :type="field.kind === 'datetime' ? 'datetime-local' : (field.kind ?? 'text')"
        :required="!field.optional"
        :disabled="disabled"
        :model-value="inputValue(field.name)"
        @update:model-value="set(field.name, $event)"
        :maxlength="field.max"
        :readonly="field.readOnly"
        :min="field.min"
        :max="field.kind === 'number' ? field.max : undefined"
        :hint="field.optional ? t('workbench.optional') : undefined"
      />
      <p
        v-if="errorField === field.name"
        class="wb-fields__error"
        role="alert"
      >
        {{ t('workbench.invalidField') }}
      </p>
    </div>
  </div>
</template>
<style scoped>
.wb-fields {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
}
.wb-fields__field {
  min-width: 0;
}
.wb-fields__tokens {
  margin: 0;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  min-width: 0;
}
.wb-fields__token-entry {
  display: flex;
  gap: var(--space-8);
  align-items: end;
}
.wb-fields__token-entry :deep(.app-input) {
  flex: 1;
  min-width: 0;
}
.wb-fields__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-8);
}
.wb-fields__chips li {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-4) var(--space-8);
  border-radius: var(--radius-control-s);
  background: var(--accent-soft);
  overflow-wrap: anywhere;
  max-width: 100%;
}
.wb-fields__chips button {
  flex: none;
  min-width: 32px;
  min-height: 32px;
  color: var(--accent-text);
}
.wb-fields__error {
  color: var(--danger);
  margin-top: var(--space-4);
}
</style>
