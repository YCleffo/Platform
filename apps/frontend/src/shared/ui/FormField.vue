<script setup lang="ts">
interface Props {
  id: string;
  label: string;
  type?: string;
  autocomplete?: string;
  hint?: string;
  error?: string;
  required?: boolean;
  disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
  type: 'text',
  autocomplete: undefined,
  hint: undefined,
  error: undefined,
  required: false,
  disabled: false,
});

const value = defineModel<string | number>({ default: '' });
</script>

<template>
  <div class="field">
    <label class="field__label" :for="id">
      {{ label }}<span v-if="required" class="field__required" aria-hidden="true">*</span>
    </label>
    <input
      :id="id"
      v-model="value"
      class="field__input"
      :class="{ 'field__input--error': error }"
      :type="type"
      :autocomplete="autocomplete"
      :required="required"
      :disabled="disabled"
      :aria-invalid="error ? 'true' : 'false'"
      :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined"
    />
    <p v-if="error" :id="`${id}-error`" class="field__error">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-hint`" class="field__hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.field {
  display: grid;
  gap: var(--space-1);
}

.field__label {
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--color-text);
}

.field__required {
  color: var(--color-danger);
  margin-left: 2px;
}

.field__input {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  width: 100%;
}

.field__input:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: var(--focus-ring);
}

.field__input--error {
  border-color: var(--color-danger);
}

.field__input:disabled {
  background: var(--color-surface-alt);
  color: var(--color-text-muted);
  cursor: not-allowed;
}

.field__error {
  font-size: var(--text-sm);
  color: var(--color-danger);
}

.field__hint {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
</style>
