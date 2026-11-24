# Chat Module — Target Architecture

**Date:** 2026-11-24  
**Status:** TARGET — not yet extracted; prototype embedded in Education (EDUCATION_07)  
**Planned milestone:** PLATFORM_08_C

---

## Overview

Chat is a **domain-agnostic** standalone module. It manages conversations, messages, and related state. It knows nothing about teachers, students, lessons, or guardians — those access rules are injected by callers via `ChatPolicyPort`.

The current Education messaging system (threads + messages in `education.*` schema) is the foundational prototype. PLATFORM_08_C migrates it into this canonical structure.

---

## Entities

### Conversation
Represents a conversation channel (1:1 or multi-party).

```
Conversation
  id               uuid PK DEFAULT gen_random_uuid()
  kind             varchar(20) NOT NULL  -- 'direct' | 'group' | 'channel'
  context_type     varchar(30)           -- 'education:homework' | 'education:lesson' | NULL
  context_id       uuid                  -- FK to context entity (nullable, domain-specific)
  organization_id  uuid NOT NULL         -- FK to core.organizations; conversations are org-scoped
  created_at       timestamptz NOT NULL DEFAULT now()
  last_activity_at timestamptz NOT NULL DEFAULT now()

INDEX (organization_id, last_activity_at DESC)
INDEX (context_type, context_id) WHERE context_id IS NOT NULL
```

> [!IMPORTANT]
> `context_id` isolation: when `context_type` and `context_id` are set, every query that lists conversations **must** filter by `context_id`. A NULL `context_id` must never leak into context-specific views. This was the **P0 security gap** in EDUCATION_07 migration 017.

### Participant
Represents a person's membership in a conversation.

```
Participant
  id               uuid PK
  conversation_id  uuid NOT NULL FK → Conversation
  account_id       uuid NOT NULL FK → core.accounts
  role             varchar(20) NOT NULL  -- 'member' | 'admin' | 'observer'
  added_at         timestamptz NOT NULL DEFAULT now()
  removed_at       timestamptz           -- soft removal; access denied but history preserved

UNIQUE (conversation_id, account_id) WHERE removed_at IS NULL
```

### Message
```
Message
  id               uuid PK DEFAULT gen_random_uuid()
  conversation_id  uuid NOT NULL FK → Conversation
  sender_id        uuid NOT NULL FK → core.accounts
  client_key       varchar(64) NOT NULL  -- caller-supplied idempotency key (UUID or SHA-256)
  kind             varchar(20) NOT NULL  -- 'text' | 'voice' | 'video_note' | 'image' | 'document' | 'audio' | 'system'
  body             text                  -- for kind='text'; NULL for media kinds
  media_asset_id   uuid FK → media.media_assets  -- for media kinds
  reply_to_id      uuid FK → Message    -- for threaded replies
  is_deleted       boolean NOT NULL DEFAULT false
  created_at       timestamptz NOT NULL DEFAULT now()
  edited_at        timestamptz           -- set when any revision exists

UNIQUE (conversation_id, client_key)   -- idempotency deduplication
INDEX  (conversation_id, created_at DESC, id DESC)  -- keyset cursor
```

> [!IMPORTANT]
> `client_key` **must** be enforced at the service layer with `INSERT ... ON CONFLICT (conversation_id, client_key) DO NOTHING RETURNING *`. If the row already exists, return the existing message — do not 409.

### MessageRevision
Full revision history for edited messages. Original body is preserved as revision 1 on first edit.

```
MessageRevision
  id               uuid PK
  message_id       uuid NOT NULL FK → Message
  revision_number  int NOT NULL
  body             text NOT NULL
  edited_at        timestamptz NOT NULL DEFAULT now()
  editor_id        uuid NOT NULL FK → core.accounts

UNIQUE (message_id, revision_number)
```

### Reaction
```
Reaction
  id               uuid PK
  message_id       uuid NOT NULL FK → Message
  account_id       uuid NOT NULL FK → core.accounts
  emoji            varchar(16) NOT NULL  -- e.g. '👍', '❤️'
  created_at       timestamptz NOT NULL DEFAULT now()

UNIQUE (message_id, account_id, emoji)
```

### ReadCursor
Tracks the last-read position per participant per conversation.

```
ReadCursor
  conversation_id  uuid NOT NULL FK → Conversation
  account_id       uuid NOT NULL FK → core.accounts
  last_read_message_id  uuid FK → Message
  updated_at       timestamptz NOT NULL DEFAULT now()

PRIMARY KEY (conversation_id, account_id)
```

### PinnedMessage
```
PinnedMessage
  conversation_id  uuid NOT NULL FK → Conversation
  message_id       uuid NOT NULL FK → Message
  pinned_by        uuid NOT NULL FK → core.accounts
  pinned_at        timestamptz NOT NULL DEFAULT now()

PRIMARY KEY (conversation_id, message_id)
```

### Draft
Client-side draft (server-persisted for cross-device sync).

```
Draft
  conversation_id  uuid NOT NULL FK → Conversation
  account_id       uuid NOT NULL FK → core.accounts
  body             text
  updated_at       timestamptz NOT NULL DEFAULT now()

PRIMARY KEY (conversation_id, account_id)
```

---

## API Contracts (REST)

All endpoints require `Authorization: Bearer <token>` and re-validate organization membership on every request. Responses include `Cache-Control: no-store`.

### Conversations

```
GET  /chat/conversations
     ?organization_id=<uuid>
     &cursor=<opaque>  &limit=<1-50, default 20>
     → { items: Conversation[], next_cursor: string|null }

POST /chat/conversations
     Body: { kind, context_type?, context_id?, participant_account_ids[] }
     → 201 Conversation | 409 conversation_exists (for direct with same pair)

GET  /chat/conversations/{id}
     → Conversation | 403 | 404

GET  /chat/conversations/{id}/participants
     → { items: Participant[] }

POST /chat/conversations/{id}/participants
     Body: { account_id, role }
     → 201 Participant | 403 | 409

DELETE /chat/conversations/{id}/participants/{accountId}
     → 204 | 403 | 404
```

### Messages

```
GET  /chat/conversations/{id}/messages
     ?before_cursor=<opaque>  &limit=<1-100, default 50>
     → { items: Message[], prev_cursor: string|null }

     Cursor encoding: base64( created_at ISO8601 + "," + message_id )
     SQL: WHERE (created_at, id) < (:cursor_ts, :cursor_id)
          ORDER BY created_at DESC, id DESC
          LIMIT :limit

POST /chat/conversations/{id}/messages
     Body: { client_key, kind, body?, media_asset_id?, reply_to_id? }
     → 201 Message | 200 Message (idempotent replay) | 403 | 422

PATCH /chat/conversations/{id}/messages/{messageId}
     Body: { body }
     → 200 Message | 403 not_sender | 404 | 422

DELETE /chat/conversations/{id}/messages/{messageId}
     → 204 (soft delete, is_deleted=true) | 403 | 404
```

### Reactions

```
POST   /chat/conversations/{id}/messages/{messageId}/reactions
       Body: { emoji }
       → 201 | 409 already_reacted

DELETE /chat/conversations/{id}/messages/{messageId}/reactions/{emoji}
       → 204 | 404
```

### Read Cursor

```
POST /chat/conversations/{id}/read
     Body: { last_read_message_id }
     → 204
     Constraint: only marks messages already visible to caller; ignores future messages
```

### Pinned Messages

```
GET    /chat/conversations/{id}/pins  → { items: PinnedMessage[] }
POST   /chat/conversations/{id}/pins  Body: { message_id } → 201 | 403 | 404
DELETE /chat/conversations/{id}/pins/{messageId} → 204 | 403 | 404
```

---

## Security Model

### Context-Aware Policy (`ChatPolicyPort`)

```php
interface ChatPolicyPort {
    /** Returns true iff $actorAccountId may read this conversation. */
    public function canRead(Uuid $conversationId, Uuid $actorAccountId): bool;

    /** Returns true iff $actorAccountId may send a message. */
    public function canSend(Uuid $conversationId, Uuid $actorAccountId): bool;

    /** Returns allowed recipient account IDs for a new conversation. */
    public function allowedRecipients(Uuid $actorAccountId, string $organizationId): array;

    /** Called on every request; returns false if actor's access has been revoked (e.g. guardian revocation). */
    public function isAccessStillValid(Uuid $conversationId, Uuid $actorAccountId): bool;
}
```

The Education module provides `EducationChatPolicy implements ChatPolicyPort`. This policy enforces:
- Org membership + active teacher assignment for teachers
- Enrolled group membership for students
- Active guardian linkage (re-validated on every request) for parents

### Participant Verification
1. `Participant` row must exist with `removed_at IS NULL`.
2. `ChatPolicyPort::isAccessStillValid()` called on every read/write operation.
3. If guardian relationship is revoked, `EducationChatPolicy` returns `false` → 403 on all subsequent requests.

### `context_id` Isolation (P0 fix from EDUCATION_07)
When listing conversations with a given `context_type`:
- Filter: `WHERE context_type = :type AND context_id = :id`
- Never return rows where `context_id IS NULL` in a context-scoped query
- Never return rows where `context_id != :requested_id`

---

## Pagination: Keyset Cursor

Message history uses **keyset (cursor) pagination** — not offset.

**Cursor:** `base64_encode(created_at.toISO8601() + "," + message_uuid)`

**Query pattern:**
```sql
SELECT * FROM chat_messages
WHERE conversation_id = :cid
  AND (created_at, id) < (:cursor_ts::timestamptz, :cursor_id::uuid)
  AND is_deleted = false
ORDER BY created_at DESC, id DESC
LIMIT :limit
```

**Response:**
```json
{
  "items": [...],
  "prev_cursor": "base64(oldest_item_ts,oldest_item_id)"
}
```

- `prev_cursor: null` → beginning of history reached
- Clients request `?before_cursor=<prev_cursor>` to load older messages
- Stable under concurrent inserts (no skipped/duplicated rows)

---

## Message Kinds

| Kind | `body` | `media_asset_id` | Notes |
|------|--------|-----------------|-------|
| `text` | Required | NULL | Plain text, linkified on render |
| `voice` | NULL | Required | Audio recording; `MediaVariant` provides waveform + duration |
| `video_note` | NULL | Required | Circular video note (Telegram-style) |
| `image` | Optional caption | Required | Static image; variants: thumbnail, full |
| `document` | Optional description | Required | Arbitrary file |
| `audio` | Optional caption | Required | Music/podcast (not voice memo) |
| `system` | Required | NULL | Server-generated: "X joined", "pinned message" etc. |

---

## Idempotency

```
Client generates: client_key = UUIDv4 or SHA-256(conversation_id + body + timestamp_ms)
Client sends:     POST /chat/conversations/{id}/messages  { client_key, ... }

Server:
  INSERT INTO chat_messages (..., client_key, ...)
  ON CONFLICT (conversation_id, client_key) DO NOTHING
  RETURNING *

If RETURNING returns a row   → 201 (new) or 200 (replay)
If RETURNING returns nothing → query existing row, return 200
```

> [!CAUTION]
> `client_key` deduplication MUST be enforced at the SQL layer, not in application code with a prior SELECT. Race conditions between duplicate requests require the `ON CONFLICT` constraint.

---

## Current Status (EDUCATION_07 prototype)

| Aspect | Status |
|--------|--------|
| Teacher-scoped threads (1:1) | ✅ Implemented in `education.*` schema |
| Student / parent role endpoints | ✅ Implemented (EDUCATION_07) |
| Guardian revocation enforcement | ✅ Implemented |
| `context_type` / `context_id` column | ⚠️ Column exists (migration 017); isolation not enforced in all queries — **P0** |
| `client_key` schema | ⚠️ Migration 019 schema exists; service-layer enforcement missing — **P1** |
| Keyset cursor pagination | ❌ Missing — offset only |
| Reactions, replies, edit, delete | ❌ Missing |
| Standalone `chat.*` schema | ❌ Missing — target of PLATFORM_08_C |
| `ChatPolicyPort` interface | ❌ Missing — Education SQL inline |

---

## Target: Standalone Module

After PLATFORM_08_C extraction:
- `chat.*` PostgreSQL schema, owned by Chat module
- Education references `Conversation.id` in homework/lesson context fields
- `EducationChatPolicy` lives in Education module, implements `ChatPolicyPort`
- Zero direct SQL joins between `chat.*` and `education.*` schemas
- OpenAPI contract split: `contracts/openapi/chat.yaml` (generic) + Education endpoints reference Chat conversation IDs
