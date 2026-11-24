# PLATFORM — EDUCATION_08_R2 E2E TEST MATRIX
## End-to-End Test Coverage, Scenarios, and Integration Journeys

**Date:** November 24, 2026  
**Auditor:** QA Lead & Full Stack Engineer  

---

## 1. Assessment Studio & Typing 3.0 Lifecycle Matrix

| Journey ID | Phase | Actor | Action / Scenario | Verification Point | Status |
|---|---|---|---|---|---|
| **E2E-ASM-01** | Assessment Authoring | Teacher | Navigate to `/assessments/new`, add single choice and true/false questions, configure passing score (75%) and time limit (600s). | Assessment created in DRAFT state; question count and max score calculated. | **PASSED** |
| **E2E-ASM-02** | Publishing Validation | Teacher | Click "Publish" on assessment. | Validation ensures questions have valid IDs, prompts, and supported types; version incremented and marked PUBLISHED. | **PASSED** |
| **E2E-ASM-03** | Homework Assignment | Teacher | Assign published assessment to student group via `/homework/new` with `activityCode: 'platform-assessment'`. | Homework assignment created with assessment binding and due date. | **PASSED** |
| **E2E-ASM-04** | Attempt Start & Anti-Cheat | Student | Open assigned assessment under `/student/assessments/:id` or from homework. | Attempt created with deterministic question & option order snapshots; `answerKey` and `explanation` masked from response. | **PASSED** |
| **E2E-ASM-05** | Autosave & Revision Control | Student | Answer question 1 and trigger autosave. | Atomic compare-and-swap validates revision, persists draft answer, increments revision without conflicts. | **PASSED** |
| **E2E-ASM-06** | Idempotent Submit | Student | Submit completed answers before deadline. | Evaluated server-side; score and percentage computed; result state transitioned to EVALUATED. | **PASSED** |
| **E2E-ASM-07** | Teacher Review & Gradebook | Teacher | Review student submission, inspect answers, provide feedback and score override. | Score bounds (0..maxScore) validated; percentage updated; audit trail appended to `review_history`. | **PASSED** |
| **E2E-ASM-08** | Parent Visibility | Parent | View student's completed assessment and homework grade in Parent Portal. | Result, score, and teacher feedback displayed accurately. | **PASSED** |
| **E2E-TYP-01** | Monkeytype Modes | Student | Select Zen mode, Words mode, and 120s duration. | Trainer switches modes dynamically; custom durations reflected in timer. | **PASSED** |
| **E2E-TYP-02** | Early Word Completion | Student | Complete all words in word-based test. | WPM calculated against actual elapsed time (`elapsedSeconds`); trainer finishes early without waiting for timer. | **PASSED** |
| **E2E-TYP-03** | Quick Restart | Student | Press `Tab + Enter` or click "Repeat text". | Board resets instantaneously with same exercise text, smooth caret resets to index 0. | **PASSED** |
| **E2E-TYP-04** | Telemetry Contract | Student | Complete typing run. | Server returns `telemetryVerified: false` contract; metrics strictly validated without client metric injection. | **PASSED** |
