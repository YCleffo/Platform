# PLATFORM_13_C — Unified Assessment Results & Grading Acceptance

**Date:** 2026-11-27  
**Scope:** Consolidation of Assessment Studio results, grading, manual review, publication, answer disclosure, and Education electronic journal integration onto the Universal Activity Engine (`App\Activity`).

---

## 1. Canonical Architecture

- **Single Canonical Engine:** All assessment auto-grading, manual review, per-question scoring, re-grading, result publication, answer disclosure, and audit history are executed server-side in `App\Activity\Application\ActivityEngineService` and `App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin`.
- **Education Domain Adapter:** `App\Education\Infrastructure\EducationActivityJournalAdapter` implements `App\Activity\Application\ActivityResultListenerInterface` and is bound in `apps/backend/config/common/di/education.php`.
- **Metric Separation:**
  - **Activity points:** `activity.attempts.raw_score` / `max_score` (stored in `education.activity_journal_sync.activity_raw_score` / `activity_max_score`).
  - **Completion percentage:** `activity.attempts.percentage` (stored in `education.activity_journal_sync.completion_percentage`).
  - **Educational journal grade:** `education.activity_journal_sync.educational_grade` (`mastered`, `developing`, `needs_support`, `pending`) and `education.lesson_results.status` when linked to a completed lesson.

---

## 2. Implemented Features

- **Deterministic Auto-Grading (`AssessmentActivityPlugin`):**
  - `single_choice`, `true_false` (boolean and `'true'`/`'false'`/`'1'`/`'0'`), `multi_choice` (with `partialCredit`), `short_text` (case-insensitive trimmed, single string or array of accepted answers), `numeric` (with `tolerance`), `matching` (with `partialCredit`), `ordering` (with `partialCredit`), and `long_text` (sets `requiresManualReview = true` and `PENDING_REVIEW`).
- **Manual Review, Per-Question Grading & Re-grading (`ActivityEngineService`):**
  - `POST /api/activity/attempts/{id}/review`: supports per-question `questionReviews` (`pointsEarned`, `comment`, `correct`), attempt-level `rawScore` override, `reviewNotes`, and `publish` flag, recalculating `raw_score`, `max_score`, `percentage`, and `passed` against `passing_score_percent`.
  - `POST /api/activity/attempts/{id}/regrade`: re-runs deterministic evaluation on stored answers, preserving manual question overrides by default (or resetting with `resetManual: true`).
  - `POST /api/activity/attempts/{id}/publish`: publishes or unpublishes evaluated results (`results_published_at`, `results_published_by_person_id`).
  - `GET /api/activity/items/{id}/attempts`: lists attempts for an activity with `state` filtering (including `PENDING_REVIEW` queue).
- **Result History & Answer Disclosure:**
  - `activity.attempt_events` records every state transition, `manual_review`, `regrade`, `results_published`, and `results_unpublished` with `previousScore` / `newScore` metadata and actor person ID.
  - `ActivityEngineService::getAttempt` enforces `revealAnswersPolicy` (`never`, `immediate`, `after_grading`, `deadline`) and masks unpublished scores/breakdowns from students and parents until `results_published_at` is set.
- **Idempotent Journal Delivery:**
  - `education.activity_journal_sync` enforces `UNIQUE (organization_id, attempt_id)` with `ON CONFLICT DO UPDATE`.
  - `education.lesson_results` and `education.result_revisions` only append a new revision when the mapped `status` or `comment` actually changes.
- **ACL:**
  - `EducationActivityDomainPolicy::canAccessParticipantAttempt` authorizes the participant, assigned/group teachers (`activity.review`), org admins, and confirmed guardians (`education.parent.use` + `student_guardians.status = 'confirmed'`).
  - `ParentReadService::progress` filters `activity.attempts` by `a.results_published_at IS NOT NULL` and joins `education.activity_journal_sync` for `educationalGrade`.

---

## 3. Migration & Legacy Status

- **Migrations Applied:**
  - `apps/backend/migrations/activity/003_unified_results_grading.sql`: adds `auto_publish_results`, `results_published_at`, `results_published_by_person_id`, backfills `results_published_at` for evaluated attempts, and idempotently consolidates `education.assessments`, `education.assessment_versions`, `education.assessment_attempts`, and `education.question_bank` into `activity.*` preserving UUIDs.
  - `apps/backend/migrations/education/023_activity_journal_adapter.sql`: creates `education.activity_journal_sync`.
- **Legacy Writes Eliminated:**
  - `AssessmentService` no longer calls `projectActivityToLegacyAssessment` or `projectQuestionBankItemToLegacy` when `ActivityEngineService` is injected.
  - `AssessmentAction` passes `$identity` to all teacher and student operations so they execute through `ActivityEngineService`.
  - `ActivityResultView::forAttempt` reads from `activity.attempts` first.
- **Remaining Legacy Tables / Consumers (Read-Only / Fallback Blocker):**
  - **Tables retained (not dropped):** `education.assessments`, `education.assessment_versions`, `education.assessment_attempts`, `education.question_bank`.
  - **Specific blockers to physical drop:**
    1. `AssessmentService` retains fallback SQL branches when `$identity === null` or `$this->engine === null` (used by unit tests in `apps/backend/tests/Foundation/AssessmentDomainTest.php` that construct `AssessmentService` with a mocked DB connection without `ActivityEngineService`).
    2. `ActivityResultView::forAttempt` retains a read-only fallback to `education.activity_results` / `education.typing_results` for non-migrated legacy activity rows.
  - **Independent Assessment Engine:** None in production request paths (`AssessmentAction` + `AssessmentService` delegate to `ActivityEngineService`).

---

## 4. Frontend & Routes

- **Unified Components:**
  - `apps/frontend/src/modules/activity/components/UnifiedAttemptResultPanel.vue`: reusable result breakdown, per-question manual grading & comments, publication controls, regrade trigger, and result history timeline (RU/EN, light/dark theme variables).
  - `apps/frontend/src/modules/education/assessment/AssessmentResultsWorkspaceView.vue`: route-aware attempts list, manual review queue (`PENDING_REVIEW` filter), loading/error states, and grading workspace.
- **Fixed Routes (`apps/frontend/src/modules/education/routes.ts`):**
  - `/assessments/:id/results` (`education-assessment-results`) and `/assessments/:id/results/:attemptId` (`education-assessment-attempt-result`) now render `AssessmentResultsWorkspaceView.vue` with `assessmentId` and `attemptId` props instead of rendering `AssessmentResultView.vue` without data loading.
  - `AssessmentListView.vue` `viewAttempts` navigates to `education-assessment-results`.

---

## 5. Local Acceptance Verification

- **Backend PHPUnit (`ActivityPluginTest`, `AssessmentDomainTest`, `ActivityEngineHandlersTest`):** `OK (39 tests, 740 assertions)`.
- **Frontend Vitest (`AssessmentStudio.spec.ts`):** `5 passed (5)`.
- **Historical Consolidation (Postgres):** `education.assessments` -> `activity.items` (`5/5` UUIDs matched), `education.assessment_attempts` -> `activity.attempts` (`1/1` UUIDs matched).
- **Auto-Grading & Partial Credit / Tolerance / Long Text:** Verified `7/10 (70%)`, `requiresManualReview = true` across `single_choice`, `multi_choice`, `numeric` (tolerance `0.05`), `short_text` (case-insensitive array), and `long_text`.
- **Journal Adapter Idempotency (rolled-back TX):** Repeated delivery of `onAttemptEvaluated` produced `1` row in `education.activity_journal_sync` with `educational_grade = mastered`.
- **Parent ACL (rolled-back TX):** `EducationActivityDomainPolicy::canAccessParticipantAttempt` returned `allowed` for confirmed guardian and `denied` for unrelated account.
