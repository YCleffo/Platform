<script setup lang="ts">
import { computed } from 'vue';
import { LoaderCircle } from '@/shared/ui/icons';

interface Props {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 's' | 'm' | 'l';
  type?: 'button' | 'submit' | 'reset';
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
    <span class="app-button__content"><slot /></span>
    <span
      v-if="loading"
      class="app-button__loader"
      aria-hidden="true"
    >
      <LoaderCircle
        class="app-button__spinner"
        :size="18"
      />
    </span>
  </button>
</template>

<style scoped>
.app-button {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--gap-s);
  border: 1px solid transparent;
  border-radius: var(--radius-control-m);
  cursor: pointer;
  user-select: none;
  white-space: normal;
  max-width: 100%;
  text-align: center;
  transition: var(--theme-transition);
}

.app-button__content {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: inherit;
  min-width: 0;
  max-width: 100%;
}

.app-button[aria-busy='true'] .app-button__content {
  opacity: 0;
}

.app-button__loader {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  pointer-events: none;
}

.app-button--s {
  min-height: var(--control-h-s);
  padding: var(--pad-button-s-vertical) var(--pad-button-s-horizontal);
  font-size: var(--type-button-s-size);
  letter-spacing: var(--type-button-s-tracking);
  line-height: var(--type-button-s-line);
  font-weight: var(--type-button-s-weight);
}

.app-button--m {
  min-height: var(--button-height-m);
  padding: var(--pad-button-m-vertical) var(--pad-button-m-horizontal);
  font-size: var(--type-button-m-size);
  letter-spacing: var(--type-button-m-tracking);
  line-height: var(--type-button-m-line);
  font-weight: var(--type-button-m-weight);
}

.app-button--l {
  min-height: var(--control-h-l);
  padding: var(--pad-button-l-vertical) var(--pad-button-l-horizontal);
  font-size: var(--type-button-l-size);
  letter-spacing: var(--type-button-l-tracking);
  line-height: var(--type-button-l-line);
  font-weight: var(--type-button-l-weight);
}
@media (max-width: 767px) {
  .app-button--m {
    min-height: var(--control-h-m);
    padding: var(--pad-button-s-vertical) var(--pad-button-s-horizontal);
  }
}
@media (max-width: 767px) and (pointer: coarse) {
  .app-button--m {
    min-height: var(--button-height-m);
  }
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
  color: var(--on-accent);
  transition: var(--theme-transition);
}

.app-button--primary:hover:not(:disabled) {
  background: var(--accent-hover);
  transition: var(--theme-transition);
}

.app-button--primary:active:not(:disabled) {
  background: var(--accent-pressed);
  transition: var(--theme-transition);
}

.app-button--secondary {
  background: var(--surface);
  color: var(--text-1);
  border-color: var(--border-strong);
  transition: var(--theme-transition);
}

.app-button--secondary:hover:not(:disabled) {
  background: var(--surface-hover);
  transition: var(--theme-transition);
}

.app-button--secondary:active:not(:disabled) {
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.app-button--ghost {
  background: transparent;
  color: var(--text-2);
  transition: var(--theme-transition);
}

.app-button--ghost:hover:not(:disabled) {
  background: var(--surface-hover);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.app-button--danger {
  background: var(--danger-solid);
  color: var(--color-white);
  transition: var(--theme-transition);
}

.app-button--danger:hover:not(:disabled) {
  background: var(--danger-solid-hover);
  transition: var(--theme-transition);
}

.app-button__spinner {
  animation: app-button-spin var(--motion-spinner-duration) var(--motion-linear) infinite;
}

@keyframes app-button-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
