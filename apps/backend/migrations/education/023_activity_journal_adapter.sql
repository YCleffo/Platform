-- Migration 023: Education Electronic Journal Adapter for Universal Activity Engine (PLATFORM_13_C)
-- Provides idempotent delivery tracking between activity.attempts and education.lesson_results,
-- separating activity raw/max score, completion percentage, and educational journal grade.

CREATE TABLE IF NOT EXISTS education.activity_journal_sync (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    attempt_id uuid NOT NULL,
    activity_id uuid NOT NULL,
    student_id uuid NOT NULL REFERENCES education.students(id) ON DELETE CASCADE,
    lesson_id uuid REFERENCES education.lessons(id) ON DELETE SET NULL,
    lesson_result_id uuid REFERENCES education.lesson_results(id) ON DELETE SET NULL,
    activity_raw_score numeric(10,2),
    activity_max_score numeric(10,2),
    completion_percentage numeric(5,2),
    educational_grade varchar(32) NOT NULL CHECK (educational_grade IN ('pending', 'mastered', 'developing', 'needs_support')),
    delivery_key varchar(128) NOT NULL,
    synced_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, attempt_id)
);

CREATE INDEX IF NOT EXISTS activity_journal_sync_student_idx
    ON education.activity_journal_sync (organization_id, student_id, synced_at DESC);

CREATE INDEX IF NOT EXISTS activity_journal_sync_lesson_idx
    ON education.activity_journal_sync (organization_id, lesson_id)
    WHERE lesson_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON education.activity_journal_sync TO platform_core_runtime;
    END IF;
END $$;
