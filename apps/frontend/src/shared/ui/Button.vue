<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  type?: 'button' | 'submit';
  loading?: boolean;
  disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  type: 'button',
  loading: false,
  disabled: false,
});

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
  <button
    :type="type"
    class="button"
    :class="`button--${variant}`"
    :disabled="isDisabled"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span v-if="loading" class="button__spinner" aria-hidden="true"></span>
    <slot />
  </button>
</template>

<style scoped>
.button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  border: 1px solid transparent;
  border-radius: var(--radius-md);
  font-weight: 600;
  cursor: pointer;
  transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.button--primary {
  background: var(--color-primary);
  color: var(--color-primary-contrast);
}

.button--primary:hover:not(:disabled) {
  background: var(--color-primary-hover);
}

.button--secondary {
  background: var(--color-surface);
  color: var(--color-text);
  border-color: var(--color-border);
}

.button--secondary:hover:not(:disabled) {
  background: var(--color-surface-alt);
}

.button--danger {
  background: var(--color-danger);
  color: var(--color-danger-contrast);
}

.button--danger:hover:not(:disabled) {
  background: var(--color-danger-hover);
}

.button--ghost {
  background: transparent;
  color: var(--color-primary);
}

.button--ghost:hover:not(:disabled) {
  background: var(--color-surface-alt);
}

.button__spinner {
  width: 1em;
  height: 1em;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: button-spin 600ms linear infinite;
}

@keyframes button-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
