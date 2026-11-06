<script setup lang="ts">
import { ref } from 'vue';
import AppMenu from './AppMenu.vue';
import type { MenuItem } from './types';

defineProps<{ items: readonly MenuItem[]; label: string; searchable?: boolean }>();
const emit = defineEmits<{ select: [item: MenuItem] }>();
const open = ref(false);
const point = ref<{ x: number; y: number } | null>(null);
const anchor = ref<HTMLElement | null>(null);
function show(event: MouseEvent | KeyboardEvent): void {
  if (event.defaultPrevented || (event.shiftKey && event instanceof MouseEvent)) return;
  const target = event.target instanceof HTMLElement ? event.target : null;
  // Preserve browser editing, link actions and selected text. Shift + right click is an escape hatch.
  if (
    !target ||
    target.closest(
      'input, textarea, select, [contenteditable]:not([contenteditable="false"]), a, button, [role="menu"], [data-native-context]',
    ) ||
    window.getSelection()?.toString()
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
  open.value = true;
}
function keydown(event: KeyboardEvent): void {
  if (event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey)) show(event);
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
