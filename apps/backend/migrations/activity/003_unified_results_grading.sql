-- Migration 003: Unified Assessment Results & Grading (PLATFORM_13_C)
-- Adds result publication tracking, auto_publish_results policy, and idempotent consolidation
-- of any remaining legacy education.assessments / assessment_versions / assessment_attempts / question_bank records.

ALTER TABLE activity.versions
    ADD COLUMN IF NOT EXISTS auto_publish_results boolean NOT NULL DEFAULT true;

ALTER TABLE activity.attempts
    ADD COLUMN IF NOT EXISTS results_published_at timestamptz,
    ADD COLUMN IF NOT EXISTS results_published_by_person_id uuid;

CREATE INDEX IF NOT EXISTS activity_attempts_activity_state_idx
    ON activity.attempts (organization_id, activity_id, state, submitted_at DESC);

CREATE INDEX IF NOT EXISTS activity_attempts_published_idx
    ON activity.attempts (organization_id, participant_person_id, results_published_at)
    WHERE results_published_at IS NOT NULL;

-- Backfill results_published_at for already evaluated/completed attempts
UPDATE activity.attempts
SET results_published_at = COALESCE(evaluated_at, submitted_at, updated_at, created_at)
WHERE state IN ('EVALUATED', 'COMPLETED')
  AND results_published_at IS NULL;

-- Idempotent consolidation of any remaining legacy education.assessments -> activity.items
DO $$
BEGIN
    IF to_regclass('education.assessments') IS NOT NULL THEN
        INSERT INTO activity.items (
            id, organization_id, plugin_code, author_person_id, title, description, status, visibility,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            a.id, a.organization_id, 'platform-assessment', a.author_person_id, a.title, a.description, a.status,
            CASE WHEN a.open_practice THEN 'public_catalog' ELSE 'organization' END,
            'assessment', a.id, a.created_at, a.updated_at
        FROM education.assessments a
        ON CONFLICT (id) DO NOTHING;

        INSERT INTO activity.versions (
            id, activity_id, version_number, title, instructions, config, content,
            time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, created_at
        )
        SELECT
            v.id, v.assessment_id, v.version_number, v.title, v.instructions,
            jsonb_build_object(
                'shuffleQuestions', v.shuffle_questions,
                'shuffleOptions', v.shuffle_options,
                'revealAnswersPolicy', v.reveal_answers_policy,
                'openPractice', a.open_practice,
                'examOnly', NOT a.open_practice,
                'partialCredit', true
            ),
            jsonb_build_object('questions', v.questions_snapshot),
            v.time_limit_seconds, v.max_attempts, v.passing_score_percent, v.grading_policy,
            v.reveal_answers_policy, v.created_at
        FROM education.assessment_versions v
        JOIN education.assessments a ON a.id = v.assessment_id
        WHERE EXISTS (SELECT 1 FROM activity.items i WHERE i.id = v.assessment_id)
        ON CONFLICT (id) DO NOTHING;

        UPDATE activity.items i
        SET active_version_id = a.latest_version_id
        FROM education.assessments a
        WHERE i.id = a.id AND a.latest_version_id IS NOT NULL AND i.active_version_id IS NULL;
    END IF;
END $$;

-- Idempotent consolidation of any remaining legacy education.assessment_attempts -> activity.attempts
DO $$
BEGIN
    IF to_regclass('education.assessment_attempts') IS NOT NULL THEN
        INSERT INTO activity.attempts (
            id, organization_id, activity_id, version_id, assignment_id,
            participant_person_id, participant_account_id,
            state, revision, seed, runtime_config, draft_state,
            answers, evaluation, raw_score, max_score, percentage, passed,
            started_at, submitted_at, evaluated_at,
            results_published_at, reviewer_person_id, review_notes,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            att.id,
            att.organization_id,
            av.activity_id,
            att.version_id,
            CASE WHEN att.assignment_id IS NOT NULL AND EXISTS (SELECT 1 FROM activity.assignments ag WHERE ag.id = att.assignment_id)
                 THEN att.assignment_id ELSE NULL END,
            s.person_id,
            (SELECT sa.account_id FROM education.student_accounts sa WHERE sa.student_id = att.student_id AND sa.organization_id = att.organization_id LIMIT 1),
            CASE att.state
                WHEN 'AWAITING_MANUAL_REVIEW' THEN 'PENDING_REVIEW'
                WHEN 'SUBMITTED' THEN 'SUBMITTED'
                WHEN 'EVALUATED' THEN 'EVALUATED'
                ELSE 'IN_PROGRESS'
            END,
            GREATEST(1, COALESCE(att.revision, 1)),
            COALESCE(att.seed, 0),
            av.config,
            jsonb_build_object(
                'questionOrder', COALESCE(att.question_order, '[]'::jsonb),
                'optionOrder', COALESCE(att.option_order, '{}'::jsonb)
            ),
            COALESCE(att.answers, '{}'::jsonb),
            COALESCE(att.result, '{}'::jsonb),
            att.score,
            att.max_score,
            att.percentage,
            att.passed,
            att.started_at,
            att.submitted_at,
            CASE WHEN att.state = 'EVALUATED' THEN COALESCE(att.submitted_at, att.created_at) ELSE NULL END,
            CASE WHEN att.state = 'EVALUATED' THEN COALESCE(att.submitted_at, att.created_at) ELSE NULL END,
            att.reviewed_by_person_id,
            att.manual_review_notes,
            'education_assessment_attempt',
            att.id,
            att.created_at,
            COALESCE(att.submitted_at, att.started_at, att.created_at)
        FROM education.assessment_attempts att
        JOIN activity.versions av ON av.id = att.version_id
        JOIN education.students s ON s.id = att.student_id AND s.organization_id = att.organization_id
        ON CONFLICT (id) DO NOTHING;

        INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason, metadata, created_at)
        SELECT
            a.id,
            'CREATED',
            a.state,
            a.participant_person_id,
            'legacy_assessment_attempt_migrated',
            jsonb_build_object('sourceLegacyType', 'education_assessment_attempt', 'sourceLegacyId', a.id),
            a.created_at
        FROM activity.attempts a
        WHERE a.source_legacy_type = 'education_assessment_attempt'
          AND NOT EXISTS (SELECT 1 FROM activity.attempt_events e WHERE e.attempt_id = a.id);
    END IF;
END $$;

-- Idempotent consolidation of any remaining legacy education.question_bank -> activity.question_bank
DO $$
BEGIN
    IF to_regclass('education.question_bank') IS NOT NULL THEN
        INSERT INTO activity.question_bank (
            id, organization_id, author_person_id, category, tags, difficulty,
            question_type, content, answer_key, explanation, default_points, archived_at, created_at, updated_at
        )
        SELECT
            qb.id, qb.organization_id, qb.author_person_id, qb.category, qb.tags, qb.difficulty,
            qb.question_type, qb.content, qb.answer_key, qb.explanation, qb.default_points, qb.archived_at, qb.created_at, qb.updated_at
        FROM education.question_bank qb
        ON CONFLICT (id) DO NOTHING;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_core_runtime') THEN
        GRANT USAGE ON SCHEMA activity TO platform_core_runtime;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA activity TO platform_core_runtime;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA activity TO platform_core_runtime;
    END IF;
END $$;
