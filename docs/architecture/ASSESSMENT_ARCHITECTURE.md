# PLATFORM — ASSESSMENT STUDIO & QUESTION BANK ARCHITECTURE
## Component Architecture, Data Model, Auto-Grading & Security

**Status:** APPROVED & DEPLOYED  
**Version:** 1.0.0 (Education 08 Baseline)  
**Author:** Education Product Architecture & Engineering Team  
**Date:** November 24, 2026

---

## 1. Executive Summary

Assessment Studio provides a native, extensible assessment platform for KiberOne schools within Platform. It provides:
1. **Assessment Authoring & Versioning**: Teachers construct assessments consisting of multiple question types with points, time limits, passing score thresholds, and randomized question/option ordering.
2. **Immutable Published Versions**: Assessments are published into immutable snapshot records (`education.assessment_versions`). Existing assignments and student submissions always bind to a concrete version ID, preventing retroactive corruption.
3. **Question Bank**: Reusable questions categorized by tags, difficulty (1-3), and question types, with instant import into assessments.
4. **Student Assessment Taking Runner**: Distraction-free, responsive test runner with auto-save, question bookmarking, remaining time urgency pulse, and clean review modal.
5. **Deterministic Auto-Grading & Manual Review**: Strict server-side grading engine with support for single choice, multi-choice, true/false, short text (case/whitespace-insensitive match list), and numeric tolerance. Teachers can inspect detailed student answer breakdowns and override scores with audit notes.
6. **Anti-Cheating & Security Boundary**: Server strictly masks `answerKey` and `explanation` from student API payloads prior to evaluation, preventing DevTools network inspection leaks.

---

## 2. Relational Database Schema (`education` plane)

The domain model is defined in migration `021_assessments.sql`:

```sql
-- Main assessment descriptor
CREATE TABLE education.assessments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID NOT NULL,
    created_by_person_id UUID NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status VARCHAR(32) NOT NULL DEFAULT 'DRAFT', -- DRAFT, PUBLISHED, CLOSED, ARCHIVED
    latest_version_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Immutable version snapshot
CREATE TABLE education.assessment_versions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    assessment_id UUID NOT NULL REFERENCES education.assessments(id) ON DELETE CASCADE,
    version_number INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    instructions TEXT NOT NULL DEFAULT '',
    time_limit_seconds INT NULL,
    passing_score_percent INT NOT NULL DEFAULT 70,
    shuffle_questions BOOLEAN NOT NULL DEFAULT FALSE,
    shuffle_options BOOLEAN NOT NULL DEFAULT FALSE,
    reveal_answers_policy VARCHAR(32) NOT NULL DEFAULT 'after_grading',
    questions JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Reusable Question Bank
CREATE TABLE education.question_bank (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID NOT NULL,
    created_by_person_id UUID NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'general',
    tags JSONB NOT NULL DEFAULT '[]'::jsonb,
    difficulty INT NOT NULL DEFAULT 1,
    question_type VARCHAR(32) NOT NULL,
    content JSONB NOT NULL DEFAULT '{}'::jsonb,
    answer_key JSONB NOT NULL,
    explanation TEXT NOT NULL DEFAULT '',
    default_points INT NOT NULL DEFAULT 10,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Student attempts and evaluations
CREATE TABLE education.assessment_attempts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    version_id UUID NOT NULL REFERENCES education.assessment_versions(id),
    student_id UUID NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'IN_PROGRESS',
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    submitted_at TIMESTAMPTZ NULL,
    answers JSONB NOT NULL DEFAULT '{}'::jsonb,
    score INT NULL,
    max_score INT NULL,
    percentage INT NULL,
    passed BOOLEAN NULL,
    score_override INT NULL,
    manual_review_notes TEXT NULL,
    evaluated_at TIMESTAMPTZ NULL
);
```

---

## 3. Question Types & Deterministic Grading

Each question item in a version contains:
- `id`: Unique question identifier (`q_...`).
- `type`: One of `single_choice`, `multi_choice`, `true_false`, `short_text`, `numeric`.
- `prompt`: Question prompt or markdown text.
- `points`: Integer weight for scoring (default: 10).
- `options`: List of `{ id, label }` for choice types.
- `answerKey`: The ground truth value (private to teachers and grading engine).
- `tolerance`: Numerical delta for `numeric` questions (e.g. `0.05`).
- `explanation`: Detailed explanation revealed according to `reveal_answers_policy`.

### Auto-Grading Rules:
1. **Single Choice**: Client answer must strictly equal `answerKey` option ID.
2. **Multi Choice**: Client answers must match `answerKey` set exactly (no missing, no extra).
3. **True / False**: Boolean equality between client response and `answerKey`.
4. **Short Text**: Client text normalized (trimmed, lowercase) matching any item in the accepted answers array.
5. **Numeric**: `abs(clientAnswer - answerKey) <= tolerance`.

---

## 4. Student Payload Masking Guarantee

When `GET /api/education/student/assessments/{id}/start` or `GET /student/assessment-attempts/{id}` is called:
- `answerKey` is completely stripped from each question object.
- `explanation` is completely stripped from each question object.
- When `reveal_answers_policy` permits (e.g. `immediate` or `after_grading`), the evaluated result response provides detailed correctness breakdowns.
