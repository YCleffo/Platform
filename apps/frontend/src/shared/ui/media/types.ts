export type MediaKind = 'audio' | 'voice' | 'video' | 'video_note' | 'image' | 'document';

export type MediaStatus =
  | 'RESERVED'
  | 'UPLOADING'
  | 'UPLOADED'
  | 'PROCESSING'
  | 'READY'
  | 'FAILED'
  | 'QUARANTINED'
  | 'EXPIRED'
  | 'CANCELLED';

export interface MediaVariant {
  variantKey: string;
  storageKey: string;
  mimeType: string;
  sizeBytes: number;
  width?: number | null;
  height?: number | null;
  metadata?: Record<string, unknown>;
}

export interface MediaAsset {
  id: string;
  kind: MediaKind;
  originalName: string;
  originalMime: string;
  verifiedMime?: string | null;
  sizeBytes: number;
  sha256?: string;
  storageKey?: string;
  durationSeconds?: number | null;
  width?: number | null;
  height?: number | null;
  codec?: string | null;
  status: MediaStatus;
  variants?: MediaVariant[];
  metadata?: Record<string, unknown>;
  createdAt?: string;
  playbackUrl?: string;
}

export interface PlaybackState {
  playing: boolean;
  currentTime: number;
  duration: number;
  buffered: number;
  playbackRate: number;
  muted: boolean;
  volume: number;
}
