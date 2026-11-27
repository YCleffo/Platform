# PLATFORM — PLATFORM_09_R2 ACCEPTANCE REPORT
## Independent Verification & Runtime Hardening

**Verdict:** `PLATFORM_09_R2_LOCAL_READY`
**Date:** 2026-11-25
**Baseline Git Commit:** `8cd4437e610f208e4a866c2affbf989c075ac034` (`main` + hardened working tree)
**Supersedes:** [PLATFORM_09_ACCEPTANCE.md](file:///c:/Platform/docs/audits/PLATFORM_09_ACCEPTANCE.md) (marked superseded due to runtime SQL, ACL, S3 integrity, and client playback defects exposed during independent audit).

---

## 1. Independent Verification Summary

Every critical blocker identified in the independent PLATFORM_09 audit was reproduced against live PostgreSQL, Redis, SeaweedFS S3, FrankenPHP/Caddy, Vite, and Headless Chromium, fixed at root cause, and proven end-to-end:

1. **Assessment SQL Schema & Concurrency (`AssessmentService.php`, `StudentAssessmentRunner.vue`, `assessmentApi.ts`, `routes.ts`):**
   - Removed non-existent `updated_at = now()` in `saveStudentAttempt()` and `p.full_name` in `listAssessmentAttempts()`.
   - Eliminated all `+ 30` second post-deadline grace windows in `startStudentAttempt()` and `resolveAndVerifyStudentAssignment()`; server deterministically locks edits after deadline and evaluates only previously DB-saved answers.
   - Enforced atomic CAS revision verification on both `saveStudentAttempt()` and `submitStudentAttempt()` (`409 revision_conflict`). Updated `StudentAssessmentRunner.vue` to sync server-authoritative answers and revision on conflict rather than overwriting newer server state.
   - Fixed real browser routing in `routes.ts` (`props` forwarding for `/student/assessments/:id`) and injected mandatory `X-Education-Organization` header across all endpoints in `assessmentApi.ts`.
   - Verified `HomeworkAttemptLinker` persistence in `education.homework_attempt_links` + `education.homework_submissions`, teacher manual score override, and confirmed parent read access.

2. **Media Lifecycle, SeaweedFS S3 Integrity, Range Streaming & ACL (`MediaService.php`, `MediaAction.php`, `EducationMediaAccessAuthorizer.php`, `S3Objects.php`, `EducationMessenger.vue`):**
   - Extracted `MediaAccessAuthorizer` adapter implemented by `EducationMediaAccessAuthorizer`, decoupling platform `Media` from Education business logic and replacing broken `message_threads` / `ha.files` / org-wide teacher bypasses with canonical `thread_members`, `homework_files`, `group_teachers`, `homework_recipients`, and confirmed `student_guardians`.
   - Enforced identical ACL verification (`assertReadAccess`) across `GET /api/media/{id}`, `GET /api/media/{id}/playback`, and `GET /api/media/stream/{id}` (supporting both Bearer auth and short-lived HMAC-SHA256 `?ticket=` with live membership/ACL re-check).
   - Hardened `confirmUpload` + binary `upload`: fail-closed (`503 storage_unavailable`) without storage, exact S3 byte size + SHA-256 + `finfo` MIME validation (with `MediaRecorder` codec parameter normalization and `audio/x-wav` support), immutable `READY` status (`409 already_ready` on parallel re-upload), and metadata (`durationSeconds`) persistence.
   - Implemented native SeaweedFS S3 byte-range seeking (`S3Objects::openRange` / `S3StorageFileAdapter::openRange`) + `Cache-Control: no-store, no-transform` preventing Caddy gzip/zstd from stripping `Content-Length`, achieving real `200`, `206` chunk/suffix/open, and `416` streaming.
   - Removed `playbackUrl` storage/trust from `EducationMessenger.vue` message metadata.

---

## 2. Quality Gate Results (Current Working Tree)

| Check | Command | Result | Scope / Metrics |
| :--- | :--- | :---: | :--- |
| **Backend Unit/Domain Tests** | `docker exec platform-backend vendor/bin/phpunit tests/Foundation/MediaServiceTest.php tests/Foundation/AssessmentDomainTest.php` | **PASS** | 26 tests, 142 assertions |
| **Static Analysis (Psalm)** | `docker exec platform-backend vendor/bin/psalm` | **PASS** | 0 errors (all modified modules verified) |
| **Code Style (PHP-CS-Fixer)** | `docker exec platform-backend vendor/bin/php-cs-fixer fix --dry-run --diff` | **PASS** | 925 files checked, 0 violations |
| **Composer Dependencies** | `docker exec platform-backend vendor/bin/composer-dependency-analyser` | **PASS** | Exit code 0 across 926 files |
| **Frontend Vitest Suite** | `npm test --prefix apps/frontend` | **PASS** | 123 files, 617 tests |
| **TypeScript / Vue-TSC** | `npm run typecheck --prefix apps/frontend` | **PASS** | 0 errors |
| **ESLint & Prettier** | `npm run lint && npm run format:check` (`apps/frontend`) | **PASS** | 0 errors |
| **Design Tokens, Styles, i18n** | `npm run check:tokens && npm run check:styles && npm run check:i18n` | **PASS** | 100% RU/EN parity, 0 style violations |
| **Frontend Production Build** | `npm run build --prefix apps/frontend` | **PASS** | 739 modules built (`vite build`) |
| **API Contracts & Docs/DBML** | `education-contract.test.mjs`, `check-links.py`, `check-dbml.cjs`, `export_schema.py --check` | **PASS** | 2/2 contract tests, 981 links, 6 DB planes, 0 drift |
| **Real Browser + S3 + PG E2E** | `node apps/frontend/scripts/e2e-platform09-r2.mjs` | **PASS** | 5/5 scenarios (`E2E-01`..`E2E-05`) |

---

## 3. Related Audit Artifacts

- [PLATFORM_09_R2_SECURITY.md](file:///c:/Platform/docs/audits/PLATFORM_09_R2_SECURITY.md)
- [PLATFORM_09_R2_E2E_MATRIX.md](file:///c:/Platform/docs/audits/PLATFORM_09_R2_E2E_MATRIX.md)
- [PLATFORM_09_R2_REMAINING_GAPS.md](file:///c:/Platform/docs/audits/PLATFORM_09_R2_REMAINING_GAPS.md)
- [platform09-r2-evidence.json](file:///c:/Platform/docs/audits/platform09-r2-evidence.json)
