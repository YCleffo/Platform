import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError, NetworkError } from '@/shared/api/errors';
import { coreAdminSession } from '@/core/admin/session';
import { coreClientHeaders } from '@/core/admin/clientInstance';

export interface ReservedMediaUpload {
  id: string;
  storageKey: string;
  stagingKey: string;
  uploadUrl: string;
  expiresAt: string;
}

export interface UploadedMediaResult {
  id: string;
  status: string;
  sizeBytes: number;
  sha256: string;
  verifiedMime?: string;
}

export interface MediaAsset {
  id: string;
  kind: string;
  originalName: string;
  originalMime: string;
  verifiedMime?: string;
  sizeBytes: number;
  sha256: string;
  storageKey?: string;
  durationSeconds?: number | null;
  width?: number | null;
  height?: number | null;
  codec?: string | null;
  status: string;
  metadata?: Record<string, unknown>;
  createdAt: string;
  updatedAt?: string;
}

export interface PlaybackInfo {
  url: string;
  mime: string;
  durationSeconds: number | null;
  expiresAt: string;
}

export interface UploadProgressInfo {
  loaded: number;
  total: number;
  lengthComputable: boolean;
  percent: number | null;
}

export interface UploadBinaryOptions {
  headers?: Record<string, string>;
  signal?: AbortSignal;
  onProgress?: (info: UploadProgressInfo) => void;
  method?: 'POST' | 'PUT';
}

function createAbortError(): DOMException {
  return new DOMException('The operation was aborted.', 'AbortError');
}

function isSameOriginApiUrl(url: string): boolean {
  if (url.startsWith('/')) return true;
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.origin === window.location.origin;
  } catch {
    return false;
  }
}

function parseXhrResponse(raw: string): unknown {
  if (!raw) return undefined;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      (parsed as { status?: unknown }).status === 'success' &&
      'data' in parsed
    ) {
      return (parsed as { data: unknown }).data;
    }
    return parsed;
  } catch {
    return raw;
  }
}

function parseXhrError(status: number, raw: string): ApiError {
  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    if (
      parsed &&
      typeof parsed.error === 'object' &&
      parsed.error !== null &&
      typeof (parsed.error as { code?: unknown }).code === 'string'
    ) {
      const err = parsed.error as { code: string; message?: string; meta?: Record<string, unknown> };
      return new ApiError(status, err.code, err.message ?? 'Upload failed.', null, err.meta);
    }
    if (typeof parsed?.error === 'string' && typeof parsed?.message === 'string') {
      return new ApiError(status, parsed.error, parsed.message, null, parsed);
    }
  } catch {
    // Non-JSON response
  }
  return new ApiError(status, 'upload_failed', 'Upload failed.');
}

/**
 * Uploads binary payload with real XHR upload.onprogress events and AbortSignal cancellation.
 * Falls back cleanly to fetch if XMLHttpRequest is unavailable.
 */
export async function uploadBinaryWithProgress<T = UploadedMediaResult>(
  url: string,
  fileOrBlob: Blob,
  options: UploadBinaryOptions = {},
): Promise<T> {
  if (options.signal?.aborted) {
    throw createAbortError();
  }

  const headers: Record<string, string> = { ...(options.headers ?? {}) };
  if (!Object.keys(headers).some((k) => k.toLowerCase() === 'content-type')) {
    headers['Content-Type'] = fileOrBlob.type || 'application/octet-stream';
  }
  if (isSameOriginApiUrl(url)) {
    const token = coreAdminSession.accessToken();
    if (token && !Object.keys(headers).some((k) => k.toLowerCase() === 'authorization')) {
      headers.Authorization = `Bearer ${token}`;
    }
    for (const [key, value] of Object.entries(coreClientHeaders())) {
      if (!Object.keys(headers).some((k) => k.toLowerCase() === key.toLowerCase())) {
        headers[key] = value;
      }
    }
  }

  const method = options.method ?? 'POST';

  if (typeof XMLHttpRequest === 'undefined') {
    const response = await fetch(url, {
      method,
      body: fileOrBlob,
      headers,
      signal: options.signal,
      credentials: isSameOriginApiUrl(url) ? 'same-origin' : 'omit',
    });
    const text = await response.text();
    if (!response.ok) {
      throw parseXhrError(response.status, text);
    }
    options.onProgress?.({
      loaded: fileOrBlob.size,
      total: fileOrBlob.size,
      lengthComputable: true,
      percent: 100,
    });
    return parseXhrResponse(text) as T;
  }

  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    let settled = false;

    const cleanup = () => {
      options.signal?.removeEventListener('abort', onAbort);
    };

    const fail = (err: unknown) => {
      if (settled) return;
      settled = true;
      cleanup();
      reject(err);
    };

    const onAbort = () => {
      xhr.abort();
      fail(createAbortError());
    };

    options.signal?.addEventListener('abort', onAbort, { once: true });

    xhr.open(method, url, true);
    xhr.withCredentials = isSameOriginApiUrl(url);

    for (const [name, value] of Object.entries(headers)) {
      if (!/^(host|content-length|user-agent)$/i.test(name)) {
        xhr.setRequestHeader(name, value);
      }
    }

    if (xhr.upload) {
      xhr.upload.onprogress = (event) => {
        if (options.signal?.aborted) return;
        const knownTotal = event.lengthComputable
          ? event.total
          : fileOrBlob.size > 0
            ? fileOrBlob.size
            : 0;
        const percent =
          knownTotal > 0 ? Math.max(0, Math.min(100, Math.round((event.loaded / knownTotal) * 100))) : null;
        options.onProgress?.({
          loaded: event.loaded,
          total: knownTotal,
          lengthComputable: event.lengthComputable || knownTotal > 0,
          percent,
        });
      };
    }

    xhr.onabort = () => fail(createAbortError());
    xhr.onerror = () => fail(new NetworkError());
    xhr.onload = () => {
      if (settled) return;
      settled = true;
      cleanup();
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(parseXhrResponse(xhr.responseText) as T);
      } else {
        reject(parseXhrError(xhr.status, xhr.responseText));
      }
    };

    try {
      xhr.send(fileOrBlob);
    } catch (err) {
      fail(err);
    }
  });
}

export async function computeBlobSha256(blob: Blob): Promise<string> {
  const buffer = await blob.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

export const mediaApi = {
  async reserveUpload(
    organizationId: string,
    payload: {
      kind: 'voice' | 'video_note' | 'audio' | 'video' | 'image' | 'document';
      name: string;
      mime: string;
      sizeBytes: number;
      sha256: string;
    },
  ): Promise<ReservedMediaUpload> {
    return coreAdminHttp.request<ReservedMediaUpload>('/media/reserve', {
      method: 'POST',
      body: payload,
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async uploadBinary(
    organizationId: string,
    assetId: string,
    blob: Blob,
    sha256: string,
    options?: { signal?: AbortSignal; onProgress?: (info: UploadProgressInfo) => void },
  ): Promise<UploadedMediaResult> {
    if (options?.signal || options?.onProgress) {
      return uploadBinaryWithProgress<UploadedMediaResult>(`/api/media/upload/${encodeURIComponent(assetId)}`, blob, {
        headers: {
          'X-Education-Organization': organizationId,
          'Content-Type': blob.type || 'application/octet-stream',
          'X-Content-Sha256': sha256,
        },
        signal: options.signal,
        onProgress: options.onProgress,
      });
    }
    return coreAdminHttp.request<UploadedMediaResult>(`/media/upload/${assetId}`, {
      method: 'POST',
      body: blob,
      headers: {
        'X-Education-Organization': organizationId,
        'Content-Type': blob.type || 'application/octet-stream',
        'X-Content-Sha256': sha256,
      },
    });
  },

  async cancelUpload(
    mediaId: string,
    organizationId?: string,
  ): Promise<{ asset: MediaAsset; cancelled: boolean }> {
    return coreAdminHttp.request<{ asset: MediaAsset; cancelled: boolean }>(
      `/media/${encodeURIComponent(mediaId)}/cancel`,
      {
        method: 'POST',
        body: {},
        headers: organizationId ? { 'X-Education-Organization': organizationId } : undefined,
      },
    );
  },

  async confirmUpload(
    organizationId: string,
    assetId: string,
    metadata?: Record<string, unknown>,
  ): Promise<MediaAsset> {
    return coreAdminHttp.request<MediaAsset>(`/media/${assetId}/confirm`, {
      method: 'POST',
      body: { metadata },
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async getPlaybackUrl(
    organizationId: string,
    assetId: string,
    variant?: string,
  ): Promise<PlaybackInfo> {
    return coreAdminHttp.request<PlaybackInfo>(`/media/${assetId}/playback`, {
      method: 'GET',
      headers: { 'X-Education-Organization': organizationId },
      query: variant ? { variant } : undefined,
    });
  },

  async getAsset(organizationId: string, assetId: string): Promise<MediaAsset> {
    return coreAdminHttp.request<MediaAsset>(`/media/${assetId}`, {
      method: 'GET',
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async listAssets(
    organizationId: string,
    options?: { kind?: string; offset?: number },
  ): Promise<{ items: MediaAsset[]; limit: number; offset: number }> {
    return coreAdminHttp.request<{ items: MediaAsset[]; limit: number; offset: number }>('/media', {
      method: 'GET',
      headers: { 'X-Education-Organization': organizationId },
      query: {
        ...(options?.kind ? { kind: options.kind } : {}),
        ...(options?.offset !== undefined ? { offset: String(options.offset) } : {}),
      },
    });
  },

  async renameAsset(organizationId: string, assetId: string, name: string): Promise<MediaAsset> {
    return coreAdminHttp.request<MediaAsset>(`/media/${encodeURIComponent(assetId)}/rename`, {
      method: 'POST',
      body: { name },
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async moveAsset(
    organizationId: string,
    assetId: string,
    folderPath: string,
  ): Promise<MediaAsset> {
    return coreAdminHttp.request<MediaAsset>(`/media/${encodeURIComponent(assetId)}/move`, {
      method: 'POST',
      body: { folderPath },
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async copyAsset(
    organizationId: string,
    assetId: string,
    folderPath?: string,
  ): Promise<MediaAsset> {
    return coreAdminHttp.request<MediaAsset>(`/media/${encodeURIComponent(assetId)}/copy`, {
      method: 'POST',
      body: folderPath !== undefined ? { folderPath } : {},
      headers: { 'X-Education-Organization': organizationId },
    });
  },

  async getDownloadUrl(organizationId: string, assetId: string): Promise<string> {
    const playback = await this.getPlaybackUrl(organizationId, assetId);
    const sep = playback.url.includes('?') ? '&' : '?';
    return `${playback.url}${sep}download=1`;
  },

  /**
   * Complete end-to-end media upload flow:
   * Blob -> SHA-256 -> Reserve -> Binary Upload -> Confirm -> READY media asset.
   */
  async uploadMediaBlob(
    organizationId: string,
    blob: Blob,
    kind: 'voice' | 'video_note' | 'audio' | 'video' | 'image' | 'document',
    durationSeconds?: number,
    options?: {
      signal?: AbortSignal;
      onProgress?: (info: UploadProgressInfo) => void;
      onReserved?: (reservation: ReservedMediaUpload) => void;
    },
  ): Promise<{ id: string; url: string; mimeType: string; durationSeconds?: number }> {
    const sha256 = await computeBlobSha256(blob);
    const mime =
      blob.type ||
      (kind === 'voice'
        ? 'audio/webm'
        : kind === 'video_note'
          ? 'video/webm'
          : 'application/octet-stream');
    const ext = mime.includes('ogg')
      ? 'ogg'
      : mime.includes('mp4')
        ? 'mp4'
        : mime.includes('webm')
          ? 'webm'
          : 'bin';
    const name = `${kind}_${Date.now()}.${ext}`;

    const reservation = await this.reserveUpload(organizationId, {
      kind,
      name,
      mime,
      sizeBytes: blob.size,
      sha256,
    });
    options?.onReserved?.(reservation);

    await this.uploadBinary(organizationId, reservation.id, blob, sha256, {
      signal: options?.signal,
      onProgress: options?.onProgress,
    });

    const confirmed = await this.confirmUpload(organizationId, reservation.id, {
      durationSeconds: durationSeconds ?? null,
      mimeType: mime,
      sizeBytes: blob.size,
      sha256,
    });

    const playback = await this.getPlaybackUrl(organizationId, confirmed.id);

    return {
      id: confirmed.id,
      url: playback.url,
      mimeType: playback.mime,
      durationSeconds: durationSeconds ?? playback.durationSeconds ?? undefined,
    };
  },
};
