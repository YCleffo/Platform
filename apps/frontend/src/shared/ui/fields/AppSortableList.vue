<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const items = defineModel<string[]>({ default: () => [] });
const { t } = useI18n();
const list = ref<HTMLUListElement | null>(null);
const handles = ref<HTMLButtonElement[]>([]);
const dragIndex = ref<number | null>(null);
const overIndex = ref<number | null>(null);
const dragOffset = ref(0);
const dragStep = ref(0);
let pointerId: number | null = null;
let dragRects: Array<{ top: number; height: number }> = [];
let startY = 0;
let activeHandle: HTMLButtonElement | null = null;

function move(index: number, target: number): void {
  if (target < 0 || target >= items.value.length || target === index) return;
  const next = [...items.value];
  const [moved] = next.splice(index, 1);
  if (moved === undefined) return;
  next.splice(target, 0, moved);
  items.value = next;
  void nextTick(() => handles.value[target]?.focus());
}

function elements(): HTMLElement[] {
  return Array.from(list.value?.children ?? []) as HTMLElement[];
}

function targetIndex(pointerY: number): number {
  const rows = elements();
  let target = rows.length;
  for (let index = 0; index < rows.length; index += 1) {
    const rect = dragRects[index];
    if (rect !== undefined && pointerY < rect.top + rect.height / 2) {
      target = index;
      break;
    }
  }
  if (dragIndex.value !== null && target > dragIndex.value) target -= 1;
  return Math.max(0, Math.min(rows.length - 1, target));
}

function onPointerDown(index: number, event: PointerEvent): void {
  if (!event.isPrimary || event.button !== 0 || dragIndex.value !== null) return;
  const handle = event.currentTarget;
  if (!(handle instanceof HTMLButtonElement)) return;
  const rows = elements();
  const row = rows[index];
  if (row === undefined) return;
  dragRects = rows.map((item) => {
    const rect = item.getBoundingClientRect();
    return { top: rect.top, height: rect.height };
  });
  const rowRect = dragRects[index];
  const nextRect = dragRects[index + 1] ?? dragRects[index - 1];
  dragStep.value =
    nextRect === undefined || rowRect === undefined
      ? (rowRect?.height ?? 0) + 4
      : Math.abs(nextRect.top - rowRect.top);
  dragIndex.value = index;
  overIndex.value = index;
  dragOffset.value = 0;
  startY = event.clientY;
  activeHandle = handle;
  pointerId = event.pointerId;
  handle.setPointerCapture(event.pointerId);
  handle.focus({ preventScroll: true });
  document.body.style.userSelect = 'none';
  document.body.style.cursor = 'grabbing';
  window.addEventListener('pointermove', onPointerMove, { passive: false });
  window.addEventListener('pointerup', onPointerUp);
  window.addEventListener('pointercancel', onPointerCancel);
  event.preventDefault();
}

function onPointerMove(event: PointerEvent): void {
  if (dragIndex.value === null || event.pointerId !== pointerId) return;
  dragOffset.value = event.clientY - startY;
  overIndex.value = targetIndex(event.clientY);
  event.preventDefault();
}

function onPointerUp(event: PointerEvent): void {
  if (event.pointerId !== pointerId || dragIndex.value === null) return;
  const from = dragIndex.value;
  const to = overIndex.value ?? from;
  finishDrag();
  if (from !== to) move(from, to);
}

function onPointerCancel(): void {
  finishDrag();
}

function finishDrag(): void {
  if (pointerId !== null && activeHandle !== null) {
    if (activeHandle.hasPointerCapture(pointerId)) activeHandle.releasePointerCapture(pointerId);
    activeHandle = null;
  }
  pointerId = null;
  dragIndex.value = null;
  overIndex.value = null;
  dragOffset.value = 0;
  dragRects = [];
  document.body.style.userSelect = '';
  document.body.style.cursor = '';
  window.removeEventListener('pointermove', onPointerMove);
  window.removeEventListener('pointerup', onPointerUp);
  window.removeEventListener('pointercancel', onPointerCancel);
}

function itemStyle(index: number): Record<string, string> | undefined {
  if (dragIndex.value === null) return undefined;
  if (index === dragIndex.value) {
    return { transform: `translateY(${dragOffset.value}px)`, zIndex: '2' };
  }
  if (overIndex.value === null) return undefined;
  const from = dragIndex.value;
  const to = overIndex.value;
  if (from < to && index > from && index <= to) {
    return { transform: `translateY(-${dragStep.value}px)` };
  }
  if (from > to && index >= to && index < from) {
    return { transform: `translateY(${dragStep.value}px)` };
  }
  return undefined;
}

function setHandle(element: Element | null, index: number): void {
  if (element instanceof HTMLButtonElement) handles.value[index] = element;
}

onBeforeUnmount(finishDrag);
</script>

<template>
  <ul
    ref="list"
    class="sortable"
  >
    <li
      v-for="(item, index) in items"
      :key="item"
      class="sortable__item"
      :class="{
        'sortable__item--dragging': dragIndex === index,
        'sortable__item--over': overIndex === index && dragIndex !== index,
      }"
      :style="itemStyle(index)"
    >
      <button
        :ref="(element) => setHandle(element as Element | null, index)"
        type="button"
        class="sortable__handle"
        :aria-label="t('sortable.move', { index: index + 1 })"
        @pointerdown="onPointerDown(index, $event)"
        @keydown.alt.up.prevent="move(index, index - 1)"
        @keydown.alt.down.prevent="move(index, index + 1)"
      >
        <span
          class="sortable__grip"
          aria-hidden="true"
        >
          <span />
          <span />
          <span />
          <span />
          <span />
          <span />
        </span>
      </button>
      <span class="sortable__label">{{ item }}</span>
      <span
        class="sortable__index"
        aria-hidden="true"
      >
        {{ index + 1 }}
      </span>
    </li>
  </ul>
</template>

<style scoped>
.sortable {
  display: grid;
  gap: var(--space-4);
}
.sortable__item {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--space-8);
  min-height: calc(42px * var(--ui-density-scale, 1));
  padding: var(--space-6) var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
  will-change: transform;
}
.sortable__item--dragging {
  z-index: 2;
  opacity: 0.96;
  box-shadow: var(--shadow-popover);
  background: var(--card);
  transition: none;
  cursor: grabbing;
}
.sortable__item--over {
  box-shadow: 0 2px 0 -1px var(--accent);
}
.sortable__handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: calc(28px * var(--ui-density-scale, 1));
  height: calc(28px * var(--ui-density-scale, 1));
  flex: 0 0 auto;
  border-radius: var(--radius-control-s);
  color: var(--text-3);
  cursor: grab;
  touch-action: none;
}
.sortable__handle:hover {
  background: var(--surface-hover);
  color: var(--text-1);
}
.sortable__handle:active {
  cursor: grabbing;
}
.sortable__handle:focus-visible {
  outline: 2px solid var(--focus-border);
  outline-offset: 2px;
}
.sortable__grip {
  display: grid;
  grid-template-columns: repeat(2, 3px);
  grid-template-rows: repeat(3, 3px);
  gap: 2px;
}
.sortable__grip span {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: currentColor;
}
.sortable__label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sortable__index {
  color: var(--text-3);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-variant-numeric: tabular-nums;
}
</style>
