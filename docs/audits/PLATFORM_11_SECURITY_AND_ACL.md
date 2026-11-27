# PLATFORM_11 SECURITY & ACCESS CONTROL MATRIX

| Surface / Control | Enforced Rule | Observed Verification |
| --- | --- | --- |
| Multi-tenant Activity Reads (`GET /api/activity/items/{id}`) | Scoped by `organization_id` and active org membership (`activity.use`) | `P11-ENGINE-01`: Outsider in Org B receives `404` on Org A activity |
| Private Answer Key Protection | `_answerKey`, `_answerKeys`, `_explanations` stripped whenever state is `CREATED`/`IN_PROGRESS` or user lacks `activity.create`/`activity.review` | `P11-ENGINE-01` & `Platform10And11DomainTest`: `_answerKeys` undefined in student attempt payload |
| Client Telemetry Integrity (`TypingActivityPlugin`) | Server recalculates WPM & accuracy from `promptText`/`typedText`; explicitly marks `telemetryVerified: false` for client-reported timing & backspaces | `Platform10And11DomainTest::testTypingPluginEvaluatesRussianEnglishAndZenModesOnServer` |
| Optimistic Locking (CAS Revisions) | `saveDraft` & `submitAttempt` reject stale `revision` with HTTP `409 revision_conflict` | `P11-ENGINE-01` & `E2E-05` (`409` verified) |
| WebSocket Ticket Security | HMAC-signed short-lived tickets bound to `accountId`, `personId`, active `core.auth_sessions` row, and `organizationId` | `P10-SEC-01`: Forged ticket rejected `401`; cross-org ticket rejected `403` |
| Chat Channel & Media ACL | `DefaultChatContextPolicy`, `EducationChatContextPolicy`, and `ChatMediaAccessAuthorizer` verify room membership and active org status | `P10-API-01`, `P10-E2E-REALTIME`, `E2E-04` (`403` across outsider/unrelated actors, `401` forged media ticket) |
| PostgreSQL Least Privilege | Runtime role `platform_core_runtime` granted explicit schema/table/sequence permissions in migrations `001_*` | Verified live requests executing under runtime DB connections |
