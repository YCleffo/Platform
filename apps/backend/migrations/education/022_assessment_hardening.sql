-- Migration 022: Assessment Hardening & Integrity
-- Adds open_practice flag, revision concurrency, deterministic shuffle snapshots, and review audit history.

ALTER TABLE education.assessments
    ADD COLUMN IF NOT EXISTS open_practice boolean NOT NULL DEFAULT false;

ALTER TABLE education.assessment_attempts
    ADD COLUMN IF NOT EXISTS revision integer NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS question_order jsonb NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS option_order jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS review_history jsonb NOT NULL DEFAULT '[]'::jsonb;

CREATE INDEX IF NOT EXISTS assessment_attempts_student_active_idx
    ON education.assessment_attempts (organization_id, student_id, version_id, state);
