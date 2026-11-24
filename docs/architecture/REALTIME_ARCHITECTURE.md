# PLATFORM — REALTIME ARCHITECTURE

**Status:** Target Architectural Specification & Transport Baseline  
**Milestone:** PLATFORM_08_A / PLATFORM_08_B  
**Author:** Principal Software Architect & Media Systems Engineer  

---

## 1. Principles & Hard Constraints

1. **PostgreSQL is the Sole Transactional Source of Truth:**
   - Realtime websocket/Centrifugo transport is strictly an ephemeral notification pipeline.
   - A client MUST NEVER publish messages or state mutations directly to WebSocket channels to mutate backend state.
   - All state mutations (`sendMessage`, `startThread`, `readThread`, `submitHomework`) MUST be persisted to PostgreSQL first, generating outbox records or transactional event notifications.

2. **Decoupled Transport:**
   - The application MUST continue to function via standard REST polling when Realtime is unavailable, offline, or blocked by corporate proxies.
   - Realtime events act as "hints" to invalidate cache, fetch fresh data, or append validated payloads.

3. **Multi-Tenancy & Authorization Boundaries:**
   - Channel naming strictly encapsulates tenant isolation: `org:{orgId}:person:{personId}` and `org:{orgId}:thread:{threadId}`.
   - Channel subscriptions MUST be authenticated via short-lived JWT signed by Core backend with claims specifying allowed channel masks and expiration.
   - Revocation of memberships or guardian links immediately invalidates channel subscription tokens.

---

## 2. Event Catalog & Taxonomy

| Event Type | Channel | Payload | Client Action |
|:---|:---|:---|:---|
| `chat.message_created` | `org:{orgId}:thread:{threadId}` | `{ id, threadId, senderPersonId, clientKey, createdAt }` | Deduplicate by `clientKey`/`id`, append to UI if active |
| `chat.read_cursor` | `org:{orgId}:thread:{threadId}` | `{ threadId, personId, lastSeenMessageId, readAt }` | Update unread counter & peer receipt checks |
| `chat.typing` | `org:{orgId}:thread:{threadId}` | `{ threadId, personId, expiresAt }` | Ephemeral indicator (3s TTL, not persisted) |
| `media.processing_done` | `org:{orgId}:person:{personId}` | `{ assetId, status: "READY", variants }` | Replace placeholder thumbnail with ready player |
| `education.homework_reviewed`| `org:{orgId}:student:{studentId}` | `{ assignmentId, submissionId, decision }` | Invalidate homework cache, toast notification |
| `security.access_revoked` | `org:{orgId}:person:{personId}` | `{ targetType: "guardian", targetId }` | Terminate active chat channels with 403 redirect |

---

## 3. Reliability & Recovery

- **Connection Lifecycle:**
  - Auto-reconnect with exponential backoff and jitter (500ms → 1s → 2s → 4s ... max 30s).
  - Page Visibility API (`document.hidden`): disconnect or suspend heartbeat polling when backgrounded for > 60s; resume and perform delta-sync (`offset=0` or `since={lastMessageAt}`) on focus.
- **Missed Event Recovery:**
  - Every connection recovery triggers a REST sync query passing the maximum `created_at` timestamp known to the client.
- **Zero Double-Processing:**
  - Client state stores maintain a set of recently processed message IDs (LRU 500) to discard duplicate events gracefully.
