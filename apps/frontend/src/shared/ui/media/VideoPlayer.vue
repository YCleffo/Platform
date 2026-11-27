<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useControlledMediaElement } from '@/shared/media/useControlledMediaElement';
import type { MediaSessionPolicy } from '@/shared/media/types';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    poster?: string;
    title?: string;
    autoplay?: boolean;
    muted?: boolean;
    policy?: MediaSessionPolicy;
  }>(),
  {
    src: undefined,
    asset: null,
    poster: undefined,
    title: undefined,
    autoplay: false,
    muted: false,
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

const containerRef = ref<HTMLElement | null>(null);
const videoRef = ref<HTMLVideoElement | null>(null);
const isFullscreen = ref(false);
const isPipActive = ref(false);
const seekHintText = ref<string | null>(null);
let seekHintTimer: number | null = null;
let lastTapTime = 0;
let lastTapSide: 'left' | 'right' | null = null;

const effectiveSrc = computed(() => props.src || props.asset?.playbackUrl || '');
const displayTitle = computed(() => props.title || props.asset?.originalName || 'Video');

const {
  isPlaying,
  isLoading,
  isBuffering,
  currentTime,
  duration,
  bufferedRatio,
  progressRatio,
  volume,
  muted,
  playbackRate,
  errorMessage,
  togglePlay,
  seekToRatio,
  seekBy,
  setVolume,
  toggleMute,
  cycleSpeed,
  mediaEvents,
} = useControlledMediaElement({
  elementRef: videoRef,
  policy: props.policy,
  durationHint: () => props.asset?.durationSeconds ?? null,
  getTrack: () => ({
    kind: 'video',
    src: effectiveSrc.value,
    title: displayTitle.value,
    artwork: props.poster,
    durationHint: props.asset?.durationSeconds ?? undefined,
    asset: props.asset,
  }),
  onPlay: () => emit('play'),
  onPause: () => emit('pause'),
  onEnded: () => emit('ended'),
  onError: (msg) => emit('error', msg),
});

const canPictureInPicture = computed(() => {
  if (typeof document === 'undefined') return false;
  return Boolean(document.pictureInPictureEnabled && !videoRef.value?.disablePictureInPicture);
});

const canFullscreen = computed(() => {
  if (typeof document === 'undefined') return false;
  return Boolean(document.fullscreenEnabled);
});

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${s < 10 ? '0' : ''}${s}`;
}

function showSeekFeedback(label: string): void {
  seekHintText.value = label;
  if (seekHintTimer) window.clearTimeout(seekHintTimer);
  seekHintTimer = window.setTimeout(() => {
    seekHintText.value = null;
  }, 700);
}

function handleSeekInput(event: Event): void {
  const val = Number((event.target as HTMLInputElement).value);
  if (Number.isFinite(val)) {
    seekToRatio(val);
  }
}

function handleVolumeInput(event: Event): void {
  const val = Number((event.target as HTMLInputElement).value);
  if (Number.isFinite(val)) {
    setVolume(val);
  }
}

async function toggleFullscreen(): Promise<void> {
  const el = containerRef.value;
  if (!el || !canFullscreen.value) return;
  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
    } else if (el.requestFullscreen) {
      await el.requestFullscreen();
    }
  } catch {
    // Ignore browser fullscreen rejection
  }
}

async function togglePip(): Promise<void> {
  const video = videoRef.value;
  if (!video || !canPictureInPicture.value) return;
  try {
    if (document.pictureInPictureElement === video) {
      await document.exitPictureInPicture();
    } else if (video.requestPictureInPicture) {
      await video.requestPictureInPicture();
    }
  } catch {
    // Ignore PiP rejection
  }
}

function handleVideoTap(event: MouseEvent): void {
  const now = Date.now();
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
  const side: 'left' | 'right' = event.clientX - rect.left < rect.width / 2 ? 'left' : 'right';

  if (now - lastTapTime < 280 && lastTapSide === side) {
    const delta = side === 'left' ? -10 : 10;
    seekBy(delta);
    showSeekFeedback(delta < 0 ? '-10s' : '+10s');
    lastTapTime = 0;
    lastTapSide = null;
    return;
  }

  lastTapTime = now;
  lastTapSide = side;
  togglePlay();
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.target instanceof HTMLInputElement) return;
  switch (event.key.toLowerCase()) {
    case ' ':
    case 'k':
      event.preventDefault();
      togglePlay();
      break;
    case 'arrowleft':
      event.preventDefault();
      seekBy(-5);
      showSeekFeedback('-5s');
      break;
    case 'arrowright':
      event.preventDefault();
      seekBy(5);
      showSeekFeedback('+5s');
      break;
    case 'm':
      event.preventDefault();
      toggleMute();
      break;
    case 'f':
      event.preventDefault();
      void toggleFullscreen();
      break;
  }
}

function onFullscreenChange(): void {
  isFullscreen.value = Boolean(document.fullscreenElement === containerRef.value);
}

function onEnterPip(): void {
  isPipActive.value = true;
}

function onLeavePip(): void {
  isPipActive.value = false;
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.addEventListener('fullscreenchange', onFullscreenChange);
  }
  const v = videoRef.value;
  if (v) {
    v.addEventListener('enterpictureinpicture', onEnterPip);
    v.addEventListener('leavepictureinpicture', onLeavePip);
  }
});

onBeforeUnmount(() => {
  if (seekHintTimer) window.clearTimeout(seekHintTimer);
  if (typeof document !== 'undefined') {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
  }
  const v = videoRef.value;
  if (v) {
    v.removeEventListener('enterpictureinpicture', onEnterPip);
    v.removeEventListener('leavepictureinpicture', onLeavePip);
  }
});
</script>

<template>
  <div
    ref="containerRef"
    class="app-video-player"
    :class="{ 'app-video-player--fullscreen': isFullscreen }"
    tabindex="0"
    :aria-label="displayTitle"
    @keydown="handleKeydown"
  >
    <div
      class="app-video-player__stage"
      @click="handleVideoTap"
    >
      <video
        v-if="effectiveSrc"
        ref="videoRef"
        :src="effectiveSrc"
        :poster="poster"
        :autoplay="autoplay"
        playsinline
        preload="metadata"
        class="app-video-player__video"
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

      <div
        v-if="!effectiveSrc || errorMessage"
        class="app-video-player__state"
        role="alert"
      >
        <span>{{ errorMessage ? t('media.playbackError') : t('media.unavailable') }}</span>
      </div>

      <div
        v-else-if="isLoading || isBuffering"
        class="app-video-player__state"
        aria-live="polite"
      >
        <span>{{ isBuffering ? t('media.buffering') : t('media.loading') }}</span>
      </div>

      <div
        v-if="seekHintText"
        class="app-video-player__seek-badge"
      >
        {{ seekHintText }}
      </div>
    </div>

    <div class="app-video-player__controls">
      <button
        type="button"
        class="app-video-player__btn"
        :aria-label="isPlaying ? t('media.pause') : t('media.play')"
        :disabled="!effectiveSrc || Boolean(errorMessage)"
        @click="togglePlay"
      >
        {{ isPlaying ? '❚❚' : '▶' }}
      </button>

      <span class="app-video-player__time">
        {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
      </span>

      <div class="app-video-player__progress-wrap">
        <div
          class="app-video-player__buffered"
          :style="{ width: `${Math.round(bufferedRatio * 100)}%` }"
        />
        <input
          type="range"
          min="0"
          max="1"
          step="0.005"
          :value="progressRatio"
          class="app-video-player__scrubber"
          :aria-label="t('media.duration')"
          :disabled="!duration"
          @input="handleSeekInput"
        />
      </div>

      <button
        type="button"
        class="app-video-player__btn"
        :aria-label="muted ? t('media.unmute') : t('media.mute')"
        @click="toggleMute"
      >
        {{ muted || volume === 0 ? '🔇' : '🔊' }}
      </button>

      <input
        type="range"
        min="0"
        max="1"
        step="0.05"
        :value="muted ? 0 : volume"
        class="app-video-player__volume"
        :aria-label="t('media.volume')"
        @input="handleVolumeInput"
      />

      <button
        type="button"
        class="app-video-player__btn app-video-player__speed"
        :aria-label="`${t('media.speed')}: ${playbackRate}x`"
        @click="cycleSpeed([0.5, 1, 1.25, 1.5, 2])"
      >
        {{ playbackRate }}x
      </button>

      <button
        v-if="canPictureInPicture"
        type="button"
        class="app-video-player__btn"
        :aria-label="t('media.pictureInPicture')"
        :aria-pressed="isPipActive"
        @click="togglePip"
      >
        PiP
      </button>

      <button
        v-if="canFullscreen"
        type="button"
        class="app-video-player__btn"
        :aria-label="isFullscreen ? t('media.exitFullscreen') : t('media.fullscreen')"
        @click="toggleFullscreen"
      >
        {{ isFullscreen ? '⤓' : '⤢' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.app-video-player {
  display: flex;
  flex-direction: column;
  background: #000;
  border-radius: var(--radius-card-m, 12px);
  overflow: hidden;
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.12));
  max-width: 100%;
  outline: none;
}

.app-video-player:focus-visible {
  box-shadow: 0 0 0 2px var(--accent, #00d2ff);
}

.app-video-player--fullscreen {
  border-radius: 0;
  width: 100%;
  height: 100%;
}

.app-video-player__stage {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 180px;
  cursor: pointer;
  background: #000;
}

.app-video-player__video {
  width: 100%;
  max-height: 460px;
  display: block;
}

.app-video-player--fullscreen .app-video-player__video {
  max-height: calc(100vh - 52px);
}

.app-video-player__state {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  padding: 16px;
  text-align: center;
  font-size: 13px;
}

.app-video-player__seek-badge {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.72);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  pointer-events: none;
}

.app-video-player__controls {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--surface-primary, #141820);
  color: var(--text-primary, #fff);
  flex-wrap: wrap;
}

.app-video-player__btn {
  background: var(--surface-secondary, rgba(255, 255, 255, 0.08));
  color: var(--text-primary, #fff);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.12));
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 12px;
  cursor: pointer;
  min-height: 28px;
}

.app-video-player__btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.app-video-player__time {
  font-size: 11px;
  font-variant-numeric: tabular-nums;
  color: var(--text-secondary, #9ba3af);
  white-space: nowrap;
}

.app-video-player__progress-wrap {
  position: relative;
  flex: 1;
  min-width: 90px;
  display: flex;
  align-items: center;
}

.app-video-player__buffered {
  position: absolute;
  left: 0;
  height: 4px;
  background: rgba(255, 255, 255, 0.25);
  border-radius: 2px;
  pointer-events: none;
}

.app-video-player__scrubber {
  width: 100%;
  accent-color: var(--accent, #00d2ff);
  cursor: pointer;
  margin: 0;
}

.app-video-player__volume {
  width: 64px;
  accent-color: var(--accent, #00d2ff);
  cursor: pointer;
}

.app-video-player__speed {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 560px) {
  .app-video-player__volume {
    display: none;
  }
}
</style>
