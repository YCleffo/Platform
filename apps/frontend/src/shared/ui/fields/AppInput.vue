<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useAttrs, watchEffect } from 'vue';
import AppTemporalPicker from './AppTemporalPicker.vue';
import type { TemporalMode } from '@/shared/formatting/temporal';

defineOptions({ inheritAttrs: false });

interface Props {
  id: string;
  label?: string;
  type?: string;
  placeholder?: string;
  autocomplete?: string;
  inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel';
  hint?: string;
  error?: string;
  required?: boolean;
  disabled?: boolean;
  size?: 's' | 'm' | 'l';
}

const props = withDefaults(defineProps<Props>(), {
  label: undefined,
  type: 'text',
  placeholder: undefined,
  autocomplete: undefined,
  inputmode: undefined,
  hint: undefined,
  error: undefined,
  required: false,
  disabled: false,
  size: 'l',
});

const model = defineModel<string | number>({ default: '' });
const attrs = useAttrs();
const input = ref<HTMLInputElement | null>(null);
const picker = ref<InstanceType<typeof AppTemporalPicker> | null>(null);
const focused = ref(false);
const editText = ref('');
const temporal = computed<TemporalMode | null>(() =>
  ['date', 'time', 'datetime-local'].includes(props.type) ? (props.type as TemporalMode) : null,
);
const fieldError = computed(
  () => props.error || (!focused.value ? picker.value?.invalid : undefined),
);
const inputModel = computed<string | number>({
  get: () =>
    temporal.value
      ? focused.value
        ? editText.value
        : (picker.value?.formatted ?? model.value)
      : model.value,
  set: (value) => {
    editText.value = String(value);
    model.value = temporal.value ? (picker.value?.parse(String(value)) ?? value) : value;
  },
});
watchEffect(() => {
  if (temporal.value) input.value?.setCustomValidity(picker.value?.invalid ?? '');
});
let form: HTMLFormElement | null = null;
let resetTimer: ReturnType<typeof setTimeout> | undefined;

const ariaDescribedBy = computed(() => {
  const ids: string[] = [];
  if (fieldError.value) {
    ids.push(`${props.id}-error`);
  } else if (props.hint !== undefined) {
    ids.push(`${props.id}-hint`);
  }
  if (typeof attrs['aria-describedby'] === 'string') {
    ids.push(attrs['aria-describedby']);
  }
  return ids.length > 0 ? ids.join(' ') : undefined;
});

function focusInput(): void {
  if (!props.disabled) input.value?.focus();
}

function nativeAttrs(): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(attrs).filter(([key]) => !['class', 'style'].includes(key)),
  );
}

function onReset(): void {
  clearTimeout(resetTimer);
  resetTimer = setTimeout(() => {
    if (input.value !== null) {
      model.value = temporal.value
        ? (picker.value?.parse(input.value.value) ?? input.value.value)
        : input.value.value;
    }
  }, 0);
}

onMounted(() => {
  form = input.value?.form ?? null;
  form?.addEventListener('reset', onReset);
});

onBeforeUnmount(() => {
  clearTimeout(resetTimer);
  form?.removeEventListener('reset', onReset);
  form = null;
});
</script>

<template>
  <div
    class="app-input"
    :class="$attrs.class"
    :style="$attrs.style as import('vue').StyleValue"
  >
    <label
      class="app-input__label"
      :for="id"
    >
      {{ label }}
      <span
        v-if="required"
        class="app-input__required"
        aria-hidden="true"
      >
        *
      </span>
    </label>
    <div
      class="app-input__control"
      @click="focusInput"
      :class="[
        `app-input__control--${size}`,
        { 'app-input__control--error': fieldError, 'app-input__control--disabled': disabled },
      ]"
    >
      <span
        v-if="$slots.leading"
        class="app-input__affix"
      >
        <slot name="leading" />
      </span>
      <input
        ref="input"
        v-bind="nativeAttrs()"
        :id="id"
        v-model="inputModel"
        class="app-input__native"
        :type="temporal ? 'text' : type"
        :placeholder="placeholder ?? picker?.placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :required="required"
        :disabled="disabled"
        :aria-invalid="fieldError ? 'true' : 'false'"
        :aria-describedby="ariaDescribedBy"
        @focus="
          editText = picker?.formatted ?? String(model);
          focused = true;
        "
        @blur="focused = false"
        @keydown.alt.down.prevent="picker?.show()"
      />
      <AppTemporalPicker
        v-if="temporal"
        ref="picker"
        v-model="model"
        :input="input"
        :mode="temporal"
        :disabled="disabled"
        :readonly="attrs.readonly !== undefined && attrs.readonly !== false"
        :min="typeof attrs.min === 'string' ? attrs.min : undefined"
        :max="typeof attrs.max === 'string' ? attrs.max : undefined"
        :step="attrs.step === undefined ? undefined : Number(attrs.step)"
      />
      <span
        v-if="$slots.trailing"
        class="app-input__affix app-input__affix--trailing"
      >
        <slot name="trailing" />
      </span>
    </div>
    <p
      v-if="fieldError"
      :id="`${id}-error`"
      class="app-input__message app-input__message--error"
    >
      {{ fieldError }}
    </p>
    <p
      v-else-if="hint"
      :id="`${id}-hint`"
      class="app-input__message"
    >
      {{ hint }}
    </p>
  </div>
</template>

<style scoped>
.app-input {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-6);
  width: 100%;
}

.app-input__label {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.app-input__required {
  color: var(--danger);
  margin-left: 2px;
  transition: var(--theme-transition);
}

.app-input__control {
  display: flex;
  cursor: text;
  align-items: center;
  gap: var(--gap-s);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  font-family: var(--type-body-large-family), var(--font-sans);
  font-size: var(--type-body-large-size);
  letter-spacing: var(--type-body-large-tracking);
  line-height: var(--type-body-large-line);
  font-weight: var(--type-body-large-weight);
  transition: var(--theme-transition);
}

.app-input__control--s {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  height: var(--control-h-s);
  box-sizing: border-box;
  min-height: var(--control-h-s);
  padding: var(--pad-input-s-vertical) var(--pad-input-s-horizontal);
}

.app-input__control--m {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  height: var(--button-height-m);
  box-sizing: border-box;
  min-height: var(--button-height-m);
  padding: var(--pad-input-m-vertical) var(--pad-input-m-horizontal);
}

.app-input__control--l {
  height: var(--control-h-l);
  box-sizing: border-box;
  min-height: var(--control-h-l);
  padding: var(--pad-input-l-vertical) var(--pad-input-l-horizontal);
}

.app-input__control:focus-within {
  border-color: var(--focus-border);
  box-shadow: none;
  transition: var(--theme-transition);
}

.app-input__control--error {
  border-color: var(--danger);
  transition: var(--theme-transition);
}

.app-input__control--disabled {
  background: var(--disabled-surface);
  border-color: var(--disabled-border);
  transition: var(--theme-transition);
}

.app-input__native {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font: inherit;
  color: var(--text-1);
  outline: none;
  transition: var(--theme-transition);
}

.app-input__native::placeholder {
  color: var(--text-3);
  transition: var(--theme-transition);
}

.app-input__native:focus-visible {
  outline: none;
  box-shadow: none;
}
/* Style committed autofill values; Chromium owns the font of its suggestion preview. */
.app-input__native:is(:autofill, :-webkit-autofill) {
  -webkit-box-shadow: 0 0 0 1000px var(--surface) inset !important;
  -webkit-text-fill-color: var(--text-1);
  caret-color: var(--text-1);
  font: inherit !important;
}
.app-input__native:disabled {
  color: var(--disabled-text);
  cursor: not-allowed;
  transition: var(--theme-transition);
}

.app-input__affix {
  display: inline-flex;
  align-items: center;
  color: var(--text-3);
  transition: var(--theme-transition);
}

.app-input__message {
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.app-input__message--error {
  color: var(--danger-text);
  transition: var(--theme-transition);
}
</style>
