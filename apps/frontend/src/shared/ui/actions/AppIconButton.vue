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
    var(--theme-transition);
}

.app-icon-button--s {
  width: var(--control-h-s);
  height: var(--control-h-s);
}

.app-icon-button--m {
  width: var(--button-height-m);
  height: var(--button-height-m);
}

.app-icon-button--l {
  width: var(--control-h-l);
  height: var(--control-h-l);
}

.app-icon-button--ghost {
  background: transparent;
  transition: var(--theme-transition);
}

.app-icon-button--ghost:hover:not(:disabled) {
  background: var(--surface-hover);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.app-icon-button--secondary {
  background: var(--surface);
  border-color: var(--border);
  transition: var(--theme-transition);
}

.app-icon-button--secondary:hover:not(:disabled) {
  background: var(--surface-hover);
  transition: var(--theme-transition);
}

.app-icon-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
