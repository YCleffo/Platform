<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useControlledMediaElement } from '@/shared/media/useControlledMediaElement';
import type { MediaSessionPolicy } from '@/shared/media/types';
import AudioWaveform from './AudioWaveform.vue';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    waveformBars?: number[];
    autoplay?: boolean;
    showDownload?: boolean;
    showVolume?: boolean;
    compact?: boolean;
    durationHint?: number;
    title?: string;
    policy?: MediaSessionPolicy;
  }>(),
  {
    src: undefined,
    asset: null,
    waveformBars: undefined,
    autoplay: false,
    showDownload: false,
    showVolume: true,
    compact: false,
    durationHint: undefined,
    title: undefined,
    policy: 'exclusive',
  },
);

const emit = defineEmits<{
  (e: 'play'): void;
  (e: 'pause'): void;
  (e: 'ended'): void;
  (e: 'timeupdate', currentTime: number): void;
  (e: 'error', message: string): void;
}>();

const { t } = useI18n();

const audioRef = ref<HTMLAudioElement | null>(null);
const extractedBars = ref<number[] | null>(null);

const effectiveSrc = computed(() => props.src || props.asset?.playbackUrl || '');
const effectiveBars = computed(() => props.waveformBars || extractedBars.value || undefined);

const {
  isPlaying,
  isLoading,
  isBuffering,
  currentTime,
  duration,
  progressRatio,
  muted,
  playbackRate,
  errorMessage,
  togglePlay,
  seekToRatio,
  seekBy,
  toggleMute,
  cycleSpeed,
  mediaEvents,
} = useControlledMediaElement({
  elementRef: audioRef,
  policy: props.policy,
  durationHint: () => props.durationHint ?? props.asset?.durationSeconds ?? null,
  getTrack: () => ({
    kind: props.compact ? 'voice' : 'audio',
    src: effectiveSrc.value,
    title: props.title || props.asset?.originalName || t('media.voiceMessage'),
    durationHint: props.durationHint ?? props.asset?.durationSeconds ?? undefined,
    asset: props.asset,
  }),
  onPlay: () => emit('play'),
  onPause: () => emit('pause'),
  onEnded: () => emit('ended'),
  onTimeUpdate: (time) => emit('timeupdate', time),
  onError: (msg) => emit('error', msg),
});

async function extractWaveformPeaks(url: string, barCount = 36): Promise<void> {
  if (typeof window === 'undefined' || !url) return;
  const AudioCtx =
    window.AudioContext ||
    (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
  if (!AudioCtx) return;
  try {
    const res = await fetch(url);
    if (!res.ok) return;
    const arrayBuffer = await res.arrayBuffer();
    const ctx = new AudioCtx();
    const audioBuffer = await ctx.decodeAudioData(arrayBuffer);
    const channelData = audioBuffer.getChannelData(0);
    const blockSize = Math.max(1, Math.floor(channelData.length / barCount));
    const peaks: number[] = [];
    for (let i = 0; i < barCount; i++) {
      const start = i * blockSize;
      let sum = 0;
      for (let j = 0; j < blockSize && start + j < channelData.length; j++) {
        sum += Math.abs(channelData[start + j] || 0);
      }
      peaks.push(sum / blockSize);
    }
    const max = Math.max(...peaks, 0.001);
    await ctx.close();
    extractedBars.value = peaks.map((p) => Math.min(1, Math.max(0.12, p / max)));
  } catch {
    extractedBars.value = null;
  }
}

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${s < 10 ? '0' : ''}${s}`;
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.target instanceof HTMLInputElement) return;
  if (event.key === ' ') {
    event.preventDefault();
    togglePlay();
  } else if (event.key === 'ArrowLeft') {
    event.preventDefault();
    seekBy(-5);
  } else if (event.key === 'ArrowRight') {
    event.preventDefault();
    seekBy(5);
  } else if (event.key.toLowerCase() === 'm') {
    event.preventDefault();
    toggleMute();
  }
}

watch(
  effectiveSrc,
  (src) => {
    extractedBars.value = null;
    if (src && !props.waveformBars) {
      void extractWaveformPeaks(src);
    }
  },
  { immediate: true },
);
</script>

<template>
  <div
    class="app-audio-player"
    :class="{
      'app-audio-player--compact': compact,
      'app-audio-player--error': Boolean(errorMessage),
    }"
    tabindex="0"
    @keydown="handleKeydown"
  >
    <audio
      v-if="effectiveSrc"
      ref="audioRef"
      :src="effectiveSrc"
      :autoplay="autoplay"
      preload="metadata"
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

    <button
      type="button"
      class="app-audio-player__play-btn"
      :aria-label="isPlaying ? t('media.pause') : t('media.play')"
      :disabled="!effectiveSrc || Boolean(errorMessage)"
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
        :bars="effectiveBars"
        @seek="seekToRatio"
      />
      <div class="app-audio-player__meta">
        <span class="app-audio-player__time">
          <template v-if="errorMessage">
            {{ t('media.playbackError') }}
          </template>
          <template v-else-if="isBuffering">
            {{ t('media.buffering') }}
          </template>
          <template v-else-if="isLoading && duration === 0">
            {{ t('media.loading') }}
          </template>
          <template v-else>
            {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
          </template>
        </span>
        <div class="app-audio-player__actions">
          <button
            v-if="showVolume"
            type="button"
            class="app-audio-player__mute-btn"
            :aria-label="muted ? t('media.unmute') : t('media.mute')"
            @click="toggleMute"
          >
            {{ muted ? '🔇' : '🔊' }}
          </button>
          <button
            type="button"
            class="app-audio-player__speed-btn"
            :aria-label="`${t('media.speed')}: ${playbackRate}x`"
            @click="cycleSpeed()"
          >
            {{ playbackRate }}x
          </button>
        </div>
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
        <line
          x1="12"
          y1="15"
          x2="12"
          y2="3"
        />
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
  outline: none;
}

.app-audio-player:focus-visible {
  box-shadow: 0 0 0 2px var(--accent, #00d2ff);
}

.app-audio-player--error {
  border-color: var(--danger, #ef4444);
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
  transition:
    transform var(--theme-transition),
    opacity var(--theme-transition);
}

.app-audio-player__play-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.app-audio-player__play-btn:hover:not(:disabled) {
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
  gap: 6px;
}

.app-audio-player__actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.app-audio-player__time {
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 400);
  color: var(--text-secondary, #9ba3af);
  font-variant-numeric: tabular-nums;
}

.app-audio-player__mute-btn,
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
  transition:
    background-color var(--theme-transition),
    color var(--theme-transition);
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
