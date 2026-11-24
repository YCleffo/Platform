<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    size?: number;
    autoplay?: boolean;
    muted?: boolean;
  }>(),
  {
    src: undefined,
    asset: null,
    size: 240,
    autoplay: false,
    muted: true,
  },
);

const emit = defineEmits<{
  (e: 'play'): void;
  (e: 'pause'): void;
  (e: 'ended'): void;
}>();

const { t } = useI18n();

const videoRef = ref<HTMLVideoElement | null>(null);
const isPlaying = ref(false);
const isMuted = ref(props.muted);
const currentTime = ref(0);
const duration = ref(props.asset?.durationSeconds ?? 0);

const effectiveSrc = computed(() => {
  return props.src || props.asset?.playbackUrl || '';
});

// SVG Circular Progress calculation
const radius = computed(() => props.size / 2 - 4);
const circumference = computed(() => 2 * Math.PI * radius.value);
const strokeDashoffset = computed(() => {
  if (!duration.value || duration.value <= 0) return circumference.value;
  const ratio = Math.min(1, Math.max(0, currentTime.value / duration.value));
  return circumference.value * (1 - ratio);
});

function togglePlayback(): void {
  const el = videoRef.value;
  if (!el) return;
  if (isPlaying.value) {
    el.pause();
  } else {
    // Unmute on explicit user interaction
    isMuted.value = false;
    void el.play();
  }
}

function handleTimeUpdate(): void {
  const el = videoRef.value;
  if (!el) return;
  currentTime.value = el.currentTime;
}

function handleLoadedMetadata(): void {
  const el = videoRef.value;
  if (!el) return;
  if (Number.isFinite(el.duration) && el.duration > 0) {
    duration.value = el.duration;
  }
}

function handleEnded(): void {
  isPlaying.value = false;
  currentTime.value = 0;
  emit('ended');
}

onBeforeUnmount(() => {
  if (videoRef.value) {
    videoRef.value.pause();
    videoRef.value.src = '';
  }
});
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
        :muted="isMuted"
        class="app-video-note-player__video"
        @play="
          isPlaying = true;
          emit('play');
        "
        @pause="
          isPlaying = false;
          emit('pause');
        "
        @timeupdate="handleTimeUpdate"
        @loadedmetadata="handleLoadedMetadata"
        @ended="handleEnded"
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
</style>
