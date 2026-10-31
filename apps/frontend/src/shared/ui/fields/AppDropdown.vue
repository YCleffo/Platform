<script setup lang="ts" generic="Value extends string = string">
import { computed, ref, useId, watch } from 'vue';
import { ChevronDown, X } from '@/shared/ui/icons';
import AppMenu from '../menus/AppMenu.vue';
import type { DropdownOption, MenuItem } from '../menus/types';

defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{
  options: readonly DropdownOption<Value>[];
  label: string;
  id?: string;
  placeholder?: string;
  disabled?: boolean;
  searchable?: boolean;
  multiple?: boolean;
  clearable?: boolean;
  invalid?: boolean;
}>(), { id: undefined, placeholder: undefined, disabled: false, searchable: false, multiple: false, clearable: false, invalid: false });
const model = defineModel<Value | Value[]>({ required: true });
const uid = useId();
const fieldId = computed(() => props.id ?? `dropdown-${uid}`);
const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const initialFocus = ref<'first' | 'last'>('first');
const selected = computed(() => Array.isArray(model.value) ? model.value : model.value ? [model.value] : []);
function convert(options: readonly DropdownOption<Value>[], disabled = false): MenuItem[] {
  return options.map(option => ({ id: option.value, label: option.label, description: option.description, icon: option.icon,
    disabled: disabled || option.disabled, children: option.children?.length ? convert(option.children, disabled || option.disabled === true) : undefined,
    checkType: option.children?.length ? undefined : props.multiple ? 'checkbox' : 'radio', checked: selected.value.includes(option.value) }));
}
const items = computed(() => convert(props.options));
function flatten(options: readonly DropdownOption<Value>[]): DropdownOption<Value>[] {
  return options.flatMap(option => option.children?.length ? flatten(option.children) : [option]);
}
const text = computed(() => flatten(props.options).filter(option => selected.value.includes(option.value)).map(option => option.label).join(', '));
function choose(item: MenuItem): void {
  const value = flatten(props.options).find(option => option.value === item.id)?.value;
  if (value === undefined) return;
  model.value = props.multiple ? selected.value.includes(value) ? selected.value.filter(item => item !== value) : [...selected.value, value] : value;
}
function keydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault(); initialFocus.value = event.key === 'ArrowUp' ? 'last' : 'first'; open.value = true;
  }
}
watch(() => props.disabled, value => { if (value) open.value = false; });
</script>

<template>
  <div class="app-dropdown" :class="{ 'app-dropdown--invalid': invalid }">
    <span :id="`${fieldId}-label`" class="visually-hidden">{{ label }}</span>
    <button :id="fieldId" ref="trigger" v-bind="$attrs" type="button" class="app-dropdown__trigger" :disabled="disabled"
      aria-haspopup="menu" :aria-expanded="open" :aria-controls="open ? `${fieldId}-menu` : undefined"
      :aria-labelledby="`${fieldId}-label ${fieldId}-value`" :aria-invalid="invalid || undefined"
      @click="initialFocus = 'first'; open = !open" @keydown="keydown">
      <span :id="`${fieldId}-value`" class="app-dropdown__value" :class="{ 'app-dropdown__value--empty': !text }">{{ text || placeholder || $t('menus.choose') }}</span>
      <ChevronDown :size="18" aria-hidden="true" />
    </button>
    <button v-if="clearable && selected.length" type="button" class="app-dropdown__clear" :disabled="disabled" :aria-label="$t('menus.clearSelection')" @click="model = (multiple ? [] : '') as Value | Value[]"><X :size="16" aria-hidden="true" /></button>
    <AppMenu :id="`${fieldId}-menu`" v-model="open" :anchor="trigger" :items="items" :label="label" :searchable="searchable" :close-on-select="!multiple" :initial-focus="initialFocus" @select="choose">
      <template #footer><slot name="footer" /></template>
    </AppMenu>
  </div>
</template>

<style scoped>
.app-dropdown { display: flex; align-items: stretch; gap: var(--space-4); width: 100%; min-width: 0; }
.app-dropdown__trigger { display: flex; align-items: center; justify-content: space-between; gap: var(--gap-s); width: 100%; min-width: 0; min-height: var(--button-height-m); padding: var(--space-8) var(--space-12); border: 1px solid var(--border-strong); border-radius: var(--radius-control-m); background: var(--surface); color: var(--text-1); font: inherit; font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); text-align: left; cursor: pointer; transition: var(--theme-transition); }
.app-dropdown__trigger:focus-visible, .app-dropdown__trigger[aria-expanded="true"] { border-color: var(--focus-border); box-shadow: var(--focus-ring); outline: none; transition: var(--theme-transition); }
.app-dropdown--invalid .app-dropdown__trigger { border-color: var(--danger); transition: var(--theme-transition); }
.app-dropdown__value { min-width: 0; overflow-wrap: anywhere; }
.app-dropdown__value--empty { color: var(--text-3); transition: var(--theme-transition); }
.app-dropdown__trigger svg { flex: 0 0 auto; }
.app-dropdown__trigger:disabled, .app-dropdown__clear:disabled { cursor: not-allowed; opacity: .5; }
.app-dropdown__clear { display: flex; align-items: center; justify-content: center; flex: 0 0 var(--button-height-m); border: 1px solid var(--border); border-radius: var(--radius-control-m); color: var(--text-2); background: var(--surface); cursor: pointer; transition: var(--theme-transition); }
</style>
