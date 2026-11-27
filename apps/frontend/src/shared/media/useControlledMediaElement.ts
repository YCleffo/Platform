import { computed, onBeforeUnmount, ref, useId, watch, type Ref } from 'vue';
import { mediaController } from './mediaController';
import type { MediaControllerTrack, MediaSessionPolicy } from './types';

export interface UseControlledMediaOptions {
  elementRef: Ref<HTMLMediaElement | null>;
  getTrack: () => Omit<MediaControllerTrack, 'id'>;
  policy?: MediaSessionPolicy;
  durationHint?: () => number | null | undefined;
  onPlay?: () => void;
  onPause?: () => void;
  onEnded?: () => void;
  onTimeUpdate?: (currentTime: number) => void;
  onError?: (message: string) => void;
}

export function useControlledMediaElement(options: UseControlledMediaOptions) {
  const instanceId = `media_${useId()}`;
  const policy: MediaSessionPolicy = options.policy ?? 'exclusive';

  const isPlaying = ref(false);
  const isLoading = ref(false);
  const isBuffering = ref(false);
  const currentTime = ref(0);
  const duration = ref(options.durationHint?.() ?? 0);
  const bufferedRatio = ref(0);
  const volume = ref(mediaController.state.volume);
  const muted = ref(mediaController.state.muted);
  const playbackRate = ref(mediaController.state.playbackRate);
  const errorMessage = ref<string | null>(null);

  const isActive = computed(() => mediaController.state.activeId === instanceId);

  const progressRatio = computed(() => {
    if (!duration.value || duration.value <= 0) return 0;
    return Math.min(1, Math.max(0, currentTime.value / duration.value));
  });

  const unregister = mediaController.registerInstance({
    id: instanceId,
    policy,
    getTrack: () => ({ id: instanceId, ...options.getTrack() }),
    play: async () => {
      const el = options.elementRef.value;
      if (!el) return;
      errorMessage.value = null;
      el.volume = volume.value;
      el.muted = muted.value;
      el.playbackRate = playbackRate.value;
      await el.play();
    },
    pause: () => {
      const el = options.elementRef.value;
      if (el && !el.paused) {
        el.pause();
      }
      isPlaying.value = false;
    },
    stop: () => {
      const el = options.elementRef.value;
      if (el) {
        el.pause();
        el.currentTime = 0;
      }
      isPlaying.value = false;
      currentTime.value = 0;
    },
    seek: (seconds: number) => {
      const el = options.elementRef.value;
      if (!el) return;
      const target = duration.value > 0 ? Math.min(duration.value, Math.max(0, seconds)) : Math.max(0, seconds);
      el.currentTime = target;
      currentTime.value = target;
    },
    setVolume: (v: number) => {
      volume.value = v;
      if (options.elementRef.value) {
        options.elementRef.value.volume = v;
      }
    },
    setMuted: (m: boolean) => {
      muted.value = m;
      if (options.elementRef.value) {
        options.elementRef.value.muted = m;
      }
    },
    setPlaybackRate: (rate: number) => {
      playbackRate.value = rate;
      if (options.elementRef.value) {
        options.elementRef.value.playbackRate = rate;
      }
    },
  });

  function togglePlay(): void {
    if (policy === 'exclusive') {
      mediaController.toggle(instanceId);
    } else {
      const el = options.elementRef.value;
      if (!el) return;
      if (isPlaying.value) {
        el.pause();
      } else {
        void el.play();
      }
    }
  }

  function seekToRatio(ratio: number): void {
    const clamped = Math.min(1, Math.max(0, ratio));
    if (!duration.value || duration.value <= 0) return;
    const target = clamped * duration.value;
    if (options.elementRef.value) {
      options.elementRef.value.currentTime = target;
    }
    currentTime.value = target;
    if (policy === 'exclusive' && isActive.value) {
      mediaController.reportState(instanceId, { currentTime: target });
    }
  }

  function seekBy(deltaSeconds: number): void {
    const target = Math.max(
      0,
      duration.value > 0 ? Math.min(duration.value, currentTime.value + deltaSeconds) : currentTime.value + deltaSeconds,
    );
    if (options.elementRef.value) {
      options.elementRef.value.currentTime = target;
    }
    currentTime.value = target;
    if (policy === 'exclusive' && isActive.value) {
      mediaController.reportState(instanceId, { currentTime: target });
    }
  }

  function setVolume(v: number): void {
    if (policy === 'exclusive') {
      mediaController.setVolume(v);
    } else {
      volume.value = Math.min(1, Math.max(0, v));
      if (options.elementRef.value) options.elementRef.value.volume = volume.value;
    }
  }

  function toggleMute(): void {
    if (policy === 'exclusive') {
      mediaController.toggleMute();
    } else {
      muted.value = !muted.value;
      if (options.elementRef.value) options.elementRef.value.muted = muted.value;
    }
  }

  function cycleSpeed(rates: readonly number[] = [1, 1.5, 2]): number {
    if (policy === 'exclusive') {
      return mediaController.cyclePlaybackRate(rates);
    }
    const idx = rates.indexOf(playbackRate.value);
    const next = rates[(idx + 1) % rates.length] ?? 1;
    playbackRate.value = next;
    if (options.elementRef.value) options.elementRef.value.playbackRate = next;
    return next;
  }

  function handleLoadStart(): void {
    isLoading.value = true;
    errorMessage.value = null;
    mediaController.reportState(instanceId, { loading: true, error: null });
  }

  function handleCanPlay(): void {
    isLoading.value = false;
    isBuffering.value = false;
    mediaController.reportState(instanceId, { loading: false, buffering: false });
  }

  function handleWaiting(): void {
    isBuffering.value = true;
    mediaController.reportState(instanceId, { buffering: true });
  }

  function handlePlaying(): void {
    isPlaying.value = true;
    isLoading.value = false;
    isBuffering.value = false;
    mediaController.reportState(instanceId, {
      playing: true,
      loading: false,
      buffering: false,
    });
  }

  function handlePlay(): void {
    isPlaying.value = true;
    errorMessage.value = null;
    mediaController.reportState(instanceId, { playing: true, error: null });
    options.onPlay?.();
  }

  function handlePause(): void {
    isPlaying.value = false;
    mediaController.reportState(instanceId, { playing: false });
    options.onPause?.();
  }

  function handleTimeUpdate(): void {
    const el = options.elementRef.value;
    if (!el) return;
    currentTime.value = el.currentTime;
    if (el.buffered && el.buffered.length > 0 && duration.value > 0) {
      const end = el.buffered.end(el.buffered.length - 1);
      bufferedRatio.value = Math.min(1, Math.max(0, end / duration.value));
    }
    mediaController.reportState(instanceId, {
      currentTime: el.currentTime,
      buffered: bufferedRatio.value,
    });
    options.onTimeUpdate?.(el.currentTime);
  }

  function handleLoadedMetadata(): void {
    const el = options.elementRef.value;
    if (!el) return;
    el.volume = volume.value;
    el.muted = muted.value;
    el.playbackRate = playbackRate.value;
    if (Number.isFinite(el.duration) && el.duration > 0) {
      duration.value = el.duration;
      mediaController.reportState(instanceId, { duration: el.duration });
    }
    isLoading.value = false;
    mediaController.reportState(instanceId, { loading: false });
  }

  function handleEnded(): void {
    isPlaying.value = false;
    currentTime.value = 0;
    mediaController.reportState(instanceId, { playing: false, currentTime: 0 });
    options.onEnded?.();
  }

  function handleError(): void {
    isPlaying.value = false;
    isLoading.value = false;
    isBuffering.value = false;
    const el = options.elementRef.value;
    const code = el?.error?.code;
    const msg = code ? `Media error (${code})` : 'Failed to play media file';
    errorMessage.value = msg;
    mediaController.reportState(instanceId, {
      playing: false,
      loading: false,
      buffering: false,
      error: msg,
    });
    options.onError?.(msg);
  }

  watch(
    () => options.durationHint?.(),
    (hint) => {
      if (hint && Number.isFinite(hint) && hint > 0 && duration.value <= 0) {
        duration.value = hint;
      }
    },
  );

  onBeforeUnmount(() => {
    const el = options.elementRef.value;
    if (el) {
      el.pause();
      el.removeAttribute('src');
      el.load();
    }
    unregister();
  });

  return {
    instanceId,
    isActive,
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
    mediaEvents: {
      loadstart: handleLoadStart,
      canplay: handleCanPlay,
      waiting: handleWaiting,
      playing: handlePlaying,
      play: handlePlay,
      pause: handlePause,
      timeupdate: handleTimeUpdate,
      loadedmetadata: handleLoadedMetadata,
      ended: handleEnded,
      error: handleError,
    },
  };
}
