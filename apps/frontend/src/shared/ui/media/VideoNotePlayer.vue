<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useControlledMediaElement } from '@/shared/media/useControlledMediaElement';
import type { MediaSessionPolicy } from '@/shared/media/types';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    size?: number;
    autoplay?: boolean;
    muted?: boolean;
    policy?: MediaSessionPolicy;
  }>(),
  {
    src: undefined,
    asset: null,
    size: 240,
    autoplay: false,
    muted: true,
    policy: 'exclusive',
  },
);

const emit = defineEmits<{
  (e: 'play'): void;
  (e: 'pause'): void;
  (e: 'ended'): void;
  (e: 'error', message: string): void;
}>();

const { t } = useI18n();

const videoRef = ref<HTMLVideoElement | null>(null);
const localMuted = ref(props.muted);

const effectiveSrc = computed(() => props.src || props.asset?.playbackUrl || '');

const {
  isPlaying,
  currentTime,
  duration,
  playbackRate,
  errorMessage,
  togglePlay: controlledTogglePlay,
  cycleSpeed: controlledCycleSpeed,
  mediaEvents,
} = useControlledMediaElement({
  elementRef: videoRef,
  policy: props.policy,
  durationHint: () => props.asset?.durationSeconds ?? null,
  getTrack: () => ({
    kind: 'video_note',
    src: effectiveSrc.value,
    title: props.asset?.originalName || 'Video note',
    durationHint: props.asset?.durationSeconds ?? undefined,
    asset: props.asset,
  }),
  onPlay: () => emit('play'),
  onPause: () => emit('pause'),
  onEnded: () => emit('ended'),
  onError: (msg) => emit('error', msg),
});

function cycleSpeed(e: MouseEvent): void {
  e.stopPropagation();
  controlledCycleSpeed([1, 1.5, 2]);
}

// SVG Circular Progress calculation
const radius = computed(() => props.size / 2 - 4);
const circumference = computed(() => 2 * Math.PI * radius.value);
const strokeDashoffset = computed(() => {
  if (!duration.value || duration.value <= 0) return circumference.value;
  const ratio = Math.min(1, Math.max(0, currentTime.value / duration.value));
  return circumference.value * (1 - ratio);
});

function togglePlayback(): void {
  if (!videoRef.value || errorMessage.value) return;
  if (!isPlaying.value) {
    // Unmute on explicit user interaction
    localMuted.value = false;
  }
  controlledTogglePlay();
}
</script>

<template>
  <div
    class="app-video-note-player"
    :style="{ width: `${size}px`, height: `${size}px` }"
    tabindex="0"
    role="button"
    :aria-label="isPlaying ? t('media.pause') : t('media.play')"
    @click="togglePlayback"
    @keydown.space.prevent="togglePlayback"
  >
    <!-- SVG Circular Progress Ring -->
    <svg
      class="app-video-note-player__ring"
      :width="size"
      :height="size"
    >
      <circle
        class="app-video-note-player__ring-bg"
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        stroke-width="3"
      />
      <circle
        class="app-video-note-player__ring-progress"
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        stroke-width="3"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="strokeDashoffset"
      />
    </svg>

    <!-- Circular Cropped Video -->
    <div class="app-video-note-player__media">
      <video
        v-if="effectiveSrc"
        ref="videoRef"
        :src="effectiveSrc"
        playsinline
        :muted="localMuted"
        class="app-video-note-player__video"
        @loadstart="mediaEvents.loadstart"
        @canplay="mediaEvents.canplay"
        @waiting="mediaEvents.waiting"
        @playing="mediaEvents.playing"
        @play="mediaEvents.play"
        @pause="mediaEvents.pause"
        @timeupdate="mediaEvents.timeupdate"
        @loadedmetadata="mediaEvents.loadedmetadata"
        @ended="mediaEvents.ended"
        @error="mediaEvents.error"
      />
    </div>

    <!-- Play/Pause Overlay indicator when paused -->
    <div
      v-if="!isPlaying"
      class="app-video-note-player__overlay"
    >
      <div class="app-video-note-player__play-badge">
        <svg
          class="app-video-note-player__icon"
          viewBox="0 0 24 24"
          fill="currentColor"
        >
          <path d="M8 5v14l11-7z" />
        </svg>
      </div>
    </div>

    <!-- Speed cycle badge when playing -->
    <button
      v-if="isPlaying"
      type="button"
      class="app-video-note-player__speed"
      :aria-label="`${t('media.speed')}: ${playbackRate}x`"
      @click="cycleSpeed"
    >
      {{ playbackRate }}x
    </button>
  </div>
</template>

<style scoped>
.app-video-note-player {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  cursor: pointer;
  user-select: none;
  background-color: var(--surface-primary, #000);
  box-shadow: var(--shadow-soft, 0 4px 16px rgba(0, 0, 0, 0.25));
  transition:
    transform var(--motion-control-duration) var(--motion-linear),
    background-color var(--theme-transition);
}

.app-video-note-player:hover {
  transform: scale(1.02);
}

.app-video-note-player:focus-visible {
  outline: 2px solid var(--accent, #00d2ff);
  outline-offset: 4px;
}

.app-video-note-player__ring {
  position: absolute;
  top: 0;
  left: 0;
  transform: rotate(-90deg);
  pointer-events: none;
  z-index: 2;
}

.app-video-note-player__ring-bg {
  fill: none;
  stroke: rgba(255, 255, 255, 0.15);
}

.app-video-note-player__ring-progress {
  fill: none;
  stroke: var(--accent, #00d2ff);
  stroke-linecap: round;
  transition:
    stroke-dashoffset var(--motion-fast-duration) var(--motion-linear),
    stroke var(--theme-transition);
}

.app-video-note-player__media {
  width: calc(100% - 10px);
  height: calc(100% - 10px);
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000;
}

.app-video-note-player__video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.app-video-note-player__overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.35);
  z-index: 3;
}

.app-video-note-player__play-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.3);
}

.app-video-note-player__icon {
  width: 24px;
  height: 24px;
  margin-left: 2px;
}

.app-video-note-player__speed {
  position: absolute;
  bottom: 8px;
  z-index: 4;
  border: none;
  border-radius: var(--radius-card-s, 4px);
  padding: 2px 6px;
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 500);
  cursor: pointer;
  transition:
    background-color var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-linear);
}

.app-video-note-player__speed:hover {
  background: rgba(0, 0, 0, 0.85);
  transform: scale(1.08);
}
</style>
