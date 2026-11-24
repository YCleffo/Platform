<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(
  defineProps<{
    progress?: number;
    bars?: number[];
    interactive?: boolean;
    barCount?: number;
  }>(),
  {
    progress: 0,
    bars: undefined,
    interactive: true,
    barCount: 36,
  },
);

const emit = defineEmits<{
  (e: 'seek', ratio: number): void;
}>();

const isDragging = ref(false);

const normalizedBars = computed(() => {
  if (props.bars && props.bars.length > 0) {
    return props.bars;
  }
  // Deterministic mock pattern for smooth audio waves
  const count = props.barCount;
  const result: number[] = [];
  for (let i = 0; i < count; i++) {
    const val = 0.25 + 0.65 * Math.abs(Math.sin((i / count) * Math.PI * 2.8) * Math.cos(i * 0.4));
    result.push(Math.min(1, Math.max(0.15, val)));
  }
  return result;
});

function seekFromEvent(event: MouseEvent | PointerEvent): void {
  if (!props.interactive) return;
  const target = (event.currentTarget || event.target) as HTMLElement;
  const container = (
    target.classList.contains('app-audio-waveform') ? target : target.closest('.app-audio-waveform')
  ) as HTMLElement | null;
  if (!container) return;
  const rect = container.getBoundingClientRect();
  if (rect.width <= 0) return;
  const clickX = event.clientX - rect.left;
  const ratio = Math.max(0, Math.min(1, clickX / rect.width));
  emit('seek', ratio);
}

function handlePointerDown(event: PointerEvent): void {
  if (!props.interactive) return;
  isDragging.value = true;
  try {
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
  } catch {}
  seekFromEvent(event);
}

function handlePointerMove(event: PointerEvent): void {
  if (!isDragging.value || !props.interactive) return;
  seekFromEvent(event);
}

function handlePointerUp(event: PointerEvent): void {
  if (!isDragging.value) return;
  isDragging.value = false;
  try {
    (event.currentTarget as HTMLElement).releasePointerCapture(event.pointerId);
  } catch {}
}
</script>

<template>
  <div
    class="app-audio-waveform"
    :class="{
      'app-audio-waveform--interactive': interactive,
      'app-audio-waveform--dragging': isDragging,
    }"
    role="slider"
    :aria-valuenow="Math.round(progress * 100)"
    aria-valuemin="0"
    aria-valuemax="100"
    tabindex="0"
    @pointerdown="handlePointerDown"
    @pointermove="handlePointerMove"
    @pointerup="handlePointerUp"
    @pointercancel="handlePointerUp"
    @click="seekFromEvent"
    @keydown.left.prevent="emit('seek', Math.max(0, progress - 0.05))"
    @keydown.right.prevent="emit('seek', Math.min(1, progress + 0.05))"
    @keydown.home.prevent="emit('seek', 0)"
    @keydown.end.prevent="emit('seek', 1)"
  >
    <div
      v-for="(bar, index) in normalizedBars"
      :key="index"
      class="app-audio-waveform__bar"
      :class="{
        'app-audio-waveform__bar--played': index / normalizedBars.length <= progress,
      }"
      :style="{ height: `${Math.round(bar * 100)}%` }"
    />
  </div>
</template>

<style scoped>
.app-audio-waveform {
  display: flex;
  align-items: center;
  gap: 2px;
  height: 28px;
  min-width: 100px;
  width: 100%;
  padding: 2px 0;
  box-sizing: border-box;
  touch-action: none;
}

.app-audio-waveform--interactive {
  cursor: pointer;
}

.app-audio-waveform--dragging {
  cursor: grabbing;
}

.app-audio-waveform:focus-visible {
  outline: 2px solid var(--accent, #00d2ff);
  outline-offset: 2px;
  border-radius: var(--radius-card-s, 4px);
}

.app-audio-waveform__bar {
  flex: 1;
  min-width: 2px;
  border-radius: 1px;
  background-color: var(--border-default, rgba(255, 255, 255, 0.2));
  transition:
    background-color var(--theme-transition),
    height var(--theme-transition);
}

.app-audio-waveform__bar--played {
  background-color: var(--accent, #00d2ff);
}

.app-audio-waveform:hover .app-audio-waveform__bar--played {
  filter: brightness(1.15);
}
</style>
