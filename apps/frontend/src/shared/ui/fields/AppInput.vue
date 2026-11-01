<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

interface Props {
  id: string;
  label?: string;
  type?: string;
  placeholder?: string;
  autocomplete?: string;
  inputmode?: 'text' | 'numeric' | 'email' | 'tel';
  hint?: string;
  error?: string;
  required?: boolean;
  disabled?: boolean;
  size?: 'm' | 'l';
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
let form: HTMLFormElement | null = null;

const ariaDescribedBy = computed(() => {
  const ids: string[] = [];
  if (props.error !== undefined) {
    ids.push(`${props.id}-error`);
  } else if (props.hint !== undefined) {
    ids.push(`${props.id}-hint`);
  }
  if (typeof attrs['aria-describedby'] === 'string') {
    ids.push(attrs['aria-describedby']);
  }
  return ids.length > 0 ? ids.join(' ') : undefined;
});

function onReset(): void {
  if (input.value !== null) {
    model.value = input.value.defaultValue;
  }
}

onMounted(() => {
  form = input.value?.form ?? null;
  form?.addEventListener('reset', onReset);
});

onBeforeUnmount(() => {
  form?.removeEventListener('reset', onReset);
  form = null;
});
</script>

<template>
  <div class="app-input">
    <label class="app-input__label" :for="id">
      {{ label }}<span v-if="required" class="app-input__required" aria-hidden="true">*</span>
    </label>
    <div
      class="app-input__control"
      :class="[
        `app-input__control--${size}`,
        { 'app-input__control--error': error, 'app-input__control--disabled': disabled },
      ]"
    >
      <span v-if="$slots.leading" class="app-input__affix"><slot name="leading" /></span>
      <input
        ref="input"
        v-bind="$attrs"
        :id="id"
        v-model="model"
        class="app-input__native"
        :type="type"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :required="required"
        :disabled="disabled"
        :aria-invalid="error ? 'true' : 'false'"
        :aria-describedby="ariaDescribedBy"
      />
      <span v-if="$slots.trailing" class="app-input__affix app-input__affix--trailing">
        <slot name="trailing" />
      </span>
    </div>
    <p v-if="error" :id="`${id}-error`" class="app-input__message app-input__message--error">
      {{ error }}
    </p>
    <p v-else-if="hint" :id="`${id}-hint`" class="app-input__message">{{ hint }}</p>
  </div>
</template>

<style scoped>
.app-input {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-6);
}

.app-input__label {
  font-size: var(--type-body-medium-emphasis-size);
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
  align-items: center;
  gap: var(--gap-s);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  transition:
    var(--theme-transition);
}

.app-input__control--m {
  min-height: var(--control-h-m);
  padding: var(--pad-input-m-vertical) var(--pad-input-m-horizontal);
}

.app-input__control--l {
  min-height: var(--control-h-l);
  padding: var(--pad-input-l-vertical) var(--pad-input-l-horizontal);
}

.app-input__control:focus-within {
  border-color: var(--focus-border);
  box-shadow: var(--focus-ring);
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
  font-size: var(--type-body-large-size);
  line-height: var(--type-body-large-line);
  font-weight: var(--type-body-large-weight);
  color: var(--text-1);
  outline: none;
  transition: var(--theme-transition);
}

.app-input__native::placeholder {
  color: var(--text-3);
  transition: var(--theme-transition);
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
