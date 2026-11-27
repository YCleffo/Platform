# PLATFORM_11 FULL E2E VERIFICATION MATRIX

| Scenario ID | Script | Layer | Outcome | Verified Behaviors |
| --- | --- | --- | --- | --- |
| `P11-PLUGIN-01` | `e2e-platform11-full.mjs` | API + PG | PASS | 5 plugins registered; Question Bank 2.0 `ordering` + `long_text` import/archive/restore |
| `P11-ENGINE-01` | `e2e-platform11-full.mjs` | API + PG | PASS | Cross-org 404 isolation, `_answerKeys` redaction, CAS draft conflict (409), partial credit, `PENDING_REVIEW` -> manual teacher review `87.5%` |
| `P11-CHAT-INTEGRATION` | `e2e-platform11-full.mjs` | API + Outbox | PASS | All 5 activity types published, student assignment + notification, analytics, Chat `activity_card` |
| `P11-E2E-BROWSER` | `e2e-platform11-full.mjs` | Chromium + Vite + WS | PASS | Teacher Studio Publish, Chat card click -> Typing 4.0 Arena, Catalog, Math Sprint, Logic Lab & Memory Grid render with 0 `pageerror`s |
| `P11-LOAD-01` | `e2e-platform11-full.mjs` | API + Outbox + Redis | PASS | 40 concurrent messages, 0 failures (`p50=4008.43ms`, `p95=5876.16ms`, `p99=6828.65ms`) |
| `P11-WS-RECONNECT` | `e2e-platform11-full.mjs` | WebSocket + Outbox | PASS | Offline missed message recovered via `sinceSeq` replay (`Event #457`) |
| `P10-SEC-01` | `e2e-platform10-realtime.mjs` | Security | PASS | Forged WS ticket (`401`) and cross-org ticket (`403`) rejected |
| `P10-API-01` | `e2e-platform10-realtime.mjs` | API + PG | PASS | Group room, idempotency, edit, pin, server draft, FTS search, delete-for-me, notification prefs |
| `P10-E2E-REALTIME` | `e2e-platform10-realtime.mjs` | Dual Chromium + WS | PASS | Live message delivery, typing indicator, reactions, synced draft, Notifications Center (`/notifications-center`) |
| `E2E-01`..`E2E-05` | `e2e-platform09-r2.mjs` | Full Regression | PASS (5/5) | Assessment homework link, SeaweedFS S3 Voice/Video Range `206`/`416`, ACL isolation `401`/`403`, CAS concurrency `409` |
