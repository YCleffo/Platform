<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AudioWaveform from './AudioWaveform.vue';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    waveformBars?: number[];
    autoplay?: boolean;
    showDownload?: boolean;
  }>(),
  {
    src: undefined,
    asset: null,
    waveformBars: undefined,
    autoplay: false,
    showDownload: false,
  },
);

const emit = defineEmits<{
  (e: 'play'): void;
  (e: 'pause'): void;
  (e: 'ended'): void;
  (e: 'timeupdate', currentTime: number): void;
}>();

const { t } = useI18n();

const audioRef = ref<HTMLAudioElement | null>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(props.asset?.durationSeconds ?? 0);
const playbackRate = ref(1);
const speeds = [1, 1.5, 2];

const effectiveSrc = computed(() => {
  return props.src || props.asset?.playbackUrl || '';
});

const progressRatio = computed(() => {
  if (!duration.value || duration.value <= 0) return 0;
  return Math.min(1, Math.max(0, currentTime.value / duration.value));
});

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${s < 10 ? '0' : ''}${s}`;
}

function togglePlay(): void {
  const el = audioRef.value;
  if (!el) return;
  if (isPlaying.value) {
    el.pause();
  } else {
    void el.play();
  }
}

function handleTimeUpdate(): void {
  const el = audioRef.value;
  if (!el) return;
  currentTime.value = el.currentTime;
  emit('timeupdate', el.currentTime);
}

function handleLoadedMetadata(): void {
  const el = audioRef.value;
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

function handleSeek(ratio: number): void {
  const el = audioRef.value;
  if (!el || !duration.value) return;
  const target = ratio * duration.value;
  el.currentTime = target;
  currentTime.value = target;
}

function cycleSpeed(): void {
  const idx = speeds.indexOf(playbackRate.value);
  const next = speeds[(idx + 1) % speeds.length] ?? 1;
  playbackRate.value = next;
  if (audioRef.value) {
    audioRef.value.playbackRate = next;
  }
}

watch(effectiveSrc, () => {
  currentTime.value = 0;
  isPlaying.value = false;
  if (props.asset?.durationSeconds) {
    duration.value = props.asset.durationSeconds;
  }
});

onBeforeUnmount(() => {
  if (audioRef.value) {
    audioRef.value.pause();
    audioRef.value.src = '';
  }
});
</script>

<template>
  <div class="app-audio-player">
    <audio
      v-if="effectiveSrc"
      ref="audioRef"
      :src="effectiveSrc"
      preload="metadata"
      @play="isPlaying = true; emit('play')"
      @pause="isPlaying = false; emit('pause')"
      @timeupdate="handleTimeUpdate"
      @loadedmetadata="handleLoadedMetadata"
      @ended="handleEnded"
    />

    <button
      type="button"
      class="app-audio-player__play-btn"
      :aria-label="isPlaying ? t('media.pause') : t('media.play')"
      @click="togglePlay"
    >
      <svg
        v-if="!isPlaying"
        class="app-audio-player__icon"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
      >
        <path d="M8 5v14l11-7z" />
      </svg>
      <svg
        v-else
        class="app-audio-player__icon"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
      >
        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
      </svg>
    </button>

    <div class="app-audio-player__content">
      <AudioWaveform
        :progress="progressRatio"
        :bars="waveformBars"
        @seek="handleSeek"
      />
      <div class="app-audio-player__meta">
        <span class="app-audio-player__time">
          {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
        </span>
        <button
          type="button"
          class="app-audio-player__speed-btn"
          :aria-label="`${t('media.speed')}: ${playbackRate}x`"
          @click="cycleSpeed"
        >
          {{ playbackRate }}x
        </button>
      </div>
    </div>

    <a
      v-if="showDownload && effectiveSrc"
      :href="effectiveSrc"
      download
      class="app-audio-player__download-btn"
      :aria-label="t('media.download')"
    >
      <svg
        class="app-audio-player__icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="7 10 12 15 17 10" />
        <line x1="12" y1="15" x2="12" y2="3" />
      </svg>
    </a>
  </div>
</template>

<style scoped>
.app-audio-player {
  display: flex;
  align-items: center;
  gap: 10px;
  background-color: var(--surface-primary, #1e222b);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
  border-radius: var(--radius-card-m, 12px);
  padding: 8px 12px;
  min-width: 240px;
  max-width: 420px;
  box-sizing: border-box;
  color: var(--text-primary, #fff);
  box-shadow: var(--shadow-soft, 0 2px 8px rgba(0, 0, 0, 0.15));
}

.app-audio-player__play-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background-color: var(--accent, #00d2ff);
  color: #000;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform var(--theme-transition), opacity var(--theme-transition);
}

.app-audio-player__play-btn:hover {
  opacity: 0.9;
  transform: scale(1.05);
}

.app-audio-player__icon {
  width: 18px;
  height: 18px;
}

.app-audio-player__content {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 4px;
}

.app-audio-player__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.app-audio-player__time {
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 400);
  color: var(--text-secondary, #9ba3af);
}

.app-audio-player__speed-btn {
  background: var(--surface-secondary, rgba(255, 255, 255, 0.08));
  border: none;
  border-radius: var(--radius-card-s, 4px);
  padding: 2px 6px;
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 500);
  color: var(--text-secondary, #9ba3af);
  cursor: pointer;
  transition: background-color var(--theme-transition), color var(--theme-transition);
}

.app-audio-player__speed-btn:hover {
  color: var(--text-primary, #fff);
  background: var(--surface-tertiary, rgba(255, 255, 255, 0.16));
}

.app-audio-player__download-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-secondary, #9ba3af);
  cursor: pointer;
  padding: 4px;
  border-radius: var(--radius-card-s, 4px);
  transition: color var(--theme-transition);
}

.app-audio-player__download-btn:hover {
  color: var(--text-primary, #fff);
}
</style>
