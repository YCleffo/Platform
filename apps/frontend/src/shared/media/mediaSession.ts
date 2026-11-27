import type { MediaControllerTrack } from './types';

export interface MediaSessionHandlers {
  onPlay: () => void;
  onPause: () => void;
  onStop: () => void;
  onSeekBackward: (offset?: number) => void;
  onSeekForward: (offset?: number) => void;
  onSeekTo: (time: number) => void;
  onPreviousTrack?: () => void;
  onNextTrack?: () => void;
}

export function isMediaSessionSupported(): boolean {
  return typeof navigator !== 'undefined' && 'mediaSession' in navigator && Boolean(navigator.mediaSession);
}

export function updateMediaSessionMetadata(track: MediaControllerTrack | null): void {
  if (!isMediaSessionSupported()) return;
  try {
    if (!track) {
      navigator.mediaSession.metadata = null;
      return;
    }
    if (typeof MediaMetadata !== 'undefined') {
      const artwork = track.artwork ? [{ src: track.artwork }] : [];
      navigator.mediaSession.metadata = new MediaMetadata({
        title: track.title || track.asset?.originalName || 'Platform Media',
        artist: track.artist || 'Platform',
        artwork,
      });
    }
  } catch {
    // Ignore unsupported MediaMetadata environments
  }
}

export function updateMediaSessionPlaybackState(state: 'none' | 'paused' | 'playing'): void {
  if (!isMediaSessionSupported()) return;
  try {
    navigator.mediaSession.playbackState = state;
  } catch {
    // Ignore
  }
}

export function updateMediaSessionPosition(duration: number, position: number, playbackRate: number): void {
  if (!isMediaSessionSupported()) return;
  try {
    if (
      typeof navigator.mediaSession.setPositionState === 'function' &&
      Number.isFinite(duration) &&
      duration > 0 &&
      Number.isFinite(position) &&
      position >= 0 &&
      position <= duration
    ) {
      navigator.mediaSession.setPositionState({
        duration,
        position,
        playbackRate: playbackRate > 0 ? playbackRate : 1,
      });
    }
  } catch {
    // Ignore invalid position state
  }
}

export function registerMediaSessionHandlers(handlers: MediaSessionHandlers): void {
  if (!isMediaSessionSupported()) return;
  const ms = navigator.mediaSession;
  const safeSet = (action: MediaSessionAction, handler: MediaSessionActionHandler | null) => {
    try {
      ms.setActionHandler(action, handler);
    } catch {
      // Action not supported in this browser
    }
  };

  safeSet('play', () => handlers.onPlay());
  safeSet('pause', () => handlers.onPause());
  safeSet('stop', () => handlers.onStop());
  safeSet('seekbackward', (details) => handlers.onSeekBackward(details.seekOffset));
  safeSet('seekforward', (details) => handlers.onSeekForward(details.seekOffset));
  safeSet('seekto', (details) => {
    if (typeof details.seekTime === 'number') {
      handlers.onSeekTo(details.seekTime);
    }
  });
  safeSet('previoustrack', handlers.onPreviousTrack ? () => handlers.onPreviousTrack?.() : null);
  safeSet('nexttrack', handlers.onNextTrack ? () => handlers.onNextTrack?.() : null);
}

export function clearMediaSessionHandlers(): void {
  if (!isMediaSessionSupported()) return;
  const actions: MediaSessionAction[] = [
    'play',
    'pause',
    'stop',
    'seekbackward',
    'seekforward',
    'seekto',
    'previoustrack',
    'nexttrack',
  ];
  for (const action of actions) {
    try {
      navigator.mediaSession.setActionHandler(action, null);
    } catch {
      // Ignore
    }
  }
  updateMediaSessionMetadata(null);
  updateMediaSessionPlaybackState('none');
}
