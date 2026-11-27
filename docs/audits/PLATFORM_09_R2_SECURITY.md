# PLATFORM — PLATFORM_09_R2 SECURITY HARDENING REPORT

**Date:** 2026-11-25
**Baseline Commit:** `8cd4437e610f208e4a866c2affbf989c075ac034` (branch `main` + hardened working tree)
**Supersedes:** [PLATFORM_09_SECURITY.md](file:///c:/Platform/docs/audits/PLATFORM_09_SECURITY.md)

## 1. Security Vulnerabilities Reproduced & Remediated

1. **Assessment `updated_at` & `p.full_name` SQL Defects (P0/P1):**
   - `AssessmentService::saveStudentAttempt` previously executed `SET updated_at = now()` against `education.assessment_attempts` (which has no `updated_at` column). Removed and verified against live PostgreSQL.
   - `AssessmentService::listAssessmentAttempts` referenced non-existent `p.full_name` on `core.persons`. Fixed to `COALESCE(NULLIF(p.display_name, ''), NULLIF(trim(COALESCE(p.given_name, '') || ' ' || COALESCE(p.family_name, '')), ''), 'Student')`.

2. **Assessment Deadline & Optimistic Concurrency Bypass (P0):**
   - Eliminated all `+ 30` second post-deadline grace windows in `startStudentAttempt()` and `resolveAndVerifyStudentAssignment()`.
   - Enforced atomic revision CAS across both `saveStudentAttempt()` (`409 revision_conflict`) and `submitStudentAttempt()` (mismatching `revision` or omitted `revision` with `answers` when DB `revision > 1` rejected with `409`).
   - Hardened `StudentAssessmentRunner.vue` so conflict responses load authoritative server `answers` + `revision` (or `EVALUATED` state) rather than mutating local revision and overwriting newer server answers.

3. **Media ACL Bypass Across Endpoints & Broad Teacher/Parent Scope (P0):**
   - Extracted `App\Media\Application\MediaAccessAuthorizer` implemented by `App\Education\Infrastructure\EducationMediaAccessAuthorizer`.
   - Eliminated legacy `education.message_threads` and non-existent `ha.files`/`sv.files` queries; canonicalized checks across `education.messages` + `education.thread_members`, and `education.homework_files` + `group_teachers` / `homework_recipients` / `student_guardians` (`status = 'confirmed'`).
   - Removed org-wide teacher bypass (`EXISTS (SELECT 1 FROM education.teachers ...)`): non-author teachers without `education.group_teachers` assignment to the homework group receive HTTP `403`.
   - Enforced uniform `assertReadAccess()` across `GET /api/media/{id}`, `GET /api/media/{id}/playback`, and `GET /api/media/stream/{id}` with cross-tenant isolation (`403 access_denied`).

4. **Protected Media Playback Authentication & Storage Integrity (P0):**
   - Implemented short-lived HMAC-SHA256 signed playback tickets (`?ticket=`, 2-hour TTL, scoped by `assetId`, `organizationId`, `personId`, validated with `hash_equals`), plus live org membership & ACL re-verification on every stream request (revoked access takes effect immediately even before ticket expiry).
   - Stopped storing `playbackUrl` / signed tickets in `education.messages.metadata` (`EducationMessenger.vue`); playback URLs are fetched dynamically per authorized viewer session via `/api/media/{id}/playback`.
   - Added fail-closed S3 enforcement (`503 storage_unavailable` when `StorageFileAdapter === null`), real S3 byte/SHA-256/MIME sniffing verification in both `upload` and `confirmUpload`, `MediaRecorder` codec parameter normalization (`audio/webm;codecs=opus`), and immutable `READY` state (`409 already_ready` on parallel re-upload).
   - Fixed chat message attachment anti-spoofing in `PostgresEducationStore::sendMessage` (`403 media_asset_unavailable` if asset is unowned, cross-org, or not `READY`).

## 2. Negative Security Verification Matrix (Verified via Live HTTP & PostgreSQL)

| Attack / Negative Test | Endpoint / Operation | Verified HTTP Status | Evidence |
| :--- | :--- | :---: | :--- |
| Outsider (Org B) reads/streams Org A voice note | `/api/media/stream/{id}`, `/api/media/{id}`, `/playback` | `403` | `e2e-platform09-r2.mjs` E2E-04 |
| Unrelated Teacher (same Org A, not in group/thread) | `/api/media/stream/{id}`, `/api/media/{id}`, `/playback` | `403` | `e2e-platform09-r2.mjs` E2E-04 |
| Confirmed Parent of Family 2 (same Org A) | `/api/media/stream/{id}`, `/api/media/{id}`, `/playback` | `403` | `e2e-platform09-r2.mjs` E2E-04 |
| Unrelated Student 2 (same Org A) | `/api/media/stream/{id}`, `/api/media/{id}`, `/playback` | `403` | `e2e-platform09-r2.mjs` E2E-04 |
| Forged HMAC playback ticket | `GET /api/media/stream/{id}?ticket=forged` | `401` | `e2e-platform09-r2.mjs` E2E-04 |
| Ticket bound to Asset A used for Asset B | `GET /api/media/stream/{otherId}?ticket=...` | `401` | `e2e-platform09-r2.mjs` E2E-04 |
| Attaching foreign/unowned `mediaAssetId` to message | `POST /api/education/student/threads/{id}/messages` | `403` | `e2e-platform09-r2.mjs` E2E-04 |
| Binary upload SHA-256 mismatch | `POST /api/media/upload/{id}` | `422` | `e2e-platform09-r2.mjs` E2E-04 |
| Parallel re-upload over `READY` asset | `POST /api/media/upload/{id}` | `409` | `e2e-platform09-r2.mjs` E2E-04 |
| Stale tab CAS save / submit (`revision = 1 < 2`) | `/save`, `/submit` | `409` | `e2e-platform09-r2.mjs` E2E-05 |
| Post-deadline autosave attempt | `PUT .../save` | `409` | `e2e-platform09-r2.mjs` E2E-05 |
