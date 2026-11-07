<script setup lang="ts">
import { ref } from 'vue';
import AppMenu from './AppMenu.vue';
import type { MenuItem } from './types';

const props = withDefaults(
  defineProps<{
    items: readonly MenuItem[];
    label: string;
    searchable?: boolean;
    disabled?: boolean;
    beforeOpen?: (context: {
      target: HTMLElement;
      event: MouseEvent | KeyboardEvent;
    }) => Promise<void> | void;
  }>(),
  {
    searchable: false,
    disabled: false,
    beforeOpen: undefined,
  },
);

const emit = defineEmits<{ select: [item: MenuItem] }>();
const open = ref(false);
const point = ref<{ x: number; y: number } | null>(null);
const anchor = ref<HTMLElement | null>(null);

async function show(event: MouseEvent | KeyboardEvent): Promise<void> {
  if (props.disabled) return;
  if (event.defaultPrevented || (event.shiftKey && event instanceof MouseEvent)) return;
  const target = event.target instanceof HTMLElement ? event.target : null;
  // Preserve browser editing, link actions, dialogs and custom opt-outs. Shift + right click is an escape hatch.
  if (
    !target ||
    target.closest(
      'input, textarea, select, [contenteditable]:not([contenteditable="false"]), a, button, [role="menu"], [role="dialog"], dialog, .app-dialog, [data-native-context], [data-no-context-menu]',
    )
  )
    return;
  event.preventDefault();
  event.stopPropagation();
  anchor.value = target.closest<HTMLElement>('[tabindex]') ?? target;
  const rect = target.getBoundingClientRect();
  point.value =
    event instanceof MouseEvent
      ? { x: event.clientX, y: event.clientY }
      : { x: rect.left + 8, y: rect.top + 8 };
  if (props.beforeOpen) {
    await props.beforeOpen({ target, event });
  }
  open.value = true;
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey)) {
    void show(event);
  }
}
</script>

<template>
  <div
    class="app-context-menu"
    @contextmenu="show"
    @keydown="keydown"
  >
    <slot />
    <AppMenu
      v-model="open"
      :items="items"
      :label="label"
      :anchor="anchor"
      :point="point"
      :searchable="searchable"
      close-on-anchor
      @select="emit('select', $event)"
    />
  </div>
</template>

<style scoped>
.app-context-menu {
  display: contents;
}
</style>
