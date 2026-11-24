# PLATFORM_08 — Feature Matrix

**Date:** 2026-11-24  
**Baseline SHA:** `dd878c4d` (main) + uncommitted EDUCATION_07 work  
**Legend:** ✅ IMPLEMENTED · ⚠️ PARTIAL · ❌ MISSING · 🔍 NOT_VERIFIED

---

## Messaging Core

| Feature | Component | Status | Notes |
|---------|-----------|--------|-------|
| Teacher → Teacher messaging | Education Backend | ✅ IMPLEMENTED | EDUCATION_07; teacher-scoped threads |
| Teacher → Student messaging | Education Backend | ✅ IMPLEMENTED | EDUCATION_07; student portal endpoints |
| Teacher → Parent (guardian) messaging | Education Backend | ✅ IMPLEMENTED | EDUCATION_07; guardian revocation enforced |
| Student → Teacher messaging | Education Backend | ✅ IMPLEMENTED | EDUCATION_07 |
| Parent → Teacher messaging | Education Backend | ✅ IMPLEMENTED | EDUCATION_07; `parent/children/{id}/threads` |
| Allowed recipients filter | Education Backend | ✅ IMPLEMENTED | Org + group enrollment + guardian link |
| Instant guardian revocation | Education Backend | ✅ IMPLEMENTED | Every request re-validates `ParentReadService` |
| Thread ordering by `last_activity_at` | Education Backend | ✅ IMPLEMENTED | Migration 017 index |
| Thread peer identification (name, role) | Education Backend | ✅ IMPLEMENTED | Enriched thread projection |
| Unread count / read cursor | Education Backend | ✅ IMPLEMENTED | Migration 002; `POST .../read` endpoint |
| `context_type` / `context_id` on threads | Education Backend | ✅ IMPLEMENTED | Migrations 017/018, strict child isolation in SQL & ParentReadService |
| Message idempotency (`client_key`) | Education Backend | ✅ IMPLEMENTED | Migration 019 unique constraint & service-layer deduplication |
| Cursor/keyset pagination for messages | Education Backend | ⚠️ PARTIAL | Offset-only; no stable `(created_at, id)` cursor |
| Standalone Chat module | — | ❌ MISSING | Messaging embedded in Education SQL |
| Message reactions (emoji) | Education Backend | ✅ IMPLEMENTED | Migration 020 `education.message_reactions`, toggle endpoint, full OpenAPI & UI |
| Message replies / threading | Education Backend | ✅ IMPLEMENTED | Migration 020 `reply_to_id`, self-join in store & quote banner UI |
| Message edit | — | ❌ MISSING | — |
| Message delete (soft) | — | ❌ MISSING | — |
| Message forward | — | ❌ MISSING | — |
| Pinned messages | — | ❌ MISSING | — |
| Message search | — | ❌ MISSING | — |
| Draft persistence | — | ❌ MISSING | — |
| Group / multi-party conversations | — | ❌ MISSING | Currently 1:1 threads only |

---

## Media & Attachments

| Feature | Component | Status | Notes |
|---------|-----------|--------|-------|
| File attachments (materials) | Education Backend | ✅ IMPLEMENTED | PDF, PNG, JPEG, WebP, plain text ≤10 MiB |
| Avatar upload | Education Backend | ✅ IMPLEMENTED | Images ≤2 MiB, 20 Mpx |
| MIME sniffing (content-based) | Education Backend | ✅ IMPLEMENTED | Not extension-based |
| SHA-256 integrity on download | Education Backend | ✅ IMPLEMENTED | Re-verified on every fetch |
| S3 actor-bound direct transfers | Storage | ✅ IMPLEMENTED | STORAGE_02 |
| Immutable objects in S3 | Storage | ✅ IMPLEMENTED | STORAGE_02 |
| Standalone Media module | Media Backend | ✅ IMPLEMENTED | `apps/backend/src/Media`, `media-routes.php`, `005_media_foundation.sql` |
| `MediaAsset` / `MediaVariant` entities | Media Backend | ✅ IMPLEMENTED | `MediaKind`, `MediaStatus`, `media.assets` table |
| `MediaProcessingJob` pipeline | — | ❌ MISSING | — |
| `MediaUploadSession` (resumable) | Media Backend | ✅ IMPLEMENTED | Reservation & confirmation handshake with storage bridge |
| Voice messages | Media & Education | ✅ IMPLEMENTED | `AudioRecorder.vue`, `AudioPlayer.vue`, kind `voice` in messages |
| Video notes | Media & Education | ✅ IMPLEMENTED | `VideoNotePlayer.vue`, kind `video_note` with circular preview |
| Image variants / thumbnails | — | ❌ MISSING | — |
| FFmpeg sandboxed processing | — | ❌ MISSING | — |
| Media quota enforcement | — | ❌ MISSING | Storage module has no per-actor quotas |
| Media QUARANTINE state | Media Backend | ✅ IMPLEMENTED | `MediaStatus::QUARANTINE` state machine support |
| Presigned URL authorization | Storage | ⚠️ PARTIAL | Used for S3 direct; not unified across all media types |
| Homework media attachments (inline images) | Education Backend | ✅ IMPLEMENTED | Expanded `HomeworkFiles` to audio/video/image attachments |

---

## Frontend / UI

| Feature | Component | Status | Notes |
|---------|-----------|--------|-------|
| Messenger — two-pane desktop layout | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07; `EducationMessenger.vue` |
| Messenger — mobile drill-down | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07 |
| Thread search | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07 |
| Unread badge indicators | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07 |
| Role badges (Teacher/Student/Parent) | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07 |
| Contact directory picker | Education Frontend | ✅ IMPLEMENTED | EDUCATION_07 |
| XSS-safe linkification | Education Frontend | ✅ IMPLEMENTED | HTML-entity escaping + sanitized `<a>` |
| Tab-visibility polling suspension | Education Frontend | ✅ IMPLEMENTED | `document.hidden` guard |
| 100% RU/EN i18n parity | Education Frontend | ✅ IMPLEMENTED | `check:i18n` passing |
| Design token adherence (`tokens.css`) | Education Frontend | ✅ IMPLEMENTED | `check:tokens` passing |
| Global audio player | Frontend | ✅ IMPLEMENTED | `AudioPlayer.vue`, `AudioWaveform.vue`, `MediaPlayer.vue` |
| Rich message composer (attachments) | Education Frontend | ✅ IMPLEMENTED | Voice recorder inline, reply banner, file attachments |
| Voice recording UI | Frontend | ✅ IMPLEMENTED | `AudioRecorder.vue` with WebAudio API, timer & waveform |
| Video note recording UI | Frontend | ✅ IMPLEMENTED | `VideoNotePlayer.vue` circular rendering & video player |
| Realtime updates (WebSocket/SSE) | Frontend | ⚠️ PARTIAL | 4s polling with visibility guard; WS foundation documented |
| Message reaction UI | Frontend | ✅ IMPLEMENTED | Quick reaction bar (👍, ❤️, 👏) & reaction pills in messenger |
| Message reply UI | Frontend | ✅ IMPLEMENTED | Reply quote banner & quote bubble rendering |
| Message edit/delete UI | Frontend | ❌ MISSING | — |
| Tabular journal (grade-book grid) | Education Frontend | ✅ IMPLEMENTED | Desktop matrix with sticky student column, attendance stats, view mode switcher |
| Student curriculum progress view | Education Frontend | ✅ IMPLEMENTED | `StudentCurriculumProgress.vue` |
| Parent section view | Education Frontend | ✅ IMPLEMENTED | `ParentSectionView.vue` |
| Student messages view | Education Frontend | ✅ IMPLEMENTED | `StudentMessagesView.vue` fully wired to student portal |

---

## Education Domain

| Feature | Component | Status | Notes |
|---------|-----------|--------|-------|
| Teacher profile & assignment | Education Backend | ✅ IMPLEMENTED | CLI assign command |
| Groups (create, rename, archive) | Education Backend | ✅ IMPLEMENTED | Lifecycle rules enforced |
| Students (enroll, remove, transfer) | Education Backend | ✅ IMPLEMENTED | Idempotent, history preserved |
| Lessons (schedule, complete, cancel) | Education Backend | ✅ IMPLEMENTED | Full lifecycle; overlap detection |
| Attendance recording & correction | Education Backend | ✅ IMPLEMENTED | — |
| Lesson results & revisions | Education Backend | ✅ IMPLEMENTED | Revision history preserved |
| Group journal (projections) | Education Backend | ✅ IMPLEMENTED | Filter by period/student |
| Student card | Education Backend | ✅ IMPLEMENTED | — |
| Homework lifecycle | Education Backend | ✅ IMPLEMENTED | EDUCATION_07; `HomeworkService` |
| Lesson → Homework context bridge | Education Frontend | ✅ IMPLEMENTED | `LessonView.vue` → `/homework/new?lessonId=...` |
| Student identity & invitations | Education Backend | ✅ IMPLEMENTED | One-time invite, Core Auth |
| Activity definitions & assignments | Education Backend | ✅ IMPLEMENTED | — |
| Activity attempts (idempotent finish) | Education Backend | ✅ IMPLEMENTED | `student_id` never trusted as identity proof |
| Activity plugin registry (dynamic) | Education Backend | ✅ IMPLEMENTED | `ActivityPluginInterface` + `platform-math-sprint` arithmetic challenge + dynamic plugin registry |
| Curriculum & programs | Education Backend | ✅ IMPLEMENTED | Migrations 008–009 |
| Guardian / family model | Education Backend | ✅ IMPLEMENTED | Migrations 012–013 |
| Achievements | Education Backend | ✅ IMPLEMENTED | Migration 014 |
| DST-aware scheduling | Education Backend | ✅ IMPLEMENTED | IANA timezone, DST transitions |
| Privacy / erasure (workspace) | Education Backend | ✅ IMPLEMENTED | `education.privacy_receipts`; idempotent by item ID |

---

## Infrastructure & Cross-Cutting

| Feature | Component | Status | Notes |
|---------|-----------|--------|-------|
| Core RBAC (roles, permissions) | Core | ✅ IMPLEMENTED | Modules register permission codes |
| M2M service authentication | Core | ✅ IMPLEMENTED | Hashed credentials, scopes, rotation, `last_used_at` |
| Outbox/inbox event tables | Core | ✅ IMPLEMENTED | Dedup by `(event_id, consumer)` |
| Background scheduler (Core) | Core | ✅ IMPLEMENTED | Advisory lock per job |
| Background scheduler (Control) | Control | ✅ IMPLEMENTED | — |
| Email delivery queue | Core | ✅ IMPLEMENTED | `backend-email-worker` |
| Auth cleanup job | Core | ✅ IMPLEMENTED | Batch delete with SKIP LOCKED |
| Privacy retention & erasure jobs | Core | ✅ IMPLEMENTED | Requires explicit policy |
| Realtime transport (WebSocket/SSE) | — | ❌ MISSING | — |
| WebPush / mobile push notifications | — | ❌ MISSING | Email + in-app polling only |
| Full-text search (messages, content) | — | ❌ MISSING | — |
| Rate limiting (per-actor, per-endpoint) | 🔍 NOT_VERIFIED | 🔍 NOT_VERIFIED | Not documented in architecture docs |
| Observability / distributed tracing | 🔍 NOT_VERIFIED | 🔍 NOT_VERIFIED | `infra/observability/README.md` referenced but not reviewed |
| Horizontal scaling (multi-replica) | 🔍 NOT_VERIFIED | 🔍 NOT_VERIFIED | Shared storage required; not validated |
