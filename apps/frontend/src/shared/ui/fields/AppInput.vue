<script setup lang="ts">
import { computed } from 'vue';

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

const ariaDescribedBy = computed(() => {
  if (props.error !== undefined) {
    return `${props.id}-error`;
  }
  if (props.hint !== undefined) {
    return `${props.id}-hint`;
  }
  return undefined;
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
  font-size: 14px;
  line-height: 20px;
  font-weight: 500;
  color: var(--text-2);
}

.app-input__required {
  color: var(--danger);
  margin-left: 2px;
}

.app-input__control {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  transition:
    border-color 120ms ease,
    box-shadow 120ms ease,
    background-color 120ms ease;
}

.app-input__control--m {
  height: var(--control-h-m);
  padding: 0 var(--space-12);
}

.app-input__control--l {
  height: var(--control-h-l);
  padding: 0 var(--space-12);
}

.app-input__control:focus-within {
  border-color: var(--focus-border);
  box-shadow: var(--focus-ring);
}

.app-input__control--error {
  border-color: var(--danger);
}

.app-input__control--disabled {
  background: var(--disabled-surface);
  border-color: var(--disabled-border);
}

.app-input__native {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font-size: 16px;
  line-height: 24px;
  color: var(--text-1);
  outline: none;
}

.app-input__native::placeholder {
  color: var(--text-3);
}

.app-input__native:disabled {
  color: var(--disabled-text);
  cursor: not-allowed;
}

.app-input__affix {
  display: inline-flex;
  align-items: center;
  color: var(--text-3);
}

.app-input__message {
  font-size: 12px;
  line-height: 16px;
  color: var(--text-3);
}

.app-input__message--error {
  color: var(--danger-text);
}
</style>
