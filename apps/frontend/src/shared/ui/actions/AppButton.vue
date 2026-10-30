<script setup lang="ts">
import { computed } from 'vue';
import { LoaderCircle } from '@lucide/vue';

interface Props {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 's' | 'm' | 'l';
  type?: 'button' | 'submit';
  loading?: boolean;
  disabled?: boolean;
  block?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'm',
  type: 'button',
  loading: false,
  disabled: false,
  block: false,
});

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
  <button
    :type="type"
    class="app-button"
    :class="[`app-button--${variant}`, `app-button--${size}`, { 'app-button--block': block }]"
    :disabled="isDisabled"
    :aria-busy="loading ? 'true' : undefined"
  >
    <LoaderCircle v-if="loading" class="app-button__spinner" :size="18" aria-hidden="true" />
    <slot />
  </button>
</template>

<style scoped>
.app-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-8);
  border: 1px solid transparent;
  border-radius: var(--radius-control-m);
  font-weight: 500;
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
  transition:
    background-color 120ms ease,
    border-color 120ms ease,
    color 120ms ease,
    box-shadow 120ms ease;
}

.app-button--s {
  height: var(--control-h-s);
  padding: 0 var(--space-12);
  font-size: 12px;
  line-height: 16px;
}

.app-button--m {
  min-height: var(--control-h-m);
  height: 44px;
  padding: 0 var(--space-16);
  font-size: 14px;
  line-height: 20px;
}

.app-button--l {
  height: var(--control-h-l);
  padding: 0 var(--space-20);
  font-size: 16px;
  line-height: 24px;
}

.app-button--block {
  width: 100%;
}

.app-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.app-button--primary {
  background: var(--accent);
  color: var(--color-white);
}

.app-button--primary:hover:not(:disabled) {
  background: var(--accent-hover);
}

.app-button--primary:active:not(:disabled) {
  background: var(--accent-pressed);
}

.app-button--secondary {
  background: var(--surface);
  color: var(--text-1);
  border-color: var(--border-strong);
}

.app-button--secondary:hover:not(:disabled) {
  background: var(--surface-hover);
}

.app-button--secondary:active:not(:disabled) {
  background: var(--surface-2);
}

.app-button--ghost {
  background: transparent;
  color: var(--text-2);
}

.app-button--ghost:hover:not(:disabled) {
  background: var(--surface-hover);
  color: var(--text-1);
}

.app-button--danger {
  background: var(--danger);
  color: var(--color-white);
}

.app-button--danger:hover:not(:disabled) {
  background: var(--color-danger-700);
}

.app-button__spinner {
  animation: app-button-spin 700ms linear infinite;
}

@keyframes app-button-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
