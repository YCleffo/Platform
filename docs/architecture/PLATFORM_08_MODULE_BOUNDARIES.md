# PLATFORM_08 — Module Boundaries (Canonical)

**Date:** 2026-11-24  
**Status:** Target architecture for PLATFORM_08 milestone series

---

## Principles

These rules extend and are subordinate to `MODULARITY.md`. All rules in `MODULARITY.md` apply unconditionally.

1. **Core is universal.** No education, chat, media, storage, or device concepts inside Core.
2. **Core never depends on optional modules.** Direction is always `Module → Core`.
3. **Chat is domain-agnostic.** It knows nothing about teachers, students, lessons, or guardians. Education uses Chat through a port/policy interface, not direct SQL.
4. **Media owns the asset lifecycle.** Education and Chat reference `MediaAsset.id` via foreign key. They do not write to media tables or invoke FFmpeg directly.
5. **Storage owns bytes.** Media and other modules request presigned URLs from Storage; they never store S3 credentials in their own tables.
6. **Realtime is transport only.** It does not store messages, threads, or presence state. It fans out events whose authoritative copy lives in Chat (messages) or Core (presence).
7. **Education never reads Chat tables directly.** It calls Chat application services or events.
8. **Module boundaries are enforced at the application layer.** Cross-module calls go through PHP interfaces (ports), not shared Yii DB connections with `JOIN` across schemas.

---

## Module Catalogue

### Core
**Owns:** `Account`, `Person`, `Organization`, memberships, RBAC, auth (all factors), sessions, audit log, privacy/consent/erasure, settings v2, feature flags, module registry, notification policy, communication outbox, M2M service credentials, outbox/inbox event tables.  
**Public interfaces:** `AccountRepository`, `PersonRepository`, `OrganizationRepository`, `RbacService`, `AuthenticationFlowService`, `SessionIssuer`, `NotificationService`, `EventOutbox`, `EventInbox`, `PrivacyContributorInterface`, `FeatureFlagService`.  
**Must NOT contain:** education entities, chat entities, media assets, device management.

### Chat *(target — currently MISSING)*
**Owns:** `Conversation`, `Participant`, `Message`, `MessageRevision`, `Reaction`, `ReadCursor`, `PinnedMessage`, `Draft`.  
**Public interfaces:** `ConversationService`, `MessageService`, `ReactionService`, `ReadCursorService`, `ChatPolicyPort` (implemented per-domain).  
**Depends on:** Core (identity/RBAC validation), Storage (media ref resolution).  
**Must NOT contain:** Education-specific access rules. Those are injected via `ChatPolicyPort`.

### Media *(target — currently MISSING)*
**Owns:** `MediaAsset`, `MediaVariant`, `MediaProcessingJob`, `MediaUploadSession`.  
**Public interfaces:** `MediaUploadService`, `MediaAssetRepository`, `MediaVariantRepository`, `MediaProcessingQueue`.  
**Depends on:** Core (actor identity, quota policy), Storage (byte storage, presigned URLs).  
**Must NOT contain:** message text, education data, device data.

### Storage
**Owns:** S3/SeaweedFS object lifecycle, presigned URL issuance, quota state, versioned student documents, manifests, conflict records, upload tickets.  
**Public interfaces:** `ObjectStoragePort` (S3 SDK factory), `PresignedUrlService`, `DocumentVersionRepository`, `UploadTicketService`.  
**Depends on:** Core (Auth, Privacy).  
**Must NOT contain:** message metadata, media variants, activity results.

### Education
**Owns:** teacher/student/group/lesson/attendance/result/journal/homework/curriculum/activity/achievement data.  
**Messaging access:** via `ChatPolicyPort` → Chat module (target). Currently: direct Education SQL (transitional).  
**Media access:** via `MediaAdapter` → Media module (target). Currently: Education `FileStore` → S3 (transitional).  
**Public interfaces:** `EducationService`, `HomeworkService`, `ParentReadService`, `ActivityRegistry`.  
**Depends on:** Core, Chat (target), Media (target), Storage (current).

### DeviceManagement
**Owns:** device records, registration codes, device sessions (strictly ordered state machine).  
**Depends on:** Core (Account, Auth).

### Commercial / Control Plane
**Owns:** subscriptions, plans, billing, payments, dunning, refunds, receipts, entitlement documents.  
**Depends on:** Core (M2M for entitlement delivery). Runs in separate process/DB.

### Realtime *(target — currently MISSING)*
**Owns:** WebSocket/SSE connection registry, event fan-out, presence signals.  
**Depends on:** Core (auth/session validation), Chat (message events to fan out).  
**Must NOT store:** messages, thread state, unread counts.

---

## Dependency Graph

### Current State (PLATFORM_07 / post-EDUCATION_07)

```
Education ──────────────────────────────────────────────────► Core
Education ──(direct SQL, transitional)──────────────────────► education.* tables (messaging embedded)
Education ──(FileStore adapter)─────────────────────────────► Storage
Storage ─────────────────────────────────────────────────────► Core
DeviceManagement ────────────────────────────────────────────► Core
Commercial ──(M2M)───────────────────────────────────────────► Core
PlatformRuntime ─────────────────────────────────────────────► Core
```

### Target State (PLATFORM_08 complete)

```
Education ───────────────────────────────────────────────────► Core
Education ──(ChatPolicyPort)─────────────────────────────────► Chat
Education ──(MediaAdapter)───────────────────────────────────► Media
Chat ─────────────────────────────────────────────────────────► Core
Chat ─────────────────────────────────────────────────────────► Storage  (media refs)
Media ────────────────────────────────────────────────────────► Core
Media ────────────────────────────────────────────────────────► Storage  (bytes)
Storage ──────────────────────────────────────────────────────► Core
Realtime ─────────────────────────────────────────────────────► Core     (auth)
Realtime ─────────────────────────────────────────────────────► Chat     (events to fan out)
DeviceManagement ─────────────────────────────────────────────► Core
Commercial ──(M2M)────────────────────────────────────────────► Core
```

**Prohibited directions (must never exist in target):**
- `Core → Education`, `Core → Chat`, `Core → Media`
- `Chat → Education` (Education-specific rules injected via port, not reverse dependency)
- `Storage → Chat`, `Storage → Media`
- `Realtime → Education` (Realtime is transport; it carries Chat events, not Education events directly)

---

## Migration Path

### PLATFORM_08_A — Foundation & Security (current sprint)
**Goal:** Fix critical security gaps in existing Education messaging; lay groundwork.

1. Enforce `context_id` isolation in all query paths (fix P0 `NULL` leak).
2. Enforce `client_key` deduplication at service layer (migration 019 schema is ready).
3. Add keyset cursor pagination to message list endpoints (replace offset).
4. Document and test revocation edge cases for guardian messaging.
5. Create `docs/architecture/CHAT_ARCHITECTURE.md` and `MEDIA_ARCHITECTURE.md` (this sprint).

**Exit criteria:** All P0 and P1 security findings resolved; cursor pagination in place; architecture docs approved.

### PLATFORM_08_B — Media Module Foundation
**Goal:** Create standalone `Media` module with upload session, asset state machine, and basic variant support.

1. Create `media` PostgreSQL schema with `media_assets`, `media_variants`, `media_processing_jobs`, `media_upload_sessions`.
2. Implement upload session flow: `RESERVED → UPLOADING → UPLOADED → PROCESSING → READY / FAILED / QUARANTINED`.
3. Wire MIME sniffing, signature validation, quota enforcement at Media layer.
4. Connect FFmpeg sandbox worker (background job) for image thumbnails only (voice/video in _C).
5. Migrate Education `FileStore` to reference `MediaAsset.id` for new uploads (backward-compatible).

**Exit criteria:** Materials saved as `MediaAsset`; Education FileStore adapter delegates to Media; existing files migrated or dual-tracked.

### PLATFORM_08_C — Chat Module Extraction
**Goal:** Extract standalone `Chat` module; Education switches to `ChatPolicyPort`.

1. Create `chat` PostgreSQL schema: `chat_conversations`, `chat_participants`, `chat_messages`, `chat_message_revisions`, `chat_reactions`, `chat_read_cursors`.
2. Implement `EducationChatPolicy implements ChatPolicyPort` — encapsulates org/group/guardian access rules.
3. Migrate Education thread/message data to `chat.*` tables.
4. Replace Education messaging SQL with calls to `ChatService`.
5. Remove Education-specific messaging tables after migration verified.

**Exit criteria:** `education.*` has no messaging tables; all 109 Education messaging API operations pass through Chat service.

### PLATFORM_08_D — Voice, Video Notes & Rich Composer
**Goal:** Add voice messages, video notes, and media attachments to Chat.

1. Add FFmpeg processing jobs for audio (voice) and video (video notes) in Media module.
2. Add `MessageKind::voice`, `MessageKind::video_note`, `MessageKind::image`, `MessageKind::document` to Chat.
3. Build Global Audio Player component in frontend.
4. Build voice recording UI (Web Audio API) in Rich Composer.
5. Build video note recording UI (MediaRecorder API) in Rich Composer.

**Exit criteria:** Voice and video note messages sendable in Education messenger; playback works across all roles.

### PLATFORM_08_E — Realtime Transport & Reactions
**Goal:** Replace polling with WebSocket/SSE; add reactions, replies, edit/delete.

1. Build Realtime service: WebSocket server, event fan-out from Chat outbox.
2. Replace 4-second polling with Realtime subscription in frontend.
3. Add message reactions (emoji picker, `chat_reactions` table).
4. Add message replies (thread reference, `reply_to_message_id`).
5. Add message edit (`chat_message_revisions`) and soft delete.
6. Add tabular journal (grade-book grid) to frontend.
7. Add dynamic activity plugin registry to Education backend.

**Exit criteria:** Sub-1s message delivery; reactions/replies/edit/delete working; journal grid implemented; activity plugin API documented and tested.
