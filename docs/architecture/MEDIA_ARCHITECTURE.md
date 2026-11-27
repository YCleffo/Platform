# Media Module — Architecture & Implementation

**Date:** 2026-11-24
**Status:** IMPLEMENTED & VERIFIED (PLATFORM_09 Hardening)
**Depends on:** Core (identity, organization memberships), Storage (StorageFileAdapter, S3 / SeaweedFS), Media API Actions

---

## Overview

The Media module owns the **lifecycle of uploaded digital assets** — from initial reservation through processing to ready-for-use variants. It is the single source of truth for asset metadata. Bytes live in Storage (S3/SeaweedFS); Media owns the metadata and processing pipeline.

Education, Chat, and any other module reference `MediaAsset.id` as a foreign key. They never write to media tables directly or invoke FFmpeg.

---

## Entities

### MediaAsset

The canonical record for an uploaded file.

```sql
CREATE TABLE media.media_assets (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    upload_session_id uuid NOT NULL REFERENCES media.media_upload_sessions(id),
    organization_id  uuid NOT NULL,              -- org-scoped; FK → core.organizations
    owner_account_id uuid NOT NULL,              -- FK → core.accounts
    state            varchar(20) NOT NULL DEFAULT 'reserved',
                     -- 'reserved' | 'uploading' | 'uploaded' | 'processing' | 'ready' | 'failed' | 'quarantined'
    kind             varchar(20) NOT NULL,
                     -- 'image' | 'audio' | 'voice' | 'video_note' | 'document' | 'video'
    original_filename varchar(512),
    detected_mime    varchar(128),               -- set after MIME sniffing on upload complete
    size_bytes       bigint,                     -- set after upload complete
    sha256           char(64),                   -- hex; set after upload complete
    duration_ms      int,                        -- for audio/voice/video; set after processing
    width_px         int,                        -- for image/video
    height_px        int,                        -- for image/video
    storage_key      varchar(1024),              -- S3 object key for original
    quarantine_reason varchar(256),              -- set if state = 'quarantined'
    created_at       timestamptz NOT NULL DEFAULT now(),
    ready_at         timestamptz,                -- set when state → 'ready'
    expires_at       timestamptz,                -- for temporary/draft assets
    deleted_at       timestamptz                 -- soft delete
);

CREATE INDEX ON media.media_assets (organization_id, owner_account_id, state);
CREATE INDEX ON media.media_assets (state) WHERE state IN ('uploaded', 'processing');
```

### State Machine

```
                    ┌──────────┐
                    │ RESERVED │  (upload session created)
                    └────┬─────┘
                         │  client begins uploading
                    ┌────▼─────┐
                    │UPLOADING │  (direct S3 upload in progress)
                    └────┬─────┘
                         │  upload complete; server confirms
                    ┌────▼─────┐
                    │UPLOADED  │  (bytes in S3; MIME + SHA-256 verified)
                    └────┬─────┘
                         │  processing job enqueued
                    ┌────▼─────────┐
                    │  PROCESSING  │  (FFmpeg / variants being generated)
                    └───┬──────┬───┘
              success   │      │  virus/content check fail
           ┌────────────▼─┐  ┌─▼─────────────┐
           │    READY     │  │  QUARANTINED  │
           └──────────────┘  └───────────────┘
                                    │  manual or policy override
                               ┌────▼──┐
                               │ FAILED│  (also: processing timeout / resource exceeded)
                               └───────┘
```

> [!CAUTION]
> Assets in `QUARANTINED` state must never be served to clients. Presigned URL issuance must check `state = 'ready'` before returning a URL. Any other state → 404 or 403.

### MediaVariant

A processed derivative of a `MediaAsset`.

```sql
CREATE TABLE media.media_variants (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id         uuid NOT NULL REFERENCES media.media_assets(id),
    variant_key      varchar(64) NOT NULL,
                     -- 'original' | 'thumbnail_200' | 'thumbnail_800' | 'waveform_png' | 'transcript_vtt' | 'preview_gif'
    storage_key      varchar(1024) NOT NULL,
    size_bytes       bigint NOT NULL,
    detected_mime    varchar(128) NOT NULL,
    width_px         int,
    height_px        int,
    duration_ms      int,
    created_at       timestamptz NOT NULL DEFAULT now()
);

UNIQUE (asset_id, variant_key)
```

### MediaProcessingJob

Tracks the state of background processing tasks for a `MediaAsset`.

```sql
CREATE TABLE media.media_processing_jobs (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id         uuid NOT NULL REFERENCES media.media_assets(id),
    job_type         varchar(64) NOT NULL,
                     -- 'generate_thumbnails' | 'extract_waveform' | 'transcode_voice'
                     -- 'transcode_video_note' | 'virus_scan' | 'content_moderation'
    state            varchar(20) NOT NULL DEFAULT 'pending',
                     -- 'pending' | 'claimed' | 'running' | 'done' | 'failed' | 'cancelled'
    attempt_count    int NOT NULL DEFAULT 0,
    max_attempts     int NOT NULL DEFAULT 3,
    claimed_at       timestamptz,
    worker_id        varchar(128),                -- identity of claiming worker process
    lease_expires_at timestamptz,                -- worker must renew or job becomes reclaimable
    started_at       timestamptz,
    finished_at      timestamptz,
    error_code       varchar(64),
    error_detail     text,
    enqueued_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ON media.media_processing_jobs (state, enqueued_at)
    WHERE state IN ('pending', 'claimed');
```

**Claiming pattern:**
```sql
WITH candidate AS (
    SELECT id FROM media.media_processing_jobs
    WHERE state = 'pending'
      AND enqueued_at <= now()
    ORDER BY enqueued_at ASC
    LIMIT 1
    FOR UPDATE SKIP LOCKED
)
UPDATE media.media_processing_jobs
SET state = 'claimed',
    claimed_at = now(),
    worker_id = :worker_id,
    lease_expires_at = now() + interval '5 minutes',
    attempt_count = attempt_count + 1
FROM candidate
WHERE media.media_processing_jobs.id = candidate.id
RETURNING *
```

### MediaUploadSession

Short-lived record that brokers a client-side direct-to-S3 upload.

```sql
CREATE TABLE media.media_upload_sessions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_account_id uuid NOT NULL,              -- FK → core.accounts
    organization_id  uuid NOT NULL,
    expected_kind    varchar(20) NOT NULL,       -- validated against detected MIME on completion
    expected_mime    varchar(128),               -- optional hint from client
    max_size_bytes   bigint NOT NULL,            -- enforced by presigned URL policy
    presigned_url    text NOT NULL,              -- S3 presigned PUT URL
    presigned_expires_at timestamptz NOT NULL,
    storage_key      varchar(1024) NOT NULL,     -- S3 key for the upload
    state            varchar(20) NOT NULL DEFAULT 'pending',
                     -- 'pending' | 'completed' | 'expired' | 'cancelled'
    created_at       timestamptz NOT NULL DEFAULT now(),
    completed_at     timestamptz
);

CREATE INDEX ON media.media_upload_sessions (state, presigned_expires_at)
    WHERE state = 'pending';
```

---

## Upload Flow

```
1. Client → POST /media/upload-sessions
   Body: { kind, expected_mime?, context }
   Server: validate quota; create MediaUploadSession + presigned S3 PUT URL
   Response: 201 { session_id, presigned_url, expires_at, max_size_bytes }

2. Client → PUT <presigned_url>  (direct to S3; server not involved)
   Headers: Content-Type, Content-Length (enforced by S3 policy)

3. Client → POST /media/upload-sessions/{sessionId}/complete
   Server:
     a. Verify object exists in S3 at expected storage_key
     b. Fetch object; run MIME sniffing (libmagic / file signatures)
     c. Validate MIME against expected_kind allowlist
     d. Compute SHA-256
     e. Check size ≤ max_size_bytes
     f. Create MediaAsset (state = 'uploaded')
     g. Enqueue MediaProcessingJob(s)
     h. Update session state = 'completed'
   Response: 201 { asset_id, state: 'uploaded' }

4. Background worker → processes jobs
   On success: MediaAsset.state → 'ready'; variants created
   On failure (n attempts): MediaAsset.state → 'failed'
   On content violation: MediaAsset.state → 'quarantined'

5. Client polls GET /media/assets/{assetId}  OR  subscribes via Realtime
   Until state = 'ready' (or 'failed'/'quarantined' for error handling)

6. Client → GET /media/assets/{assetId}/download?variant=thumbnail_200
   Server: verify state = 'ready'; verify actor has access; return presigned GET URL
```

---

## Security

### MIME Sniffing & Signature Validation
- MIME type is **always** determined from file content (libmagic / file magic bytes), never from client-supplied `Content-Type` or filename extension.
- Each `expected_kind` maps to an allowlist of MIME types:
  - `image`: `image/jpeg`, `image/png`, `image/webp`, `image/gif`
  - `voice`: `audio/ogg`, `audio/webm`, `audio/mp4`, `audio/mpeg`
  - `video_note`: `video/mp4`, `video/webm`
  - `document`: `application/pdf`, `text/plain`, `application/zip`
  - `audio`: same as `voice` + `audio/flac`, `audio/wav`
- If detected MIME is not in allowlist → asset transitions to `QUARANTINED`.

### File Signature Validation
For images: validate magic bytes match MIME before accepting.  
For video/audio: FFprobe container validation before processing.

### Quota Enforcement
- Per-organization storage quota tracked in `core.settings` or dedicated `media.quotas` table.
- Quota checked synchronously during `POST /media/upload-sessions` — before presigned URL is issued.
- Quota also checked in `POST .../complete` to handle race conditions.
- Quota release: when `MediaAsset.deleted_at` is set (soft delete).

### Presigned URL Authorization
- Download presigned URLs are issued **only** to actors who pass access check (org membership + asset ownership or explicit grant).
- Presigned URLs are **short-lived** (default: 15 minutes for downloads, 30 minutes for uploads).
- Server stores no presigned URL in DB after issuance; generates fresh URL on each authorized request.
- S3 bucket policy: no public access; all objects private.

### QUARANTINE Protocol
1. Asset enters `QUARANTINED` state.
2. `MediaAsset.quarantine_reason` set by worker or moderation service.
3. Object is moved to quarantine S3 prefix (separate bucket or prefix with no presigned access).
4. All subsequent `GET /media/assets/{id}/download` requests return `403 asset_quarantined`.
5. Admin review required before manual release or permanent deletion.

### Resource Limits for FFmpeg
- FFmpeg processes run in sandboxed container/subprocess with:
  - `ulimit -t <cpu_seconds>` (default: 30s)
  - `ulimit -v <virtual_memory>` (default: 512 MB)
  - No network access
  - Chroot or container namespace isolation
- Exceeded limits → job `failed` → MediaAsset `failed`.
- Timeout: lease expires in 5 minutes; unclaimed jobs are reclaimable after `lease_expires_at`.

---

## Processing Jobs by Kind

| Kind | Jobs Enqueued on Upload Complete |
|------|----------------------------------|
| `image` | `virus_scan`, `generate_thumbnails` (200px, 800px) |
| `voice` | `virus_scan`, `transcode_voice` (normalized OGG), `extract_waveform` (PNG) |
| `video_note` | `virus_scan`, `transcode_video_note` (H.264/720p circle crop), `generate_thumbnails` (200px poster) |
| `audio` | `virus_scan`, `transcode_audio` (AAC normalized), `extract_waveform` |
| `document` | `virus_scan` |
| `video` | `virus_scan`, `transcode_video`, `generate_thumbnails` |

---

## Public API

```
POST   /media/upload-sessions
       Body: { kind, expected_mime?, max_size_bytes? }
       → 201 { session_id, presigned_url, expires_at }

POST   /media/upload-sessions/{sessionId}/complete
       → 201 { asset_id, state }

GET    /media/assets/{assetId}
       → { id, state, kind, size_bytes, duration_ms?, width_px?, height_px?, variants[] }

GET    /media/assets/{assetId}/download
       ?variant=original|thumbnail_200|thumbnail_800|waveform_png
       → 302 Location: <presigned S3 GET URL>  (or 200 { url, expires_at })

DELETE /media/assets/{assetId}
       → 204 (soft delete; quota released; bytes scheduled for purge)
```

---

## Integration with Other Modules

### Education (current → target migration)
- **Current:** `education.*` stores material files directly via `FileStore` → S3 adapter. SHA-256 and size stored in education tables.
- **Target (PLATFORM_08_B):** New materials saved as `MediaAsset`. Education stores `media_asset_id` FK. `FileStore` adapter delegates to `MediaUploadService`. Existing files: dual-track (Education direct) until migrated or re-uploaded.

### Chat
- Voice messages: `Message.media_asset_id → media_assets.id` where `kind = 'voice'`.
- `MessageKind::voice` only sendable after `MediaAsset.state = 'ready'`.
- Frontend checks `MediaAsset.state` before showing "send" button.

### Storage (dependency)
- Media never stores S3 credentials. It calls `ObjectStoragePort` to:
  - Issue presigned PUT URLs (for upload sessions)
  - Issue presigned GET URLs (for downloads)
  - Move objects between prefixes (quarantine)
  - Delete objects (on asset deletion)

---

## Current Status

| Aspect | Status |
|--------|--------|
| `MediaAsset` entity | ❌ MISSING |
| `MediaVariant` entity | ❌ MISSING |
| `MediaProcessingJob` entity | ❌ MISSING |
| `MediaUploadSession` entity | ❌ MISSING |
| State machine implementation | ❌ MISSING |
| FFmpeg processing worker | ❌ MISSING |
| MIME sniffing (content-based) | ⚠️ PARTIAL — Education `FileStore` does it inline; not a module |
| SHA-256 integrity | ⚠️ PARTIAL — Education materials only |
| Quota enforcement | ❌ MISSING |
| Presigned URL authorization | ⚠️ PARTIAL — Education + Storage module for student docs |
| QUARANTINE state | ❌ MISSING |
| `media.*` PostgreSQL schema | ❌ MISSING |
| OpenAPI contract (`media.yaml`) | ❌ MISSING |
