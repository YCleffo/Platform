# PLATFORM_11 ARCHITECTURE OVERVIEW

## 1. Modular Decoupled Boundaries

```
+-------------------------------------------------------------------------+
|                           apps/frontend (Vue 3 / TS)                    |
|  shared/realtime/realtimeClient.ts <---> modules/activity/*             |
|  modules/chat/*                    <---> modules/notifications/*        |
+----------------------------+-----------------------------+--------------+
                             | HTTPS (/api/*)              | WSS/WS (/ws)
+----------------------------v-----------------------------v--------------+
|                  FrankenPHP / Caddy (apps/backend/docker)               |
|  handle /ws*  -> 127.0.0.1:8084 (App\Realtime\WebSocketServer)          |
|  handle *     -> Yii 3 HTTP Actions (Auth, Chat, Notifications, Act.)   |
+------+------------------------------+------------------------------+----+
       |                              |                              |
+------v--------+            +--------v--------+            +--------v----+
| PostgreSQL 18 |            |   Redis Pub/Sub |            | SeaweedFS   |
| - activity    |            | - channels      |            | S3 media    |
| - chat        |            | - presence      |            |             |
| - realtime    |            | - typing        |            |             |
| - notifications            +-----------------+            +-------------+
+---------------+
```

## 2. Database Catalogs (PostgreSQL 18)

- [Activity_v1.dbml](file:///c:/Platform/docs/database/activity/Activity_v1.dbml) — 8 tables, 9 foreign keys (`plugins`, `items`, `versions`, `assignments`, `attempts`, `attempt_events`, `question_bank`, `module_migrations`).
- [Chat_v1.dbml](file:///c:/Platform/docs/database/chat/Chat_v1.dbml) — 8 tables, 7 foreign keys.
- [Realtime_v1.dbml](file:///c:/Platform/docs/database/realtime/Realtime_v1.dbml) — 3 tables (`outbox_events`, `connections`, `module_migrations`).
- [Notifications_v1.dbml](file:///c:/Platform/docs/database/notifications/Notifications_v1.dbml) — 4 tables.

## 3. Attempt Lifecycle & CAS Concurrency

1. **Creation (`startAttempt`)**: Resolves published `activity.items.active_version_id`, checks `maxAttempts`, enforces assignment `opens_at` / `due_at` / `allow_late`, generates seeded `exercise` server-side via `ActivityPluginInterface::generateExercise()`, stores private `_answerKey` / `_answerKeys` inside `draft_state`, strips them on any public `IN_PROGRESS` read, inserts `attempts` at `revision = 1`, records `CREATED -> IN_PROGRESS` in `activity.attempt_events`, and emits `activity.attempt.started` via Transactional Outbox.
2. **Draft Sync (`saveDraft`)**: Acquires `FOR UPDATE` row lock, verifies participant identity and active deadline, compares `revision` (returning `409 revision_conflict` on mismatch), and atomically increments `revision`. Client [draftStorage.ts](file:///c:/Platform/apps/frontend/src/modules/activity/draftStorage.ts) clones answers to plain objects for IndexedDB resilience across offline tabs and serializes draft/submit calls via `draftInFlight` in [ActivityRunnerView.vue](file:///c:/Platform/apps/frontend/src/modules/activity/ActivityRunnerView.vue).
3. **Evaluation (`submitAttempt` / `reviewAttempt`)**: Runs authoritative server-side grading inside `ActivityPluginInterface::evaluate()`, transitions `IN_PROGRESS` to `PENDING_REVIEW` (if `requiresManualReview = true`, e.g., `long_text`) or `EVALUATED`, publishes WS event & teacher/student notifications.
