-- Migration 001: Universal Activity Engine (PLATFORM_11)
-- Platform-wide interactive activity engine decoupled from any single product.
-- Preserves existing data from education.assessments / education.question_bank and upgrades supported types.

CREATE SCHEMA IF NOT EXISTS activity;

CREATE TABLE IF NOT EXISTS activity.module_migrations (
    name text PRIMARY KEY,
    sha256 char(64) NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS activity.plugins (
    code varchar(80) PRIMARY KEY,
    title varchar(180) NOT NULL,
    description text NOT NULL DEFAULT '',
    category varchar(60) NOT NULL DEFAULT 'learning',
    contract_version integer NOT NULL DEFAULT 2 CHECK (contract_version > 0),
    capabilities jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(capabilities) = 'array'),
    config_schema jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(config_schema) = 'object'),
    default_config jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(default_config) = 'object'),
    scoring_rules jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(scoring_rules) = 'object'),
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS activity.items (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    plugin_code varchar(80) NOT NULL REFERENCES activity.plugins(code),
    author_person_id uuid NOT NULL,
    author_account_id uuid,
    title varchar(200) NOT NULL CHECK (btrim(title) <> ''),
    description text NOT NULL DEFAULT '',
    status varchar(24) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED')),
    visibility varchar(24) NOT NULL DEFAULT 'organization' CHECK (visibility IN ('private', 'organization', 'group', 'public_catalog')),
    active_version_id uuid,
    tags jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(tags) = 'array'),
    source_legacy_type varchar(40),
    source_legacy_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS activity_items_org_idx
    ON activity.items (organization_id, status, plugin_code, updated_at DESC);

CREATE TABLE IF NOT EXISTS activity.versions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    activity_id uuid NOT NULL REFERENCES activity.items(id) ON DELETE CASCADE,
    version_number integer NOT NULL CHECK (version_number > 0),
    title varchar(200) NOT NULL CHECK (btrim(title) <> ''),
    instructions text NOT NULL DEFAULT '',
    config jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(config) = 'object'),
    content jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(content) = 'object'),
    scoring_rules jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(scoring_rules) = 'object'),
    time_limit_seconds integer,
    max_attempts integer NOT NULL DEFAULT 0 CHECK (max_attempts >= 0),
    passing_score_percent integer NOT NULL DEFAULT 60 CHECK (passing_score_percent BETWEEN 0 AND 100),
    grading_policy varchar(30) NOT NULL DEFAULT 'highest' CHECK (grading_policy IN ('highest', 'latest', 'average', 'practice_no_grade')),
    created_by_person_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (activity_id, version_number)
);

CREATE INDEX IF NOT EXISTS activity_versions_item_idx
    ON activity.versions (activity_id, version_number DESC);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = 'activity' AND table_name = 'items' AND constraint_name = 'activity_items_active_version_fkey'
    ) THEN
        ALTER TABLE activity.items
            ADD CONSTRAINT activity_items_active_version_fkey
            FOREIGN KEY (active_version_id) REFERENCES activity.versions(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS activity.assignments (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    activity_id uuid NOT NULL REFERENCES activity.items(id) ON DELETE CASCADE,
    version_id uuid NOT NULL REFERENCES activity.versions(id),
    assigned_by_person_id uuid NOT NULL,
    target_type varchar(32) NOT NULL CHECK (target_type IN ('group', 'person', 'student', 'lesson', 'homework', 'room', 'organization')),
    target_id uuid NOT NULL,
    opens_at timestamptz,
    due_at timestamptz,
    allow_late boolean NOT NULL DEFAULT false,
    config_override jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(config_override) = 'object'),
    status varchar(24) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'CLOSED', 'CANCELLED')),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS activity_assignments_org_target_idx
    ON activity.assignments (organization_id, target_type, target_id, created_at DESC);

CREATE TABLE IF NOT EXISTS activity.attempts (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    activity_id uuid NOT NULL REFERENCES activity.items(id) ON DELETE CASCADE,
    version_id uuid NOT NULL REFERENCES activity.versions(id),
    assignment_id uuid REFERENCES activity.assignments(id) ON DELETE SET NULL,
    participant_person_id uuid NOT NULL,
    participant_account_id uuid,
    state varchar(30) NOT NULL DEFAULT 'CREATED'
        CHECK (state IN ('CREATED', 'IN_PROGRESS', 'SUBMITTED', 'PENDING_REVIEW', 'EVALUATED', 'COMPLETED', 'EXPIRED', 'CANCELLED', 'INVALIDATED')),
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    seed bigint NOT NULL DEFAULT 0,
    idempotency_key varchar(128),
    runtime_config jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(runtime_config) = 'object'),
    draft_state jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(draft_state) = 'object'),
    telemetry jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(telemetry) = 'object'),
    answers jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(answers) = 'object'),
    evaluation jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(evaluation) = 'object'),
    raw_score numeric(10,2),
    max_score numeric(10,2),
    percentage numeric(5,2),
    passed boolean,
    started_at timestamptz,
    expires_at timestamptz,
    submitted_at timestamptz,
    evaluated_at timestamptz,
    reviewer_person_id uuid,
    review_notes text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS activity_attempts_participant_idx
    ON activity.attempts (organization_id, participant_person_id, activity_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS activity_attempts_idempotency_idx
    ON activity.attempts (organization_id, participant_person_id, activity_id, idempotency_key)
    WHERE idempotency_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS activity.attempt_events (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    attempt_id uuid NOT NULL REFERENCES activity.attempts(id) ON DELETE CASCADE,
    from_state varchar(30),
    to_state varchar(30) NOT NULL,
    actor_person_id uuid,
    reason varchar(120) NOT NULL DEFAULT '',
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS activity_attempt_events_idx
    ON activity.attempt_events (attempt_id, created_at ASC);

CREATE TABLE IF NOT EXISTS activity.question_bank (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL,
    author_person_id uuid NOT NULL,
    category varchar(100) NOT NULL DEFAULT 'general',
    tags jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(tags) = 'array'),
    difficulty integer NOT NULL DEFAULT 1 CHECK (difficulty BETWEEN 1 AND 3),
    question_type varchar(30) NOT NULL
        CHECK (question_type IN ('single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric', 'matching', 'ordering', 'long_text')),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    answer_key jsonb NOT NULL,
    explanation text NOT NULL DEFAULT '',
    default_points integer NOT NULL DEFAULT 1 CHECK (default_points BETWEEN 1 AND 100),
    archived_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS activity_question_bank_org_idx
    ON activity.question_bank (organization_id, archived_at, category, difficulty);

-- Expand legacy education.question_bank constraint to also support ordering & long_text + archived_at
DO $$
BEGIN
    IF to_regclass('education.question_bank') IS NOT NULL THEN
        ALTER TABLE education.question_bank ADD COLUMN IF NOT EXISTS archived_at timestamptz;
        IF EXISTS (
            SELECT 1 FROM information_schema.table_constraints
            WHERE table_schema = 'education' AND table_name = 'question_bank' AND constraint_name = 'question_bank_question_type_check'
        ) THEN
            ALTER TABLE education.question_bank DROP CONSTRAINT question_bank_question_type_check;
        END IF;
        ALTER TABLE education.question_bank
            ADD CONSTRAINT question_bank_question_type_check
            CHECK (question_type IN ('single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric', 'matching', 'ordering', 'long_text'));
    END IF;
END $$;

-- Seed built-in Universal Activity Plugins
INSERT INTO activity.plugins (code, title, description, category, contract_version, capabilities, default_config, scoring_rules)
VALUES
    (
        'platform-assessment',
        'Assessment Studio 2.0',
        'Interactive tests, quizzes, matching, ordering and open-ended assessments with versions and manual review.',
        'assessment',
        2,
        '["supportsDrafts","supportsRealtime","supportsManualReview","supportsTimer","supportsAdaptive"]'::jsonb,
        '{"timeLimitSeconds":null,"passingScorePercent":60,"shuffleQuestions":false,"shuffleOptions":false,"partialCredit":true}'::jsonb,
        '{"mode":"percentage","passThreshold":60}'::jsonb
    ),
    (
        'platform-typing',
        'Typing Arena 4.0',
        'Monkeytype-inspired keyboard speed & accuracy trainer with words, quotes, code, numbers, timed and zen modes.',
        'typing',
        2,
        '["supportsDrafts","supportsRealtime","supportsTimer","supportsPractice"]'::jsonb,
        '{"mode":"words","submode":"time","timeLimitSeconds":60,"wordCount":30,"difficulty":1,"targetWpm":0,"minAccuracy":85,"language":"en"}'::jsonb,
        '{"mode":"wpm_accuracy","minAccuracy":85}'::jsonb
    ),
    (
        'platform-math-sprint',
        'Math Sprint',
        'Adaptive rapid mental arithmetic sprints with accuracy streaks and reaction speed analytics.',
        'math',
        2,
        '["supportsDrafts","supportsTimer","supportsAdaptive","supportsPractice"]'::jsonb,
        '{"operations":["add","sub","mul"],"difficulty":1,"questionCount":15,"timeLimitSeconds":90}'::jsonb,
        '{"mode":"percentage","passThreshold":70}'::jsonb
    ),
    (
        'platform-logic',
        'Logic & Patterns Lab',
        'Sequence reasoning, algorithmic logic puzzles and multi-step deduction rounds.',
        'logic',
        2,
        '["supportsDrafts","supportsAdaptive","supportsPractice"]'::jsonb,
        '{"rounds":8,"difficulty":1,"topics":["sequences","deduction","algorithms"]}'::jsonb,
        '{"mode":"percentage","passThreshold":65}'::jsonb
    ),
    (
        'platform-memory',
        'Memory & Flashcards Studio',
        'Visual pattern recall, spaced repetition pairs and sequence memory training.',
        'memory',
        2,
        '["supportsDrafts","supportsAdaptive","supportsPractice","supportsTimer"]'::jsonb,
        '{"mode":"grid","gridSize":4,"rounds":6,"difficulty":1,"previewMs":1800}'::jsonb,
        '{"mode":"percentage","passThreshold":70}'::jsonb
    )
ON CONFLICT (code) DO UPDATE SET
    title = EXCLUDED.title,
    description = EXCLUDED.description,
    capabilities = EXCLUDED.capabilities,
    default_config = EXCLUDED.default_config;

-- Also register platform-memory inside education.activity_definitions for seamless backward-compat catalog listing
DO $$
BEGIN
    IF to_regclass('education.activity_definitions') IS NOT NULL THEN
        INSERT INTO education.activity_definitions (code, name, kind, contract_version, config, metrics, required_permission)
        VALUES (
            'platform-memory',
            'Memory & Flashcards Studio',
            'web-game',
            1,
            '{"mode":"grid","gridSize":4,"rounds":6,"difficulty":1}'::jsonb,
            '[{"key":"accuracy","label":"Accuracy"},{"key":"score","label":"Score"},{"key":"rounds","label":"Rounds"}]'::jsonb,
            'education.activities.use'
        )
        ON CONFLICT (code) DO NOTHING;
    END IF;
END $$;

-- Migrate existing assessments & question_bank into universal activity.* preserving UUIDs
DO $$
BEGIN
    IF to_regclass('education.assessments') IS NOT NULL THEN
        INSERT INTO activity.items (
            id, organization_id, plugin_code, author_person_id, title, description, status, visibility, source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            a.id, a.organization_id, 'platform-assessment', a.author_person_id, a.title, a.description, a.status,
            CASE WHEN a.open_practice THEN 'public_catalog' ELSE 'organization' END,
            'assessment', a.id, a.created_at, a.updated_at
        FROM education.assessments a
        ON CONFLICT (id) DO NOTHING;

        INSERT INTO activity.versions (
            id, activity_id, version_number, title, instructions, config, content, time_limit_seconds, max_attempts, passing_score_percent, grading_policy, created_at
        )
        SELECT
            v.id, v.assessment_id, v.version_number, v.title, v.instructions,
            jsonb_build_object(
                'shuffleQuestions', v.shuffle_questions,
                'shuffleOptions', v.shuffle_options,
                'revealAnswersPolicy', v.reveal_answers_policy
            ),
            jsonb_build_object('questions', v.questions_snapshot),
            v.time_limit_seconds, v.max_attempts, v.passing_score_percent, v.grading_policy, v.created_at
        FROM education.assessment_versions v
        WHERE EXISTS (SELECT 1 FROM activity.items i WHERE i.id = v.assessment_id)
        ON CONFLICT (id) DO NOTHING;

        UPDATE activity.items i
        SET active_version_id = a.latest_version_id
        FROM education.assessments a
        WHERE i.id = a.id AND a.latest_version_id IS NOT NULL AND i.active_version_id IS NULL;
    END IF;

    IF to_regclass('education.question_bank') IS NOT NULL THEN
        INSERT INTO activity.question_bank (
            id, organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points, archived_at, created_at, updated_at
        )
        SELECT
            id, organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points, archived_at, created_at, updated_at
        FROM education.question_bank
        ON CONFLICT (id) DO NOTHING;
    END IF;
END $$;

INSERT INTO core.modules(code, name, description, version, enabled)
VALUES ('activity', 'Universal Activity Engine', 'Plugin-based interactive exercises, assessments, typing arena, math, logic and memory engine', '1.0.0', true)
ON CONFLICT(code) DO UPDATE SET enabled = true;

INSERT INTO core.permissions(module_id, code, name, description, is_system)
SELECT m.id, p.code, p.name, p.description, true
FROM core.modules m
CROSS JOIN (VALUES
    ('activity.use', 'Use Universal Activities', 'Browse catalog and run interactive learning activities'),
    ('activity.create', 'Author Universal Activities', 'Create and edit interactive activities and question bank items'),
    ('activity.assign', 'Assign Universal Activities', 'Assign activities to groups, students or rooms'),
    ('activity.review', 'Review Activity Attempts', 'Manually evaluate open answers and review analytics')
) AS p(code, name, description)
WHERE m.code = 'activity'
ON CONFLICT(code) DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('activity.use')
WHERE r.code IN ('education.teacher', 'education.student', 'education.parent', 'core.admin')
ON CONFLICT DO NOTHING;

INSERT INTO core.role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM core.roles r
JOIN core.permissions p ON p.code IN ('activity.create', 'activity.assign', 'activity.review')
WHERE r.code IN ('education.teacher', 'core.admin')
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA activity TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA activity TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA activity TO platform_core_runtime;
    END IF;
END $$;
