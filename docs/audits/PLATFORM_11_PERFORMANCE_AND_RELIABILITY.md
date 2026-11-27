# PLATFORM_11 PERFORMANCE, CONCURRENCY & RELIABILITY

## 1. Concurrency & Throughput Benchmark (`P11-LOAD-01`)

Executed via `node apps/frontend/scripts/e2e-platform11-full.mjs` against real FrankenPHP + PostgreSQL 18 + Redis + Outbox relay:

- **Workload:** 40 parallel HTTP `POST /api/chat/rooms/{id}/messages` requests alternating between teacher and student tokens (creating `chat.messages`, `notifications.items`, and `realtime.outbox_events` inside transactions, then relaying to Redis).
- **Failures:** `0 / 40`
- **Observed Latency:**
  - `p50`: `4008.43 ms`
  - `p95`: `5876.16 ms`
  - `p99`: `6828.65 ms`

*(Baseline reflects single-node local Docker dev container concurrency; no errors, deadlocks, or dropped outbox events occurred.)*

## 2. WebSocket Outbox Recovery (`P11-WS-RECONNECT`)

- Client records `latestSeq` from `POST /api/realtime/ticket`.
- A message (`Offline recovery <nonce>`) is sent via HTTP while no WebSocket client is attached.
- Client opens `/ws?ticket=...` and sends `{ "type": "subscribe", "channel": "room:<id>", "sinceSeq": <seqBefore> }`.
- Server replays missed outbox event `#457` strictly after `<seqBefore>` from `realtime.outbox_events`.

## 3. PostgreSQL Backup & Restore Drill

Executed via `pg_dump -Fc` from live `platform` database (`--schema=chat --schema=realtime --schema=notifications --schema=activity`) and restored via `pg_restore` into disposable database `platform_restore_drill`:

- `activity.plugins`: 5 rows restored
- `activity.items`: 2 rows restored
- `chat.rooms`: 8 rows restored
- `chat.messages`: 11 rows restored
- `notifications.items`: 29 rows restored
- `realtime.outbox_events`: 90 rows restored
- Clean drop of `platform_restore_drill` verified with zero error.
