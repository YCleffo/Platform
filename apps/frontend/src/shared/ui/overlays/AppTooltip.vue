<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

const props = withDefaults(defineProps<{ content: string; disabled?: boolean }>(), {
  disabled: false,
});
const trigger = ref<HTMLElement | null>(null);
const visible = ref(false);
const position = ref({ left: 0, top: 0 });

function show(): void {
  if (props.disabled || trigger.value === null) return;
  const rect = trigger.value.getBoundingClientRect();
  position.value = { left: rect.right + 8, top: rect.top + rect.height / 2 };
  visible.value = true;
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
}

function hide(): void {
  visible.value = false;
  window.removeEventListener('scroll', hide, true);
  window.removeEventListener('resize', hide);
}

const style = computed(() => ({
  left: `${position.value.left}px`,
  top: `${position.value.top}px`,
}));

onBeforeUnmount(hide);
</script>

<template>
  <span
    ref="trigger"
    class="app-tooltip"
    @mouseenter="show"
    @mouseleave="hide"
    @focusin="show"
    @focusout="hide"
  >
    <slot />
  </span>
  <Teleport to="body">
    <Transition name="app-tooltip">
      <span
        v-if="visible"
        class="app-tooltip__bubble"
        role="tooltip"
        :style="style"
      >
        {{ content }}
      </span>
    </Transition>
  </Teleport>
</template>

<style scoped>
.app-tooltip {
  display: block;
  min-width: 0;
}

.app-tooltip__bubble {
  position: fixed;
  z-index: 1100;
  max-width: 240px;
  padding: var(--space-6) var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  background: var(--surface-2);
  color: var(--text-1);
  box-shadow: var(--shadow-soft);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  white-space: nowrap;
  pointer-events: none;
  transform: translateY(-50%);
  transition:
    opacity var(--motion-fast-duration) var(--motion-ease),
    transform var(--motion-fast-duration) var(--motion-ease),
    var(--theme-transition);
}

.app-tooltip-enter-from,
.app-tooltip-leave-to {
  opacity: 0;
  transform: translate(4px, -50%);
}
</style>
