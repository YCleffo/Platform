<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

export interface GalleryImageItem {
  id?: string;
  src: string;
  alt?: string;
  title?: string;
}

const props = withDefaults(
  defineProps<{
    src?: string;
    alt?: string;
    items?: GalleryImageItem[];
    initialIndex?: number;
  }>(),
  {
    src: undefined,
    alt: '',
    items: undefined,
    initialIndex: 0,
  },
);

const emit = defineEmits<{
  (e: 'change', index: number): void;
  (e: 'error', message: string): void;
}>();

const { t } = useI18n();

const currentIndex = ref(props.initialIndex);
const zoom = ref(1);
const rotation = ref(0);
const loadError = ref(false);
let touchStartX = 0;

const galleryItems = computed<GalleryImageItem[]>(() => {
  if (props.items && props.items.length > 0) {
    return props.items;
  }
  if (props.src) {
    return [{ src: props.src, alt: props.alt, title: props.alt }];
  }
  return [];
});

const currentItem = computed(() => galleryItems.value[currentIndex.value] ?? null);
const hasMultiple = computed(() => galleryItems.value.length > 1);

function resetTransform(): void {
  zoom.value = 1;
  rotation.value = 0;
  loadError.value = false;
}

function selectIndex(index: number): void {
  if (galleryItems.value.length === 0) return;
  const normalized = ((index % galleryItems.value.length) + galleryItems.value.length) % galleryItems.value.length;
  currentIndex.value = normalized;
  resetTransform();
  emit('change', normalized);
}

function next(): void {
  if (!hasMultiple.value) return;
  selectIndex(currentIndex.value + 1);
}

function previous(): void {
  if (!hasMultiple.value) return;
  selectIndex(currentIndex.value - 1);
}

function zoomIn(): void {
  zoom.value = Math.min(3, Number((zoom.value + 0.25).toFixed(2)));
}

function zoomOut(): void {
  zoom.value = Math.max(0.5, Number((zoom.value - 0.25).toFixed(2)));
}

function rotate(): void {
  rotation.value = (rotation.value + 90) % 360;
}

function handleError(): void {
  loadError.value = true;
  emit('error', t('media.unavailable'));
}

function handleTouchStart(event: TouchEvent): void {
  if (event.touches[0]) {
    touchStartX = event.touches[0].clientX;
  }
}

function handleTouchEnd(event: TouchEvent): void {
  if (!hasMultiple.value || !event.changedTouches[0]) return;
  const delta = event.changedTouches[0].clientX - touchStartX;
  if (Math.abs(delta) > 48) {
    if (delta < 0) next();
    else previous();
  }
}

function handleKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowLeft':
      if (hasMultiple.value) {
        event.preventDefault();
        previous();
      }
      break;
    case 'ArrowRight':
      if (hasMultiple.value) {
        event.preventDefault();
        next();
      }
      break;
    case '+':
    case '=':
      event.preventDefault();
      zoomIn();
      break;
    case '-':
      event.preventDefault();
      zoomOut();
      break;
    case '0':
      event.preventDefault();
      resetTransform();
      break;
  }
}

watch(
  () => [props.src, props.items, props.initialIndex] as const,
  () => {
    currentIndex.value = Math.max(
      0,
      Math.min(props.initialIndex, Math.max(0, galleryItems.value.length - 1)),
    );
    resetTransform();
  },
);
</script>

<template>
  <div
    class="app-image-viewer"
    tabindex="0"
    @keydown="handleKeydown"
    @touchstart.passive="handleTouchStart"
    @touchend.passive="handleTouchEnd"
  >
    <div class="app-image-viewer__toolbar">
      <div class="app-image-viewer__controls">
        <button
          type="button"
          class="app-image-viewer__btn"
          :aria-label="t('media.zoomOut')"
          @click="zoomOut"
        >
          −
        </button>
        <span class="app-image-viewer__zoom">{{ Math.round(zoom * 100) }}%</span>
        <button
          type="button"
          class="app-image-viewer__btn"
          :aria-label="t('media.zoomIn')"
          @click="zoomIn"
        >
          +
        </button>
        <button
          type="button"
          class="app-image-viewer__btn"
          :aria-label="t('media.resetZoom')"
          @click="resetTransform"
        >
          1:1
        </button>
        <button
          type="button"
          class="app-image-viewer__btn"
          :aria-label="t('media.rotate')"
          @click="rotate"
        >
          ↻
        </button>
      </div>
      <span
        v-if="hasMultiple"
        class="app-image-viewer__counter"
      >
        {{ currentIndex + 1 }} / {{ galleryItems.length }}
      </span>
    </div>

    <div class="app-image-viewer__stage">
      <button
        v-if="hasMultiple"
        type="button"
        class="app-image-viewer__nav app-image-viewer__nav--prev"
        :aria-label="t('media.previous')"
        @click="previous"
      >
        ‹
      </button>

      <div
        v-if="!currentItem || !currentItem.src || loadError"
        class="app-image-viewer__error"
        role="alert"
      >
        {{ t('media.unavailable') }}
      </div>
      <img
        v-else
        :src="currentItem.src"
        :alt="currentItem.alt || currentItem.title || alt || 'image'"
        class="app-image-viewer__img"
        :style="{ transform: `scale(${zoom}) rotate(${rotation}deg)` }"
        @error="handleError"
      />

      <button
        v-if="hasMultiple"
        type="button"
        class="app-image-viewer__nav app-image-viewer__nav--next"
        :aria-label="t('media.next')"
        @click="next"
      >
        ›
      </button>
    </div>

    <div
      v-if="hasMultiple"
      class="app-image-viewer__thumbs"
    >
      <button
        v-for="(item, idx) in galleryItems"
        :key="item.id || `${item.src}-${idx}`"
        type="button"
        class="app-image-viewer__thumb"
        :class="{ 'app-image-viewer__thumb--active': idx === currentIndex }"
        :aria-label="item.alt || item.title || `${idx + 1}`"
        @click="selectIndex(idx)"
      >
        <img
          :src="item.src"
          :alt="item.alt || ''"
          loading="lazy"
        />
      </button>
    </div>
  </div>
</template>

<style scoped>
.app-image-viewer {
  display: flex;
  flex-direction: column;
  gap: 8px;
  outline: none;
}

.app-image-viewer__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.app-image-viewer__controls {
  display: flex;
  align-items: center;
  gap: 6px;
}

.app-image-viewer__btn {
  background: var(--surface-2, var(--surface-secondary, rgba(127, 127, 127, 0.14)));
  color: var(--text-1, var(--text-primary, inherit));
  border: 1px solid var(--border, var(--border-default, rgba(127, 127, 127, 0.25)));
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}

.app-image-viewer__zoom,
.app-image-viewer__counter {
  font-size: 12px;
  color: var(--text-2, var(--text-secondary, #6b7280));
  font-variant-numeric: tabular-nums;
}

.app-image-viewer__stage {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 240px;
  max-height: 68vh;
  overflow: auto;
  background: var(--surface-secondary, #10141c);
  border-radius: var(--radius-card-m, 12px);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
}

.app-image-viewer__img {
  max-width: 100%;
  max-height: 65vh;
  object-fit: contain;
  transition: transform 150ms ease;
  display: block;
}

.app-image-viewer__error {
  padding: 24px;
  color: var(--text-secondary, #9ba3af);
  font-size: 13px;
  text-align: center;
}

.app-image-viewer__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 2;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.2));
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.app-image-viewer__nav--prev {
  left: 10px;
}

.app-image-viewer__nav--next {
  right: 10px;
}

.app-image-viewer__thumbs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 2px 0;
}

.app-image-viewer__thumb {
  width: 52px;
  height: 52px;
  padding: 0;
  border-radius: 6px;
  overflow: hidden;
  border: 2px solid transparent;
  background: transparent;
  cursor: pointer;
  flex-shrink: 0;
}

.app-image-viewer__thumb--active {
  border-color: var(--accent, #00d2ff);
}

.app-image-viewer__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
</style>
