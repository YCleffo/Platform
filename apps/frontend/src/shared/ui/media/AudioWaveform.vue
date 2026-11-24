<script setup lang="ts">
import { computed } from 'vue';

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

function handleClick(event: MouseEvent): void {
  if (!props.interactive) return;
  const target = event.currentTarget as HTMLElement;
  const rect = target.getBoundingClientRect();
  const clickX = event.clientX - rect.left;
  const ratio = Math.max(0, Math.min(1, clickX / rect.width));
  emit('seek', ratio);
}
</script>

<template>
  <div
    class="app-audio-waveform"
    :class="{ 'app-audio-waveform--interactive': interactive }"
    role="slider"
    :aria-valuenow="Math.round(progress * 100)"
    aria-valuemin="0"
    aria-valuemax="100"
    tabindex="0"
    @click="handleClick"
    @keydown.left.prevent="emit('seek', Math.max(0, progress - 0.05))"
    @keydown.right.prevent="emit('seek', Math.min(1, progress + 0.05))"
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
}

.app-audio-waveform--interactive {
  cursor: pointer;
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
</style>
