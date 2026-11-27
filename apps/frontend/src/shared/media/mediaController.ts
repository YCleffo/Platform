import { reactive, readonly } from 'vue';
import type { Router } from 'vue-router';
import {
  clearMediaSessionHandlers,
  registerMediaSessionHandlers,
  updateMediaSessionMetadata,
  updateMediaSessionPlaybackState,
  updateMediaSessionPosition,
} from './mediaSession';
import type {
  MediaControllerState,
  MediaInstanceControls,
  MediaQueueItem,
  MediaSessionPolicy,
} from './types';

const PREFS_STORAGE_KEY = 'platform.media.prefs.v1';
const ALLOWED_RATES = [0.5, 1, 1.25, 1.5, 2] as const;

interface StoredMediaPrefs {
  volume: number;
  muted: boolean;
  playbackRate: number;
}

function loadPrefs(): StoredMediaPrefs {
  const defaults: StoredMediaPrefs = { volume: 1, muted: false, playbackRate: 1 };
  if (typeof window === 'undefined' || !window.localStorage) return defaults;
  try {
    const raw = window.localStorage.getItem(PREFS_STORAGE_KEY);
    if (!raw) return defaults;
    const parsed = JSON.parse(raw) as Partial<StoredMediaPrefs>;
    const volume =
      typeof parsed.volume === 'number' && Number.isFinite(parsed.volume)
        ? Math.min(1, Math.max(0, parsed.volume))
        : 1;
    const muted = typeof parsed.muted === 'boolean' ? parsed.muted : false;
    const playbackRate =
      typeof parsed.playbackRate === 'number' &&
      (ALLOWED_RATES as readonly number[]).includes(parsed.playbackRate)
        ? parsed.playbackRate
        : 1;
    return { volume, muted, playbackRate };
  } catch {
    return defaults;
  }
}

function savePrefs(prefs: StoredMediaPrefs): void {
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem(PREFS_STORAGE_KEY, JSON.stringify(prefs));
  } catch {
    // Ignore quota or private mode errors
  }
}

const initialPrefs = loadPrefs();

const state = reactive<MediaControllerState>({
  activeId: null,
  activeTrack: null,
  playing: false,
  loading: false,
  buffering: false,
  currentTime: 0,
  duration: 0,
  buffered: 0,
  volume: initialPrefs.volume,
  muted: initialPrefs.muted,
  playbackRate: initialPrefs.playbackRate,
  error: null,
  queue: [],
  queueIndex: -1,
});

const instances = new Map<string, MediaInstanceControls>();
const trackedObjectUrls = new Set<string>();
let sessionHandlersBound = false;
let routerUnwatch: (() => void) | null = null;

function ensureMediaSessionHandlers(): void {
  if (sessionHandlersBound) return;
  sessionHandlersBound = true;
  registerMediaSessionHandlers({
    onPlay: () => {
      if (state.activeId) void play(state.activeId);
    },
    onPause: () => pause(),
    onStop: () => stop(),
    onSeekBackward: (offset = 10) => seek(Math.max(0, state.currentTime - offset)),
    onSeekForward: (offset = 10) =>
      seek(state.duration > 0 ? Math.min(state.duration, state.currentTime + offset) : state.currentTime + offset),
    onSeekTo: (time) => seek(time),
    onPreviousTrack: () => {
      if (state.queue.length > 0 && state.queueIndex > 0) {
        void playPrevious();
      }
    },
    onNextTrack: () => {
      if (state.queue.length > 0 && state.queueIndex < state.queue.length - 1) {
        void playNext();
      }
    },
  });
}

function persistCurrentPrefs(): void {
  savePrefs({
    volume: state.volume,
    muted: state.muted,
    playbackRate: state.playbackRate,
  });
}

function registerInstance(controls: MediaInstanceControls): () => void {
  instances.set(controls.id, controls);
  // Apply current global preferences to newly registered instance
  controls.setVolume?.(state.volume);
  controls.setMuted?.(state.muted);
  controls.setPlaybackRate?.(state.playbackRate);

  return () => {
    unregisterInstance(controls.id);
  };
}

function unregisterInstance(id: string): void {
  instances.delete(id);
  if (state.activeId === id) {
    state.activeId = null;
    state.activeTrack = null;
    state.playing = false;
    state.loading = false;
    state.buffering = false;
    state.currentTime = 0;
    state.duration = 0;
    state.buffered = 0;
    state.error = null;
    updateMediaSessionMetadata(null);
    updateMediaSessionPlaybackState('none');
  }
}

function pauseAllExcept(exceptId: string | null, policy: MediaSessionPolicy = 'exclusive'): void {
  if (policy === 'independent') return;
  for (const [id, inst] of instances.entries()) {
    if (id !== exceptId && inst.policy === 'exclusive') {
      inst.pause();
    }
  }
}

async function play(id?: string): Promise<void> {
  const targetId = id ?? state.activeId;
  if (!targetId) return;
  const inst = instances.get(targetId);
  if (!inst) return;

  if (inst.policy === 'exclusive') {
    pauseAllExcept(targetId, 'exclusive');
    state.activeId = targetId;
    state.activeTrack = inst.getTrack();
    state.error = null;
    ensureMediaSessionHandlers();
    updateMediaSessionMetadata(state.activeTrack);
    updateMediaSessionPlaybackState('playing');
  }

  try {
    await inst.play();
    if (inst.policy === 'exclusive') {
      state.playing = true;
      updateMediaSessionPlaybackState('playing');
    }
  } catch (err) {
    if (inst.policy === 'exclusive') {
      state.playing = false;
      state.error = err instanceof Error ? err.message : 'Playback failed';
      updateMediaSessionPlaybackState('paused');
    }
  }
}

function pause(id?: string): void {
  const targetId = id ?? state.activeId;
  if (!targetId) return;
  const inst = instances.get(targetId);
  inst?.pause();
  if (state.activeId === targetId) {
    state.playing = false;
    updateMediaSessionPlaybackState('paused');
  }
}

function toggle(id: string): void {
  if (state.activeId === id && state.playing) {
    pause(id);
  } else {
    void play(id);
  }
}

function stop(): void {
  if (state.activeId) {
    const inst = instances.get(state.activeId);
    if (inst?.stop) {
      inst.stop();
    } else {
      inst?.pause();
      inst?.seek?.(0);
    }
  }
  state.playing = false;
  state.currentTime = 0;
  state.buffering = false;
  updateMediaSessionPlaybackState('none');
}

function seek(seconds: number): void {
  if (!state.activeId || !Number.isFinite(seconds)) return;
  const clamped = Math.max(0, state.duration > 0 ? Math.min(state.duration, seconds) : seconds);
  const inst = instances.get(state.activeId);
  inst?.seek?.(clamped);
  state.currentTime = clamped;
  updateMediaSessionPosition(state.duration, state.currentTime, state.playbackRate);
}

function seekRatio(ratio: number): void {
  if (!state.duration || state.duration <= 0) return;
  const clamped = Math.min(1, Math.max(0, ratio));
  seek(clamped * state.duration);
}

function setVolume(volume: number): void {
  const clamped = Math.min(1, Math.max(0, Number.isFinite(volume) ? volume : 1));
  state.volume = clamped;
  if (clamped > 0 && state.muted) {
    state.muted = false;
  }
  for (const inst of instances.values()) {
    inst.setVolume?.(clamped);
    inst.setMuted?.(state.muted);
  }
  persistCurrentPrefs();
}

function toggleMute(): void {
  state.muted = !state.muted;
  for (const inst of instances.values()) {
    inst.setMuted?.(state.muted);
  }
  persistCurrentPrefs();
}

function setPlaybackRate(rate: number): void {
  const validRate = (ALLOWED_RATES as readonly number[]).includes(rate) ? rate : 1;
  state.playbackRate = validRate;
  for (const inst of instances.values()) {
    inst.setPlaybackRate?.(validRate);
  }
  updateMediaSessionPosition(state.duration, state.currentTime, state.playbackRate);
  persistCurrentPrefs();
}

function cyclePlaybackRate(rates: readonly number[] = [1, 1.5, 2]): number {
  const idx = rates.indexOf(state.playbackRate);
  const next = rates[(idx + 1) % rates.length] ?? 1;
  setPlaybackRate(next);
  return next;
}

function reportState(
  id: string,
  patch: Partial<Pick<MediaControllerState, 'playing' | 'loading' | 'buffering' | 'currentTime' | 'duration' | 'buffered' | 'error'>>,
): void {
  const inst = instances.get(id);
  if (!inst || inst.policy === 'independent') return;

  if (patch.playing && state.activeId !== id) {
    pauseAllExcept(id, 'exclusive');
    state.activeId = id;
    state.activeTrack = inst.getTrack();
    ensureMediaSessionHandlers();
    updateMediaSessionMetadata(state.activeTrack);
  }

  if (state.activeId !== id) return;

  if (patch.playing !== undefined) {
    state.playing = patch.playing;
    updateMediaSessionPlaybackState(patch.playing ? 'playing' : 'paused');
  }
  if (patch.loading !== undefined) state.loading = patch.loading;
  if (patch.buffering !== undefined) state.buffering = patch.buffering;
  if (patch.currentTime !== undefined) state.currentTime = patch.currentTime;
  if (patch.duration !== undefined) state.duration = patch.duration;
  if (patch.buffered !== undefined) state.buffered = patch.buffered;
  if (patch.error !== undefined) state.error = patch.error;

  if (patch.currentTime !== undefined || patch.duration !== undefined) {
    updateMediaSessionPosition(state.duration, state.currentTime, state.playbackRate);
  }
}

function setQueue(items: MediaQueueItem[], startIndex = 0): void {
  state.queue = [...items];
  state.queueIndex = items.length > 0 ? Math.max(0, Math.min(items.length - 1, startIndex)) : -1;
}

async function playNext(): Promise<void> {
  if (state.queueIndex < 0 || state.queueIndex >= state.queue.length - 1) return;
  state.queueIndex += 1;
  const item = state.queue[state.queueIndex];
  if (item && instances.has(item.id)) {
    await play(item.id);
  }
}

async function playPrevious(): Promise<void> {
  if (state.queueIndex <= 0) return;
  state.queueIndex -= 1;
  const item = state.queue[state.queueIndex];
  if (item && instances.has(item.id)) {
    await play(item.id);
  }
}

function notifyRecordingStart(): void {
  pauseAllExcept(null, 'exclusive');
  state.playing = false;
  updateMediaSessionPlaybackState('paused');
}

function trackObjectUrl(url: string): string {
  if (url.startsWith('blob:')) {
    trackedObjectUrls.add(url);
  }
  return url;
}

function revokeObjectUrl(url: string | null | undefined): void {
  if (!url) return;
  trackedObjectUrls.delete(url);
  if (url.startsWith('blob:') && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
    try {
      URL.revokeObjectURL(url);
    } catch {
      // Ignore
    }
  }
}

function revokeAllObjectUrls(): void {
  for (const url of trackedObjectUrls) {
    if (typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
      try {
        URL.revokeObjectURL(url);
      } catch {
        // Ignore
      }
    }
  }
  trackedObjectUrls.clear();
}

function bindRouter(router: Router): () => void {
  if (routerUnwatch) {
    routerUnwatch();
  }
  routerUnwatch = router.afterEach((to, from) => {
    if (to.path !== from.path) {
      pauseAllExcept(null, 'exclusive');
      state.playing = false;
      updateMediaSessionPlaybackState('paused');
    }
  });
  return routerUnwatch;
}

function reset(): void {
  pauseAllExcept(null, 'exclusive');
  state.activeId = null;
  state.activeTrack = null;
  state.playing = false;
  state.loading = false;
  state.buffering = false;
  state.currentTime = 0;
  state.duration = 0;
  state.buffered = 0;
  state.error = null;
  state.queue = [];
  state.queueIndex = -1;
  revokeAllObjectUrls();
  clearMediaSessionHandlers();
  sessionHandlersBound = false;
}

export const mediaController = {
  state: readonly(state),
  registerInstance,
  unregisterInstance,
  play,
  pause,
  toggle,
  stop,
  seek,
  seekRatio,
  setVolume,
  toggleMute,
  setPlaybackRate,
  cyclePlaybackRate,
  reportState,
  setQueue,
  playNext,
  playPrevious,
  notifyRecordingStart,
  trackObjectUrl,
  revokeObjectUrl,
  revokeAllObjectUrls,
  bindRouter,
  reset,
};

export function useMediaController() {
  return mediaController;
}
