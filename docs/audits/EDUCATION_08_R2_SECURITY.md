# PLATFORM — EDUCATION_08_R2 SECURITY AUDIT REPORT
## Assessment Studio, Anti-Cheat, Concurrency & Media Security Hardening

**Date:** November 24, 2026  
**Auditor:** Principal Security Engineer & Education Architect  
**Classification:** Internal Technical Audit  

---

## 1. Executive Summary

This security audit certifies the production hardening of the Assessment Studio and Typing 3.0 subsystem within the Education plane. All anti-cheat server projections, optimistic concurrency safeguards, authentication boundaries, and storage upload verification rules have been audited and mathematically verified with automated regression tests.

---

## 2. In-Depth Security Controls & Verification

### 2.1 Anti-Cheat Server Authoritative Projection (Answer Key & Explanation Masking)
- **Vulnerability Addressed:** Client-side inspection of network payloads (via browser devtools or script injection) could allow learners to intercept correct answers (`answerKey`) and explanations before submission.
- **Server Implementation:**
  - `AssessmentService::maskStudentAttempt()` strictly strips `answerKey`, `correctAnswer`, and `explanation` from all questions in the attempt whenever `state === 'IN_PROGRESS'`.
  - When an attempt is `EVALUATED`, answers and explanations are ONLY revealed if permitted by `reveal_answers_policy`:
    - `never`: answers and explanations are never transmitted to students.
    - `immediate`: answers are projected immediately upon evaluation.
    - `after_grading`: answers are projected only after evaluation is finalized.
    - `deadline`: answers are withheld until `now >= due_at` (enforced using authoritative server time).
  - Masking applies symmetrically to both `questions` and `result.questions`.
- **Verification:** `AssessmentDomainTest::testStudentAttemptMaskingAcrossAllRevealPolicies()` asserts all 6 policy and state permutations.

### 2.2 Student Authorization & Active Assignment Check
- **Vulnerability Addressed:** Insecure Direct Object Reference (IDOR) or unassigned assessment access where a student accesses an assessment belonging to another group or organization.
- **Server Implementation:**
  - `AssessmentService::assertStudentActive()` verifies the student exists in the authenticated tenant and checks that enrollment has not been revoked (`revoked_at IS NULL`).
  - In `startStudentAttempt()`, access is allowed ONLY if:
    1. The assessment has `open_practice = true`, OR
    2. There is an active homework assignment (`education.homework_recipients` joined with `education.homework_assignments`) for this student with availability within deadlines (`due_at` and `allow_late`), OR
    3. There is an active group activity assignment (`education.activity_assignments`).
- **HTTP Statuses:** 403 `access_denied`, 404 `not_found`, 409 `assignment_closed`.

### 2.3 Concurrency Control & Optimistic Revision Locking
- **Vulnerability Addressed:** Race conditions in autosave where an older autosave packet from high-latency network conditions overwrites newer student progress.
- **Server Implementation:**
  - `education.assessment_attempts` contains an integer `revision` column (default 1).
  - `saveStudentAttempt()` executes an atomic compare-and-swap:
    ```sql
    UPDATE education.assessment_attempts
    SET answers = CAST(:answers AS jsonb),
        revision = revision + 1,
        updated_at = now()
    WHERE id = CAST(:id AS uuid) AND revision = :clientRev
    ```
  - If 0 rows are updated due to a revision mismatch, the server aborts with HTTP 409 `revision_conflict`, returning the current authoritative server revision for graceful client state synchronization.

### 2.4 Authoritative Time Enforcement & Grace Period
- **Vulnerability Addressed:** Client system clock tampering to gain unlimited time during timed exams.
- **Server Implementation:**
  - Time limits are enforced strictly against server start timestamp (`started_at`).
  - A server-side grace period of 30 seconds (`GRACE_PERIOD_SECONDS = 30`) is added to compensate for network transit jitter.
  - If `elapsedSeconds > timeLimitSeconds + 30`, subsequent autosaves are rejected with HTTP 409 `attempt_time_expired`.

### 2.5 Teacher Review Score Bounds & Audit Trail
- **Vulnerability Addressed:** Improper score tampering or accidental negative/out-of-bounds score assignment.
- **Server Implementation:**
  - `reviewAttempt()` verifies `0 <= score <= max_score`. Values outside this range throw HTTP 422 `invalid_score_bounds`.
  - Recalculates percentage: `round((score / maxScore) * 100, 2)`.
  - Appends an immutable audit entry to `review_history` containing `reviewedByPersonId`, `reviewedAt`, `previousScore`, `newScore`, and `notes`.

### 2.6 Media Upload Confirmation Hardening
- **Vulnerability Addressed:** Upload spoofing where clients confirm unverified media assets without an active session or with mismatched storage objects.
- **Server Implementation:**
  - `MediaService::confirmUpload()` verifies an active upload session in `media.upload_sessions` where `expires_at > now()`.
  - Verifies storage object existence via `StorageFileAdapter::exists()`.
  - Compares client metadata `sha256` and `sizeBytes` against the reserved record, throwing HTTP 422 `checksum_mismatch` or `size_mismatch` on divergence.

---

## 3. Security Test Coverage Summary

| Area | Security Assertion | Test Location | Result |
|---|---|---|---|
| Anti-Cheat | Question answer key stripped during exam | `AssessmentDomainTest.php` | **PASSED** |
| Anti-Cheat | Result explanations stripped during exam | `AssessmentDomainTest.php` | **PASSED** |
| Concurrency | Optimistic revision mismatch returns 409 | `AssessmentDomainTest.php` | **PASSED** |
| Validation | Publication rejects `matching` & unsupported question types | `AssessmentDomainTest.php` | **PASSED** |
| Teacher Safety | Review score bounds (0..maxScore) enforced | `AssessmentDomainTest.php` | **PASSED** |
| Teacher Safety | Review history audit trail recorded | `AssessmentDomainTest.php` | **PASSED** |
| Registry Modularity | Duplicate plugin code registration throws 409 | `ActivityPluginTest.php` | **PASSED** |
| Registry Modularity | Non-semver plugin version throws 422 | `ActivityPluginTest.php` | **PASSED** |
| Media Security | Expired upload session returns 409 | `MediaServiceTest.php` | **PASSED** |
| Media Security | Checksum mismatch returns 422 | `MediaServiceTest.php` | **PASSED** |
| Media Security | Size mismatch returns 422 | `MediaServiceTest.php` | **PASSED** |
