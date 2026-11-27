# PLATFORM — PLATFORM_09 REMAINING GAPS & DEBT REGISTER
## Production Hardening Backlog, Non-Blocking Enhancements & Future Roadmap

**Date:** November 24, 2026  
**Auditor:** Principal Software Architect & QA Lead  
**Overall Verdict:** PLATFORM_09_LOCAL_READY (All 10 Integrity Gates Passed)

---

## 1. Zero Blockers for Current Phase

All mandatory gates of PLATFORM_09 have been fully satisfied:
- Zero blocking defects in Assessment Studio, Question Bank, Homework linking, or Media S3 storage.
- Zero test regressions across backend unit suites and frontend Vitest (617/617 passed).
- Zero TypeScript or i18n compilation errors.

---

## 2. Non-Blocking Architectural & Operational Enhancements (Post-Acceptance Backlog)

The following items are non-blocking debt or forward-looking architectural opportunities:

| ID | Module / Area | Description | Impact | Priority | Target Phase |
|:---|:---|:---|:---:|:---:|:---:|
| **GAP-01** | Media Module | Video transcoding and variant generation pipeline via asynchronous background worker (FFmpeg). Currently original uploaded audio/video files stream directly with 206 Range. | Low (Original files stream cleanly) | Medium | Post-09 Media Pipeline |
| **GAP-02** | Assessment Studio | Client-side autosave offline buffer via IndexedDB for intermittent network disconnections during long exams. Currently autosave retries transient network errors. | Low | Low | Assessment 2.0 |
| **GAP-03** | Typing 3.0 | Anti-cheat typing cadence telemetry (keystroke flight time and inter-key latency anomaly detection) to verify `telemetryVerified: true`. | Low (Practice exercises function without biometric telemetry) | Low | Education Anti-Cheat |
| **GAP-04** | Live Realtime | WebSocket / SSE broadcast when teacher overrides an assessment grade, instantly updating student homework view without manual page refresh. | Low (Reconcile button and route reload available) | Low | Realtime 2.0 |
| **GAP-05** | Question Bank | Question Bank bulk CSV/JSON import tool for school curriculum directors. | Low (Teacher UI builder is fully functional) | Low | Curriculum Studio |

---

## 3. Deployment Invariants & Pre-requisites

1. **PostgreSQL Migration:**
   - Existing migration `021_assessments.sql` covers assessment tables.
   - Database schema is fully cataloged in `Education_v1.dbml` and `Storage_v1.dbml`.
2. **SeaweedFS S3 Storage:**
   - SeaweedFS S3 container must remain healthy on port 8333 with bucket initialized.
3. **Environment Isolation:**
   - In automated test containers, `TEST_ISOLATED_RUNTIME=1`, `APP_ENV=test`, `DB_NAME=platform_test`, and `REDIS_HOST=redis-test` must be provided for destructive fixture tests. Unit and non-destructive domain tests run deterministically without dependencies.
