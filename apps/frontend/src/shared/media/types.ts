import type { MediaAsset, MediaKind } from '@/shared/ui/media/types';

export type MediaSessionPolicy = 'exclusive' | 'independent';

export interface MediaControllerTrack {
  id: string;
  kind: MediaKind;
  src: string;
  title?: string;
  artist?: string;
  artwork?: string;
  durationHint?: number;
  mimeType?: string;
  asset?: MediaAsset | null;
}

export interface MediaQueueItem {
  id: string;
  kind: MediaKind;
  src: string;
  title?: string;
  artist?: string;
  artwork?: string;
  durationHint?: number;
  mimeType?: string;
}

export interface MediaInstanceControls {
  id: string;
  policy: MediaSessionPolicy;
  getTrack: () => MediaControllerTrack;
  play: () => Promise<void> | void;
  pause: () => void;
  stop?: () => void;
  seek?: (seconds: number) => void;
  setVolume?: (volume: number) => void;
  setMuted?: (muted: boolean) => void;
  setPlaybackRate?: (rate: number) => void;
}

export interface MediaControllerState {
  activeId: string | null;
  activeTrack: MediaControllerTrack | null;
  playing: boolean;
  loading: boolean;
  buffering: boolean;
  currentTime: number;
  duration: number;
  buffered: number;
  volume: number;
  muted: boolean;
  playbackRate: number;
  error: string | null;
  queue: MediaQueueItem[];
  queueIndex: number;
}
