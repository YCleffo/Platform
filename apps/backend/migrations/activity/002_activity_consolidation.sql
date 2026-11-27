-- Migration 002: Activity Engine Consolidation (PLATFORM_12 GATE 01)
-- Idempotent migration of legacy education.activity_* and education.assessment_* records
-- into canonical activity.* tables preserving UUIDs. Does NOT drop legacy education.* tables.

ALTER TABLE activity.assignments
    ADD COLUMN IF NOT EXISTS revoked_at timestamptz,
    ADD COLUMN IF NOT EXISTS source_legacy_type varchar(40),
    ADD COLUMN IF NOT EXISTS source_legacy_id uuid;

ALTER TABLE activity.attempts
    ADD COLUMN IF NOT EXISTS source_legacy_type varchar(40),
    ADD COLUMN IF NOT EXISTS source_legacy_id uuid;

ALTER TABLE activity.versions
    ADD COLUMN IF NOT EXISTS reveal_answers_policy varchar(30) NOT NULL DEFAULT 'after_grading';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = 'activity'
          AND table_name = 'versions'
          AND constraint_name = 'activity_versions_reveal_policy_check'
    ) THEN
        ALTER TABLE activity.versions
            ADD CONSTRAINT activity_versions_reveal_policy_check
            CHECK (reveal_answers_policy IN ('never', 'immediate', 'after_grading', 'deadline'));
    END IF;
END $$;

UPDATE activity.versions
SET reveal_answers_policy = config->>'revealAnswersPolicy'
WHERE (config->>'revealAnswersPolicy') IN ('never', 'immediate', 'after_grading', 'deadline')
  AND reveal_answers_policy <> (config->>'revealAnswersPolicy');

CREATE INDEX IF NOT EXISTS activity_versions_reveal_policy_idx
    ON activity.versions (reveal_answers_policy, ((config->>'revealAnswersPolicy')));

CREATE INDEX IF NOT EXISTS activity_attempts_version_state_idx
    ON activity.attempts (version_id, state, participant_person_id);

CREATE INDEX IF NOT EXISTS activity_items_legacy_idx
    ON activity.items (organization_id, source_legacy_type, source_legacy_id)
    WHERE source_legacy_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS activity_assignments_active_idx
    ON activity.assignments (organization_id, activity_id, status)
    WHERE revoked_at IS NULL;

-- Ensure all 5 built-in plugins are registered
INSERT INTO activity.plugins (code, title, description, category, contract_version, capabilities, default_config, scoring_rules)
VALUES
    (
        'platform-assessment',
        'Assessment Studio 2.0',
        'Interactive tests, quizzes, matching, ordering and open-ended assessments with versions and manual review.',
        'assessment',
        2,
        '["supportsDrafts","supportsRealtime","supportsManualReview","supportsTimer","supportsAdaptive"]'::jsonb,
        '{"timeLimitSeconds":null,"passingScorePercent":60,"shuffleQuestions":false,"shuffleOptions":false,"partialCredit":true,"revealAnswersPolicy":"after_grading"}'::jsonb,
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

-- 1. Sync any education.assessments / education.assessment_versions into activity.items / activity.versions
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
                'examOnly', NOT a.open_practice
            ),
            jsonb_build_object('questions', v.questions_snapshot),
            v.time_limit_seconds, v.max_attempts, v.passing_score_percent, v.grading_policy,
            v.reveal_answers_policy, v.created_at
        FROM education.assessment_versions v
        JOIN education.assessments a ON a.id = v.assessment_id
        WHERE EXISTS (SELECT 1 FROM activity.items i WHERE i.id = v.assessment_id)
        ON CONFLICT (id) DO NOTHING;

        -- Ensure migrated assessment versions have reveal_answers_policy and examOnly/openPractice flags aligned
        UPDATE activity.versions av
        SET reveal_answers_policy = ev.reveal_answers_policy,
            config = av.config || jsonb_build_object(
                'revealAnswersPolicy', ev.reveal_answers_policy,
                'openPractice', ea.open_practice,
                'examOnly', NOT ea.open_practice
            )
        FROM education.assessment_versions ev
        JOIN education.assessments ea ON ea.id = ev.assessment_id
        WHERE av.id = ev.id
          AND (av.reveal_answers_policy <> ev.reveal_answers_policy OR NOT (av.config ? 'examOnly'));

        UPDATE activity.items i
        SET active_version_id = a.latest_version_id
        FROM education.assessments a
        WHERE i.id = a.id AND a.latest_version_id IS NOT NULL AND i.active_version_id IS NULL;
    END IF;
END $$;

-- 2. Migrate education.activity_definitions per organization that references them (or has active Education setup)
DO $$
DECLARE
    rec RECORD;
    target_item_id uuid;
    target_ver_id uuid;
    mapped_plugin varchar(80);
    org_author uuid;
    raw_hash text;
BEGIN
    IF to_regclass('education.activity_definitions') IS NOT NULL THEN
        FOR rec IN
            SELECT DISTINCT
                src.organization_id,
                d.id AS def_id,
                d.code AS def_code,
                d.name AS def_name,
                d.config AS def_config,
                d.created_at
            FROM education.activity_definitions d
            JOIN (
                SELECT organization_id, activity_id FROM education.activity_assignments
                UNION
                SELECT organization_id, activity_id FROM education.activity_attempts
            ) src ON src.activity_id = d.id
        LOOP
            mapped_plugin := CASE rec.def_code
                WHEN 'kiberone-typing' THEN 'platform-typing'
                WHEN 'platform-typing' THEN 'platform-typing'
                WHEN 'kiberone-logic' THEN 'platform-logic'
                WHEN 'platform-logic' THEN 'platform-logic'
                WHEN 'platform-math-sprint' THEN 'platform-math-sprint'
                WHEN 'platform-memory' THEN 'platform-memory'
                WHEN 'platform-assessment' THEN 'platform-assessment'
                ELSE 'platform-typing'
            END;

            SELECT i.id INTO target_item_id
            FROM activity.items i
            WHERE i.organization_id = rec.organization_id
              AND (
                  i.id = rec.def_id
                  OR (i.source_legacy_type = 'activity_definition' AND i.source_legacy_id = rec.def_id)
              )
            LIMIT 1;

            IF target_item_id IS NULL THEN
                SELECT COALESCE(
                    (SELECT t.person_id FROM education.teachers t WHERE t.organization_id = rec.organization_id ORDER BY t.created_at LIMIT 1),
                    (SELECT m.person_id FROM core.organization_memberships m WHERE m.organization_id = rec.organization_id AND m.status = 'active' LIMIT 1),
                    rec.organization_id
                ) INTO org_author;

                -- Preserve d.id if not yet taken by another org; otherwise deterministic UUID per (org, def)
                IF NOT EXISTS (SELECT 1 FROM activity.items WHERE id = rec.def_id) THEN
                    target_item_id := rec.def_id;
                ELSE
                    raw_hash := md5(rec.organization_id::text || ':' || rec.def_id::text);
                    target_item_id := (
                        substr(raw_hash, 1, 8) || '-' ||
                        substr(raw_hash, 9, 4) || '-4' ||
                        substr(raw_hash, 14, 3) || '-8' ||
                        substr(raw_hash, 18, 3) || '-' ||
                        substr(raw_hash, 21, 12)
                    )::uuid;
                END IF;

                INSERT INTO activity.items (
                    id, organization_id, plugin_code, author_person_id, title, description,
                    status, visibility, source_legacy_type, source_legacy_id, created_at, updated_at
                ) VALUES (
                    target_item_id, rec.organization_id, mapped_plugin, org_author,
                    rec.def_name, 'Migrated Education activity definition (' || rec.def_code || ')',
                    'PUBLISHED', 'public_catalog', 'activity_definition', rec.def_id,
                    rec.created_at, now()
                )
                ON CONFLICT (id) DO NOTHING;
            END IF;

            SELECT v.id INTO target_ver_id
            FROM activity.versions v
            WHERE v.activity_id = target_item_id
            ORDER BY v.version_number DESC
            LIMIT 1;

            IF target_ver_id IS NULL THEN
                INSERT INTO activity.versions (
                    activity_id, version_number, title, instructions, config, content,
                    max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, created_at
                ) VALUES (
                    target_item_id, 1, rec.def_name, '', COALESCE(rec.def_config, '{}'::jsonb), '{}'::jsonb,
                    0, 60, 'highest', 'after_grading', rec.created_at
                )
                ON CONFLICT (activity_id, version_number) DO NOTHING
                RETURNING id INTO target_ver_id;

                IF target_ver_id IS NULL THEN
                    SELECT v.id INTO target_ver_id FROM activity.versions v WHERE v.activity_id = target_item_id ORDER BY v.version_number DESC LIMIT 1;
                END IF;
            END IF;

            UPDATE activity.items
            SET active_version_id = COALESCE(active_version_id, target_ver_id)
            WHERE id = target_item_id AND active_version_id IS NULL;
        END LOOP;
    END IF;
END $$;

-- 3. Migrate education.activity_assignments -> activity.assignments (preserving UUIDs)
DO $$
DECLARE
    rec RECORD;
    resolved_item_id uuid;
    resolved_ver_id uuid;
    resolved_person_id uuid;
    cfg_assessment_id text;
    cfg_version_id text;
BEGIN
    IF to_regclass('education.activity_assignments') IS NOT NULL THEN
        FOR rec IN
            SELECT aa.*, d.code AS def_code
            FROM education.activity_assignments aa
            JOIN education.activity_definitions d ON d.id = aa.activity_id
            WHERE NOT EXISTS (SELECT 1 FROM activity.assignments ag WHERE ag.id = aa.id)
        LOOP
            resolved_item_id := NULL;
            resolved_ver_id := NULL;

            cfg_assessment_id := rec.config->>'assessmentId';
            IF cfg_assessment_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
                SELECT i.id, i.active_version_id INTO resolved_item_id, resolved_ver_id
                FROM activity.items i
                WHERE i.id = cfg_assessment_id::uuid AND i.organization_id = rec.organization_id
                LIMIT 1;

                cfg_version_id := rec.config->>'versionId';
                IF resolved_item_id IS NOT NULL AND cfg_version_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
                    SELECT v.id INTO resolved_ver_id
                    FROM activity.versions v
                    WHERE v.id = cfg_version_id::uuid AND v.activity_id = resolved_item_id
                    LIMIT 1;
                    IF resolved_ver_id IS NULL THEN
                        SELECT i.active_version_id INTO resolved_ver_id FROM activity.items i WHERE i.id = resolved_item_id;
                    END IF;
                END IF;
            END IF;

            IF resolved_item_id IS NULL THEN
                SELECT i.id, i.active_version_id INTO resolved_item_id, resolved_ver_id
                FROM activity.items i
                WHERE i.organization_id = rec.organization_id
                  AND (
                      (i.source_legacy_type = 'activity_definition' AND i.source_legacy_id = rec.activity_id)
                      OR i.id = rec.activity_id
                  )
                LIMIT 1;
            END IF;

            IF resolved_item_id IS NOT NULL AND resolved_ver_id IS NOT NULL THEN
                SELECT COALESCE(
                    (SELECT a.person_id FROM core.accounts a WHERE a.id = rec.owner_account_id LIMIT 1),
                    rec.owner_account_id
                ) INTO resolved_person_id;

                INSERT INTO activity.assignments (
                    id, organization_id, activity_id, version_id, assigned_by_person_id,
                    target_type, target_id, opens_at, due_at, allow_late, config_override,
                    status, source_legacy_type, source_legacy_id, created_at
                ) VALUES (
                    rec.id, rec.organization_id, resolved_item_id, resolved_ver_id, resolved_person_id,
                    CASE WHEN rec.group_id IS NOT NULL THEN 'group' ELSE 'student' END,
                    COALESCE(rec.group_id, rec.student_id),
                    rec.opens_at, rec.due_at, false, COALESCE(rec.config, '{}'::jsonb),
                    'ACTIVE', 'education_activity_assignment', rec.id, rec.created_at
                )
                ON CONFLICT (id) DO NOTHING;
            END IF;
        END LOOP;
    END IF;
END $$;

-- 4. Migrate education.assessment_attempts -> activity.attempts & activity.attempt_events (preserving UUIDs)
DO $$
BEGIN
    IF to_regclass('education.assessment_attempts') IS NOT NULL THEN
        INSERT INTO activity.attempts (
            id, organization_id, activity_id, version_id, assignment_id,
            participant_person_id, participant_account_id,
            state, revision, seed, runtime_config, draft_state,
            answers, evaluation, raw_score, max_score, percentage, passed,
            started_at, submitted_at, evaluated_at,
            reviewer_person_id, review_notes,
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

-- 5. Migrate education.activity_attempts + activity_results + typing_results + logic_results -> activity.attempts & activity.attempt_events (preserving UUIDs)
DO $$
BEGIN
    IF to_regclass('education.activity_attempts') IS NOT NULL THEN
        INSERT INTO activity.attempts (
            id, organization_id, activity_id, version_id, assignment_id,
            participant_person_id, participant_account_id,
            state, revision, seed, runtime_config, draft_state,
            answers, evaluation, raw_score, max_score, percentage, passed,
            started_at, submitted_at, evaluated_at,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            a.id,
            a.organization_id,
            ai.id,
            av.id,
            CASE WHEN a.assignment_id IS NOT NULL AND EXISTS (SELECT 1 FROM activity.assignments ag WHERE ag.id = a.assignment_id)
                 THEN a.assignment_id ELSE NULL END,
            s.person_id,
            a.account_id,
            CASE
                WHEN a.revoked_at IS NOT NULL THEN 'INVALIDATED'
                WHEN a.status = 'finished' THEN 'EVALUATED'
                WHEN a.status = 'expired' THEN 'EXPIRED'
                WHEN a.status = 'failed' THEN 'CANCELLED'
                ELSE 'IN_PROGRESS'
            END,
            GREATEST(1, COALESCE(a.state_version, 0) + 1),
            0,
            COALESCE(a.config, '{}'::jsonb),
            COALESCE(a.exercise_payload, '{}'::jsonb),
            COALESCE(a.client_state, '{}'::jsonb),
            CASE
                WHEN a.status = 'finished' THEN jsonb_build_object(
                    'metrics', COALESCE(
                        ar.metrics,
                        CASE WHEN tr.attempt_id IS NOT NULL THEN jsonb_build_object(
                            'wpm', tr.wpm,
                            'accuracy', tr.accuracy,
                            'errors', tr.errors,
                            'keystrokes', tr.keystrokes,
                            'correctChars', tr.correct_chars,
                            'durationSeconds', tr.duration_seconds,
                            'language', tr.language
                        ) ELSE '{}'::jsonb END
                    ),
                    'passed', COALESCE(ar.passed, tr.keystrokes > 0, false),
                    'telemetryVerified', false,
                    'feedback', ''
                )
                ELSE '{}'::jsonb
            END,
            COALESCE(
                tr.wpm,
                lr.correct_answers::numeric,
                NULLIF(ar.metrics->>'score', '')::numeric
            ),
            COALESCE(
                tr.wpm,
                lr.total_rounds::numeric,
                NULLIF(ar.metrics->>'totalRounds', '')::numeric,
                CASE WHEN a.status = 'finished' THEN 100.0 ELSE NULL END
            ),
            LEAST(100.0, GREATEST(0.0, COALESCE(
                tr.accuracy,
                lr.accuracy,
                NULLIF(ar.metrics->>'accuracy', '')::numeric
            ))),
            COALESCE(ar.passed, CASE WHEN tr.attempt_id IS NOT NULL THEN tr.keystrokes > 0 ELSE NULL END),
            a.started_at,
            a.finished_at,
            CASE WHEN a.status = 'finished' THEN a.finished_at ELSE NULL END,
            'education_activity_attempt',
            a.id,
            a.started_at,
            COALESCE(a.finished_at, a.started_at)
        FROM education.activity_attempts a
        JOIN education.students s ON s.id = a.student_id AND s.organization_id = a.organization_id
        JOIN activity.items ai ON ai.organization_id = a.organization_id
            AND ((ai.source_legacy_type = 'activity_definition' AND ai.source_legacy_id = a.activity_id) OR ai.id = a.activity_id)
        JOIN activity.versions av ON av.id = ai.active_version_id
        LEFT JOIN education.activity_results ar ON ar.attempt_id = a.id
        LEFT JOIN education.typing_results tr ON tr.attempt_id = a.id
        LEFT JOIN education.logic_results lr ON lr.attempt_id = a.id
        ON CONFLICT (id) DO NOTHING;

        INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason, metadata, created_at)
        SELECT
            a.id,
            'CREATED',
            a.state,
            a.participant_person_id,
            'legacy_activity_attempt_migrated',
            jsonb_build_object('sourceLegacyType', 'education_activity_attempt', 'sourceLegacyId', a.id),
            a.created_at
        FROM activity.attempts a
        WHERE a.source_legacy_type = 'education_activity_attempt'
          AND NOT EXISTS (SELECT 1 FROM activity.attempt_events e WHERE e.attempt_id = a.id);
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
