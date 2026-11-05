<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const items = defineModel<string[]>({ default: () => [] });
const { t } = useI18n();
const dragIndex = ref<number | null>(null);
const overIndex = ref<number | null>(null);
const handles = ref<HTMLButtonElement[]>([]);

function move(index: number, target: number): void {
  if (target < 0 || target >= items.value.length || target === index) return;
  const next = [...items.value];
  const [moved] = next.splice(index, 1);
  if (moved === undefined) return;
  next.splice(target, 0, moved);
  items.value = next;
  void nextTick(() => handles.value[target]?.focus());
}

function onDragStart(index: number, event: DragEvent): void {
  dragIndex.value = index;
  if (event.dataTransfer === null) return;
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', String(index));
}

function onDragOver(index: number, event: DragEvent): void {
  event.preventDefault();
  if (event.dataTransfer !== null) event.dataTransfer.dropEffect = 'move';
  overIndex.value = index;
}

function onDrop(index: number): void {
  if (dragIndex.value !== null) move(dragIndex.value, index);
  onDragEnd();
}

function onDragEnd(): void {
  dragIndex.value = null;
  overIndex.value = null;
}

function setHandle(element: Element | null, index: number): void {
  if (element instanceof HTMLButtonElement) handles.value[index] = element;
}
</script>

<template>
  <ul class="sortable">
    <li
      v-for="(item, index) in items"
      :key="item"
      class="sortable__item"
      :class="{
        'sortable__item--dragging': dragIndex === index,
        'sortable__item--over': overIndex === index && dragIndex !== index,
      }"
      @dragover="onDragOver(index, $event)"
      @drop.prevent="onDrop(index)"
    >
      <button
        :ref="(element) => setHandle(element as Element | null, index)"
        type="button"
        class="sortable__handle"
        draggable="true"
        :aria-label="t('sortable.move', { index: index + 1 })"
        @dragstart="onDragStart(index, $event)"
        @dragend="onDragEnd"
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
  display: flex;
  align-items: center;
  gap: var(--space-8);
  min-height: 42px;
  padding: var(--space-6) var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  transition: var(--theme-transition);
}
.sortable__item--dragging {
  opacity: 0.45;
}
.sortable__item--over {
  border-color: var(--accent);
  background: var(--accent-soft);
}
.sortable__handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  flex: 0 0 auto;
  border-radius: var(--radius-control-s);
  color: var(--text-3);
  cursor: grab;
}
.sortable__handle:hover {
  background: var(--surface-hover);
  color: var(--text-1);
}
.sortable__handle:active {
  cursor: grabbing;
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
