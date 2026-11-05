<script setup lang="ts">
import { useId } from 'vue';
import { ChevronDown } from '@/shared/ui/icons';
defineProps<{ label: string; disabled?: boolean }>();
const open = defineModel<boolean>({ default: false });
const id = `disclosure-${useId()}`;
</script>

<template>
  <section
    class="app-disclosure"
    :class="{ 'app-disclosure--open': open }"
  >
    <button
      :id="`${id}-trigger`"
      type="button"
      class="app-disclosure__trigger"
      :aria-expanded="open"
      :aria-controls="`${id}-content`"
      :disabled="disabled"
      @click="open = !open"
    >
      <span>{{ label }}</span>
      <ChevronDown
        class="app-disclosure__icon"
        :size="18"
        aria-hidden="true"
      />
    </button>
    <Transition name="disclosure">
      <div
        v-show="open"
        :id="`${id}-content`"
        class="app-disclosure__content"
        role="region"
        :inert="!open"
        :aria-hidden="!open"
        :aria-labelledby="`${id}-trigger`"
      >
        <div class="app-disclosure__inner"><slot /></div>
      </div>
    </Transition>
  </section>
</template>

<style scoped>
.app-disclosure {
  min-width: 0;
}
.app-disclosure__trigger {
  transition: var(--theme-transition);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  width: 100%;
  min-height: var(--control-h-s);
  padding: var(--space-4) 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: start;
  cursor: pointer;
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
}
.app-disclosure__trigger:disabled {
  cursor: default;
  opacity: 0.55;
}
.app-disclosure__trigger:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: var(--space-4);
  border-radius: var(--radius-control-s);
}
.app-disclosure__icon {
  flex: none;
  color: var(--text-2);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease);
}
.app-disclosure--open > .app-disclosure__trigger .app-disclosure__icon {
  transform: rotate(180deg);
}
.app-disclosure__content {
  display: grid;
  grid-template-rows: 1fr;
}
.app-disclosure__inner {
  min-height: 0;
  overflow: hidden;
}
.disclosure-enter-active,
.disclosure-leave-active {
  transition:
    var(--theme-transition),
    grid-template-rows var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-control-duration) var(--motion-ease);
}
.disclosure-enter-from,
.disclosure-leave-to {
  grid-template-rows: 0fr;
  opacity: 0;
}
@media (pointer: coarse) {
  .app-disclosure__trigger {
    min-height: var(--button-height-m);
  }
}
</style>
