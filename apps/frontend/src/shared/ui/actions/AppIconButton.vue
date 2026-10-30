<script setup lang="ts">
interface Props {
  label: string;
  variant?: 'ghost' | 'secondary';
  size?: 's' | 'm' | 'l';
  disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
  variant: 'ghost',
  size: 'm',
  disabled: false,
});

defineEmits<{
  click: [event: MouseEvent];
}>();
</script>

<template>
  <button
    type="button"
    class="app-icon-button"
    :class="[`app-icon-button--${variant}`, `app-icon-button--${size}`]"
    :aria-label="label"
    :disabled="disabled"
    @click="$emit('click', $event)"
  >
    <slot />
  </button>
</template>

<style scoped>
.app-icon-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-control-m);
  border: 1px solid transparent;
  cursor: pointer;
  color: var(--text-2);
  transition:
    background-color 120ms ease,
    color 120ms ease,
    border-color 120ms ease;
}

.app-icon-button--s {
  width: 32px;
  height: 32px;
}

.app-icon-button--m {
  width: 44px;
  height: 44px;
}

.app-icon-button--l {
  width: 48px;
  height: 48px;
}

.app-icon-button--ghost {
  background: transparent;
}

.app-icon-button--ghost:hover:not(:disabled) {
  background: var(--surface-hover);
  color: var(--text-1);
}

.app-icon-button--secondary {
  background: var(--surface);
  border-color: var(--border);
}

.app-icon-button--secondary:hover:not(:disabled) {
  background: var(--surface-hover);
}

.app-icon-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
