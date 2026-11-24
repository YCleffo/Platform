# PLATFORM_08 — Current State Baseline

**Date:** 2026-11-24  
**Git SHA:** `dd878c4d` (main)  
**Local uncommitted changes:** yes — EDUCATION_07 work (migrations 017–019, messaging module, parent threading, student portal)

---

## Infrastructure

| Layer | Technology |
|-------|-----------|
| Backend runtime | PHP 8.4, Yii 3 |
| Primary database | PostgreSQL (schemas: `core`, `education`, `storage`, `device_management`, `platform_runtime`, `public`) |
| Cache / queues | Redis (ephemeral; not source of truth) |
| File storage | SeaweedFS / S3-compatible (actor-bound direct transfers, immutable objects) |
| Frontend | Vue 3 + TypeScript + Vite + Pinia + Vue Router |
| Design system | `tokens.css`, Inter (locally hosted), 393×852 mobile canonical |
| Auth | Argon2id passwords, Google/Telegram OIDC, WebAuthn Level 3, MFA, M2M service credentials |
| CI | GitHub Actions (`.github/workflows/ci.yml`) |
| Containers | Docker Compose: `apps`, `postgres`, `redis`, `mailpit`, `seaweedfs` |
| Background | `backend-scheduler` (Yii console), `backend-email-worker`, `control-scheduler`, `control-outbox-worker` |
| Reverse proxy | Caddy (embedded in app container) |
| Email (dev) | Mailpit |

---

## Module Boundaries (Current)

### Core
**Owns:** `Account`, `Person`, `Organization`, memberships, RBAC roles/permissions, authentication (password, OIDC, WebAuthn, MFA, passkeys), sessions, audit log, privacy/erasure, settings, feature flags, module registry, outbox/inbox event tables, notifications policy, communication queue.  
**Schemas:** `core.*`, `platform_runtime.*`  
**Depends on:** nothing domain-specific  
**Sub-domains:** Account, AccountSettings, Audit, Auth, Communication, Consent, Geo, Internationalization, Module, Network, Notification, Organization, Person, Presence, Privacy, Rbac, Security, Settings, Time  
**Status:** ✅ **IMPLEMENTED** (mature, well-tested)

### Education
**Owns:** teacher profiles, groups, students (references Core Person), lessons, attendance, results, journal, homework, curriculum, activities, activity attempts, messaging threads & messages, read cursors, materials (file metadata), student identity/invitations, achievements.  
**Schemas:** `education.*` (17 migrations applied, 018–019 uncommitted)  
**Depends on:** Core (Account, Person, RBAC, Auth, Storage SDK, Privacy interfaces)  
**Key uncommitted state:** migration 017 (messaging enhancements: `context_type`, `context_id`, `last_activity_at`), 018 (parent thread context), 019 (message idempotency — schema exists but not yet enforced at service layer)  
**Status:** ✅ **IMPLEMENTED** (EDUCATION_07 complete; some items PARTIAL — see Feature Matrix)

### Storage
**Owns:** S3/SeaweedFS object lifecycle, presigned URL issuance, student document versions, manifests, conflict resolution, short-lived upload tickets.  
**Schemas:** `storage.*`  
**Depends on:** Core (Auth, Person, Privacy)  
**Status:** ✅ **IMPLEMENTED** (STORAGE_02; shared S3 SDK factory, actor-bound transfers)

### DeviceManagement
**Owns:** device records, one-time registration codes, device sessions (strictly ordered state machine), one active session per device.  
**Schemas:** `device_management.*`  
**Depends on:** Core (Account, Auth)  
**Status:** ✅ **IMPLEMENTED**

### Commercial (Control Plane)
**Owns:** subscriptions, plans, billing, payments (YooKassa), dunning, refunds, receipts, entitlement documents (signed, versioned), control users.  
**Schemas:** separate Control Plane DB  
**Depends on:** Core (M2M service auth for entitlement delivery)  
**Status:** ✅ **IMPLEMENTED**

### PlatformRuntime
**Owns:** background job status signals, entitlement sync state.  
**Schemas:** `platform_runtime.*`  
**Depends on:** Core  
**Status:** ✅ **IMPLEMENTED**

### Chat *(target standalone module)*
**Owns:** conversations, participants, messages, reactions, read cursors, pins, drafts — domain-agnostic.  
**Schemas:** none yet (messaging currently embedded in `education.*`)  
**Depends on:** Core, Storage  
**Status:** ❌ **MISSING** — prototype exists as Education-embedded teacher/student/parent threading

### Media *(target standalone module)*
**Owns:** `MediaAsset`, `MediaVariant`, `MediaProcessingJob`, `MediaUploadSession` — asset lifecycle, variant generation, processing queue.  
**Schemas:** none  
**Depends on:** Core, Storage  
**Status:** ❌ **MISSING**

### Realtime *(target transport layer)*
**Owns:** event fan-out transport (WebSocket/SSE), presence signals — NOT a source of truth.  
**Depends on:** Core (auth), Chat  
**Status:** ❌ **MISSING** — currently replaced by 4-second polling in Education messenger

---

## Identified Gaps vs PLATFORM_08 Requirements

| Gap | Current workaround | Impact |
|-----|--------------------|--------|
| **Chat as standalone module** | Messaging embedded in Education, SQL-coupled | Cannot reuse across domains; prevents Chat extraction |
| **`context_id IS NULL` leak (P0 security)** | Migration 017 adds column but filtering not enforced in all query paths | Parent A may see Parent B's threads if `context_id` NULL |
| **No `client_key` idempotency** | Migration 019 schema exists but service layer not enforced | Duplicate messages on retry/network error |
| **Cursor/keyset pagination** | Offset-only pagination on message lists | Deep history becomes slow; no stable cursor for real-time sync |
| **Media module absent** | Education `FileStore` handles materials (PDF/PNG/JPEG/WebP ≤10 MiB only) | No voice, video, image variants, processing pipeline |
| **Voice messages** | None | PLATFORM_08 requirement unmet |
| **Video notes** | None | PLATFORM_08 requirement unmet |
| **Global audio player (frontend)** | None | PLATFORM_08 requirement unmet |
| **Rich message composer** | Text-only with safe linkification | No attachments from composer |
| **Realtime transport** | 4s polling (tab-visible only) | Latency, battery waste, no push on mobile |
| **Message reactions** | None | PLATFORM_08 requirement unmet |
| **Message replies (threading)** | None | PLATFORM_08 requirement unmet |
| **Message edit / delete** | None | PLATFORM_08 requirement unmet |
| **Homework media attachments** | Files only via Education FileStore | No inline images or voice in homework |
| **Tabular journal** | List view in `JournalView.vue` | Full grade-book grid not implemented |
| **Activity plugin registry** | 2 hardcoded handlers (`platform_typing`, one other) | No dynamic plugin registration |
| **Notifications: push / WebPush** | Email + in-app polling | No mobile push or WebPush |
| **Search** | None across messages or content | PLATFORM_08 requirement unmet |
