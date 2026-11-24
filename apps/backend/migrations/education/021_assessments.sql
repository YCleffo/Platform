-- Migration 021: Assessment Studio & Question Bank
-- Adds tables for assessments, immutable version snapshots, question bank, and student attempts.

CREATE TABLE IF NOT EXISTS education.assessments (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    author_person_id uuid NOT NULL,
    title varchar(200) NOT NULL CHECK (btrim(title) <> ''),
    description text NOT NULL DEFAULT '',
    status varchar(20) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED')),
    latest_version_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS assessments_org_status_idx
    ON education.assessments (organization_id, status, updated_at DESC);

CREATE TABLE IF NOT EXISTS education.assessment_versions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    assessment_id uuid NOT NULL REFERENCES education.assessments(id) ON DELETE CASCADE,
    version_number integer NOT NULL CHECK (version_number > 0),
    title varchar(200) NOT NULL CHECK (btrim(title) <> ''),
    instructions text NOT NULL DEFAULT '',
    time_limit_seconds integer CHECK (time_limit_seconds IS NULL OR time_limit_seconds BETWEEN 30 AND 14400),
    passing_score_percent integer NOT NULL DEFAULT 60 CHECK (passing_score_percent BETWEEN 0 AND 100),
    max_attempts integer NOT NULL DEFAULT 1 CHECK (max_attempts BETWEEN 1 AND 20),
    shuffle_questions boolean NOT NULL DEFAULT false,
    shuffle_options boolean NOT NULL DEFAULT false,
    reveal_answers_policy varchar(30) NOT NULL DEFAULT 'after_grading' CHECK (reveal_answers_policy IN ('never', 'immediate', 'after_grading', 'deadline')),
    grading_policy varchar(30) NOT NULL DEFAULT 'highest' CHECK (grading_policy IN ('highest', 'latest', 'average')),
    questions_snapshot jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(questions_snapshot) = 'array'),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_assessment_version UNIQUE (assessment_id, version_number)
);

CREATE INDEX IF NOT EXISTS assessment_versions_assessment_idx
    ON education.assessment_versions (assessment_id, version_number DESC);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = 'education'
          AND table_name = 'assessments'
          AND constraint_name = 'assessments_latest_version_fkey'
    ) THEN
        ALTER TABLE education.assessments
            ADD CONSTRAINT assessments_latest_version_fkey
            FOREIGN KEY (latest_version_id) REFERENCES education.assessment_versions(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS education.question_bank (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    author_person_id uuid NOT NULL,
    category varchar(100) NOT NULL DEFAULT 'general',
    tags jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(tags) = 'array'),
    difficulty integer NOT NULL DEFAULT 1 CHECK (difficulty BETWEEN 1 AND 3),
    question_type varchar(30) NOT NULL CHECK (question_type IN ('single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric', 'matching')),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    answer_key jsonb NOT NULL,
    explanation text NOT NULL DEFAULT '',
    default_points integer NOT NULL DEFAULT 1 CHECK (default_points BETWEEN 1 AND 100),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS question_bank_org_cat_idx
    ON education.question_bank (organization_id, category, difficulty);

CREATE TABLE IF NOT EXISTS education.assessment_attempts (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    version_id uuid NOT NULL REFERENCES education.assessment_versions(id) ON DELETE CASCADE,
    student_id uuid NOT NULL REFERENCES education.students(id) ON DELETE CASCADE,
    organization_id uuid NOT NULL,
    assignment_id uuid REFERENCES education.activity_assignments(id) ON DELETE SET NULL,
    state varchar(30) NOT NULL DEFAULT 'IN_PROGRESS' CHECK (state IN ('IN_PROGRESS', 'SUBMITTED', 'EVALUATED', 'AWAITING_MANUAL_REVIEW')),
    seed bigint NOT NULL DEFAULT 0,
    started_at timestamptz NOT NULL DEFAULT now(),
    submitted_at timestamptz,
    answers jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(answers) = 'object'),
    result jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(result) = 'object'),
    score integer CHECK (score IS NULL OR score >= 0),
    max_score integer CHECK (max_score IS NULL OR max_score >= 0),
    percentage numeric(5,2) CHECK (percentage IS NULL OR percentage BETWEEN 0 AND 100),
    passed boolean,
    manual_review_notes text,
    reviewed_by_person_id uuid,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS assessment_attempts_student_idx
    ON education.assessment_attempts (organization_id, student_id, version_id, started_at DESC);

INSERT INTO education.activity_definitions (code, name, kind, contract_version, config, metrics, required_permission)
VALUES (
    'platform-assessment',
    'Assessment Studio',
    'test',
    1,
    '{"passingScorePercent": 60, "timeLimitSeconds": 1800, "shuffleQuestions": false, "shuffleOptions": false}'::jsonb,
    '[{"code":"score","name":"Score","kind":"number"},{"code":"percentage","name":"Percentage","kind":"number"},{"code":"passed","name":"Passed","kind":"boolean"}]'::jsonb,
    'education.activities.use'
) ON CONFLICT (code) DO NOTHING;
