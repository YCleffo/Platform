-- Final Activity Engine historical consolidation & homework FK alignment (PLATFORM_19_A).
-- Idempotent: safe on empty DBs, repeated runs, and regardless of education/activity migration order.

DO $$
DECLARE
    tbl_hw_links regclass := to_regclass('education.homework_attempt_links');
BEGIN
    -- 1. Ensure legacy education.assessments & versions are present in activity.items / activity.versions.
    IF to_regclass('education.assessments') IS NOT NULL THEN
        INSERT INTO activity.items (
            id, organization_id, plugin_code, author_person_id, author_account_id,
            title, description, status, visibility, tags,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            a.id,
            a.organization_id,
            'platform-assessment',
            a.author_person_id,
            a.author_person_id,
            a.title,
            a.description,
            CASE UPPER(COALESCE(a.status, 'DRAFT'))
                WHEN 'PUBLISHED' THEN 'PUBLISHED'
                WHEN 'CLOSED' THEN 'CLOSED'
                WHEN 'ARCHIVED' THEN 'ARCHIVED'
                ELSE 'DRAFT'
            END,
            CASE WHEN a.open_practice THEN 'organization' ELSE 'private' END,
            '[]'::jsonb,
            'assessment',
            a.id,
            COALESCE(a.created_at, now()),
            COALESCE(a.updated_at, now())
        FROM education.assessments a
        ON CONFLICT (id) DO NOTHING;

        IF to_regclass('education.assessment_versions') IS NOT NULL THEN
            INSERT INTO activity.versions (
                id, activity_id, version_number, title, instructions,
                config, content, scoring_rules,
                time_limit_seconds, max_attempts, passing_score_percent,
                grading_policy, reveal_answers_policy, auto_publish_results,
                created_by_person_id, created_at
            )
            SELECT
                v.id,
                v.assessment_id,
                v.version_number,
                v.title,
                v.instructions,
                jsonb_build_object(
                    'shuffleQuestions', COALESCE(v.shuffle_questions, false),
                    'shuffleOptions', COALESCE(v.shuffle_options, false),
                    'timeLimitSeconds', COALESCE(v.time_limit_seconds, 1800),
                    'passingScorePercent', COALESCE(v.passing_score_percent, 60),
                    'maxAttempts', COALESCE(v.max_attempts, 1),
                    'gradingPolicy', CASE LOWER(COALESCE(v.grading_policy, 'latest'))
                        WHEN 'highest' THEN 'highest'
                        WHEN 'average' THEN 'average'
                        WHEN 'practice_no_grade' THEN 'practice_no_grade'
                        ELSE 'latest'
                    END,
                    'revealAnswersPolicy', CASE LOWER(COALESCE(v.reveal_answers_policy, 'immediate'))
                        WHEN 'never' THEN 'never'
                        WHEN 'after_grading' THEN 'after_grading'
                        WHEN 'deadline' THEN 'deadline'
                        ELSE 'immediate'
                    END
                ),
                jsonb_build_object('questions', COALESCE(v.questions_snapshot, '[]'::jsonb)),
                jsonb_build_object('passingScorePercent', COALESCE(v.passing_score_percent, 60)),
                v.time_limit_seconds,
                GREATEST(0, COALESCE(v.max_attempts, 1)),
                LEAST(100, GREATEST(0, COALESCE(v.passing_score_percent, 60))),
                CASE LOWER(COALESCE(v.grading_policy, 'latest'))
                    WHEN 'highest' THEN 'highest'
                    WHEN 'average' THEN 'average'
                    WHEN 'practice_no_grade' THEN 'practice_no_grade'
                    ELSE 'latest'
                END,
                CASE LOWER(COALESCE(v.reveal_answers_policy, 'immediate'))
                    WHEN 'never' THEN 'never'
                    WHEN 'after_grading' THEN 'after_grading'
                    WHEN 'deadline' THEN 'deadline'
                    ELSE 'immediate'
                END,
                true,
                a.author_person_id,
                COALESCE(v.created_at, now())
            FROM education.assessment_versions v
            JOIN education.assessments a ON a.id = v.assessment_id
            JOIN activity.items i ON i.id = v.assessment_id
            ON CONFLICT (id) DO NOTHING;

            UPDATE activity.items i
            SET active_version_id = a.latest_version_id
            FROM education.assessments a
            JOIN activity.versions av ON av.id = a.latest_version_id
            WHERE i.id = a.id
              AND i.active_version_id IS NULL
              AND a.latest_version_id IS NOT NULL;
        END IF;
    END IF;

    -- 2. Ensure legacy education.question_bank is completely present in activity.question_bank (preserving UUIDs).
    IF to_regclass('education.question_bank') IS NOT NULL THEN
        INSERT INTO activity.question_bank (
            id, organization_id, author_person_id, category, tags, difficulty,
            question_type, content, answer_key, explanation, default_points,
            archived_at, created_at, updated_at
        )
        SELECT
            qb.id,
            qb.organization_id,
            qb.author_person_id,
            qb.category,
            CASE WHEN jsonb_typeof(qb.tags) = 'array' THEN qb.tags ELSE '[]'::jsonb END,
            LEAST(3, GREATEST(1, COALESCE(qb.difficulty, 1))),
            qb.question_type,
            CASE WHEN jsonb_typeof(qb.content) = 'object' THEN qb.content ELSE '{}'::jsonb END,
            qb.answer_key,
            qb.explanation,
            LEAST(100, GREATEST(1, COALESCE(qb.default_points, 1))),
            qb.archived_at,
            COALESCE(qb.created_at, now()),
            COALESCE(qb.updated_at, now())
        FROM education.question_bank qb
        ON CONFLICT (id) DO NOTHING;
    END IF;

    -- 3. Ensure legacy education.activity_assignments (and homework activity assignments) exist in activity.assignments.
    IF to_regclass('education.activity_assignments') IS NOT NULL AND to_regclass('education.activity_definitions') IS NOT NULL THEN
        INSERT INTO activity.items (
            id, organization_id, plugin_code, author_person_id, author_account_id,
            title, status, visibility, tags, source_legacy_type, source_legacy_id,
            created_at, updated_at
        )
        SELECT DISTINCT ON (aa.organization_id, d.id)
            gen_random_uuid(),
            aa.organization_id,
            CASE d.code
                WHEN 'kiberone-typing' THEN 'platform-typing'
                WHEN 'kiberone-logic' THEN 'platform-logic'
                ELSE d.code
            END,
            COALESCE(aa.owner_account_id, aa.organization_id),
            COALESCE(aa.owner_account_id, aa.organization_id),
            d.name,
            'PUBLISHED',
            'private',
            '[]'::jsonb,
            'activity_definition',
            d.id,
            COALESCE(aa.created_at, now()),
            now()
        FROM education.activity_assignments aa
        JOIN education.activity_definitions d ON d.id = aa.activity_id
        WHERE NOT EXISTS (
            SELECT 1 FROM activity.items i
            WHERE i.organization_id = aa.organization_id
              AND i.plugin_code = CASE d.code
                  WHEN 'kiberone-typing' THEN 'platform-typing'
                  WHEN 'kiberone-logic' THEN 'platform-logic'
                  ELSE d.code
              END
        );

        INSERT INTO activity.versions (
            id, activity_id, version_number, title, config, content,
            scoring_rules, grading_policy, reveal_answers_policy, auto_publish_results,
            created_by_person_id, created_at
        )
        SELECT
            gen_random_uuid(),
            i.id,
            1,
            i.title,
            CASE WHEN jsonb_typeof(d.config) = 'object' THEN d.config ELSE '{}'::jsonb END,
            '{}'::jsonb,
            '{}'::jsonb,
            'latest',
            'immediate',
            true,
            i.author_person_id,
            i.created_at
        FROM activity.items i
        JOIN education.activity_definitions d ON d.code = i.plugin_code
        WHERE NOT EXISTS (
            SELECT 1 FROM activity.versions v WHERE v.activity_id = i.id
        );

        UPDATE activity.items i
        SET active_version_id = v.id
        FROM activity.versions v
        WHERE v.activity_id = i.id
          AND i.active_version_id IS NULL;

        INSERT INTO activity.assignments (
            id, organization_id, activity_id, version_id, assigned_by_person_id,
            target_type, target_id, opens_at, due_at, allow_late,
            config_override, status, created_at,
            source_legacy_type, source_legacy_id
        )
        SELECT
            aa.id,
            aa.organization_id,
            i.id,
            COALESCE(i.active_version_id, v.id),
            aa.owner_account_id,
            CASE WHEN aa.student_id IS NOT NULL THEN 'student' ELSE 'group' END,
            COALESCE(aa.student_id, aa.group_id, aa.organization_id),
            aa.opens_at,
            aa.due_at,
            true,
            CASE WHEN jsonb_typeof(aa.config) = 'object' THEN aa.config ELSE '{}'::jsonb END,
            'ACTIVE',
            COALESCE(aa.created_at, now()),
            'education_activity_assignment',
            aa.id
        FROM education.activity_assignments aa
        JOIN education.activity_definitions d ON d.id = aa.activity_id
        JOIN activity.items i ON i.organization_id = aa.organization_id
            AND i.plugin_code = CASE d.code
                WHEN 'kiberone-typing' THEN 'platform-typing'
                WHEN 'kiberone-logic' THEN 'platform-logic'
                ELSE d.code
            END
        LEFT JOIN LATERAL (
            SELECT id FROM activity.versions WHERE activity_id = i.id ORDER BY version_number DESC LIMIT 1
        ) v ON true
        ON CONFLICT (id) DO NOTHING;
    END IF;

    -- 4. Ensure legacy education.assessment_attempts not yet in activity.attempts are migrated.
    IF to_regclass('education.assessment_attempts') IS NOT NULL AND to_regclass('education.assessment_versions') IS NOT NULL THEN
        INSERT INTO activity.attempts (
            id, organization_id, activity_id, version_id, assignment_id,
            participant_person_id, participant_account_id,
            state, revision, seed, runtime_config, draft_state, answers, evaluation,
            raw_score, max_score, percentage, passed,
            started_at, submitted_at, evaluated_at,
            reviewer_person_id, review_notes,
            results_published_at, results_published_by_person_id,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            aa.id,
            aa.organization_id,
            av.assessment_id,
            aa.version_id,
            CASE WHEN ag.id IS NOT NULL THEN aa.assignment_id ELSE NULL END,
            s.person_id,
            s.person_id,
            CASE UPPER(COALESCE(aa.state, 'IN_PROGRESS'))
                WHEN 'SUBMITTED' THEN 'SUBMITTED'
                WHEN 'PENDING_REVIEW' THEN 'PENDING_REVIEW'
                WHEN 'EVALUATED' THEN 'EVALUATED'
                WHEN 'COMPLETED' THEN 'COMPLETED'
                WHEN 'EXPIRED' THEN 'EXPIRED'
                WHEN 'CANCELLED' THEN 'CANCELLED'
                WHEN 'INVALIDATED' THEN 'INVALIDATED'
                ELSE 'IN_PROGRESS'
            END,
            GREATEST(1, COALESCE(aa.revision, 1)),
            COALESCE(aa.seed, 0),
            jsonb_build_object(
                'questionOrder', COALESCE(aa.question_order, '[]'::jsonb),
                'optionOrder', COALESCE(aa.option_order, '{}'::jsonb)
            ),
            CASE WHEN jsonb_typeof(aa.answers) = 'object' THEN aa.answers ELSE '{}'::jsonb END,
            CASE WHEN jsonb_typeof(aa.answers) = 'object' THEN aa.answers ELSE '{}'::jsonb END,
            CASE WHEN jsonb_typeof(aa.result) = 'object' THEN aa.result ELSE '{}'::jsonb END,
            aa.score,
            aa.max_score,
            aa.percentage,
            aa.passed,
            COALESCE(aa.started_at, aa.created_at, now()),
            aa.submitted_at,
            COALESCE(aa.submitted_at, aa.created_at),
            aa.reviewed_by_person_id,
            aa.manual_review_notes,
            CASE WHEN UPPER(COALESCE(aa.state, '')) = 'EVALUATED' THEN COALESCE(aa.submitted_at, aa.created_at) ELSE NULL END,
            aa.reviewed_by_person_id,
            'education_assessment_attempt',
            aa.id,
            COALESCE(aa.created_at, now()),
            COALESCE(aa.submitted_at, aa.created_at, now())
        FROM education.assessment_attempts aa
        JOIN education.assessment_versions av ON av.id = aa.version_id
        JOIN activity.versions actv ON actv.id = aa.version_id
        JOIN education.students s ON s.id = aa.student_id
        LEFT JOIN activity.assignments ag ON ag.id = aa.assignment_id
        ON CONFLICT (id) DO NOTHING;
    END IF;

    -- 5. Ensure legacy education.activity_attempts not yet in activity.attempts are migrated.
    IF to_regclass('education.activity_attempts') IS NOT NULL AND to_regclass('education.activity_definitions') IS NOT NULL THEN
        INSERT INTO activity.items (
            id, organization_id, plugin_code, author_person_id, author_account_id,
            title, status, visibility, tags, source_legacy_type, source_legacy_id,
            created_at, updated_at
        )
        SELECT DISTINCT ON (ea.organization_id, d.id)
            gen_random_uuid(),
            ea.organization_id,
            CASE d.code
                WHEN 'kiberone-typing' THEN 'platform-typing'
                WHEN 'kiberone-logic' THEN 'platform-logic'
                ELSE d.code
            END,
            ea.account_id,
            ea.account_id,
            d.name,
            'PUBLISHED',
            'private',
            '[]'::jsonb,
            'activity_definition',
            d.id,
            COALESCE(ea.started_at, now()),
            now()
        FROM education.activity_attempts ea
        JOIN education.activity_definitions d ON d.id = ea.activity_id
        WHERE NOT EXISTS (
            SELECT 1 FROM activity.items i
            WHERE i.organization_id = ea.organization_id
              AND i.plugin_code = CASE d.code
                  WHEN 'kiberone-typing' THEN 'platform-typing'
                  WHEN 'kiberone-logic' THEN 'platform-logic'
                  ELSE d.code
              END
        );

        INSERT INTO activity.versions (
            id, activity_id, version_number, title, config, content,
            scoring_rules, grading_policy, reveal_answers_policy, auto_publish_results,
            created_by_person_id, created_at
        )
        SELECT
            gen_random_uuid(),
            i.id,
            1,
            i.title,
            CASE WHEN jsonb_typeof(d.config) = 'object' THEN d.config ELSE '{}'::jsonb END,
            '{}'::jsonb,
            '{}'::jsonb,
            'latest',
            'immediate',
            true,
            i.author_person_id,
            i.created_at
        FROM activity.items i
        JOIN education.activity_definitions d ON d.code = i.plugin_code
        WHERE NOT EXISTS (
            SELECT 1 FROM activity.versions v WHERE v.activity_id = i.id
        );

        UPDATE activity.items i
        SET active_version_id = v.id
        FROM activity.versions v
        WHERE v.activity_id = i.id
          AND i.active_version_id IS NULL;

        INSERT INTO activity.attempts (
            id, organization_id, activity_id, version_id, assignment_id,
            participant_person_id, participant_account_id,
            state, revision, seed, runtime_config, draft_state, answers, evaluation,
            raw_score, max_score, percentage, passed,
            started_at, submitted_at, evaluated_at,
            results_published_at,
            source_legacy_type, source_legacy_id, created_at, updated_at
        )
        SELECT
            ea.id,
            ea.organization_id,
            i.id,
            COALESCE(i.active_version_id, v.id),
            CASE WHEN ag.id IS NOT NULL THEN ea.assignment_id ELSE NULL END,
            s.person_id,
            ea.account_id,
            CASE
                WHEN ea.revoked_at IS NOT NULL OR LOWER(COALESCE(ea.status, '')) IN ('revoked', 'cancelled') THEN 'CANCELLED'
                WHEN LOWER(COALESCE(ea.status, '')) = 'finished' THEN 'EVALUATED'
                ELSE 'IN_PROGRESS'
            END,
            GREATEST(1, COALESCE(ea.state_version, 1)),
            0,
            CASE WHEN jsonb_typeof(ea.config) = 'object' THEN ea.config ELSE '{}'::jsonb END,
            CASE
                WHEN jsonb_typeof(ea.exercise_payload) = 'object' THEN ea.exercise_payload
                WHEN jsonb_typeof(ea.client_state) = 'object' THEN ea.client_state
                ELSE '{}'::jsonb
            END,
            CASE WHEN jsonb_typeof(ea.client_state) = 'object' THEN ea.client_state ELSE '{}'::jsonb END,
            COALESCE(
                CASE WHEN jsonb_typeof(ea.result_payload) = 'object' THEN ea.result_payload ELSE NULL END,
                CASE WHEN ar.metrics IS NOT NULL THEN jsonb_build_object('metrics', ar.metrics, 'passed', ar.passed) ELSE '{}'::jsonb END
            ),
            COALESCE(
                NULLIF(ar.metrics->>'score', '')::numeric,
                NULLIF(ar.metrics->>'wpm', '')::numeric,
                NULLIF(ea.result_payload->>'score', '')::numeric,
                NULLIF(ea.result_payload->>'wpm', '')::numeric
            ),
            COALESCE(
                NULLIF(ar.metrics->>'maxScore', '')::numeric,
                NULLIF(ea.result_payload->>'maxScore', '')::numeric
            ),
            COALESCE(
                NULLIF(ar.metrics->>'percentage', '')::numeric,
                NULLIF(ar.metrics->>'accuracy', '')::numeric,
                NULLIF(ea.result_payload->>'percentage', '')::numeric,
                NULLIF(ea.result_payload->>'accuracy', '')::numeric
            ),
            COALESCE(ar.passed, (ea.result_payload->>'passed')::boolean),
            COALESCE(ea.started_at, now()),
            ea.finished_at,
            ea.finished_at,
            CASE WHEN LOWER(COALESCE(ea.status, '')) = 'finished' AND ea.revoked_at IS NULL THEN COALESCE(ea.finished_at, ea.started_at) ELSE NULL END,
            'education_activity_attempt',
            ea.id,
            COALESCE(ea.started_at, now()),
            COALESCE(ea.finished_at, ea.started_at, now())
        FROM education.activity_attempts ea
        JOIN education.students s ON s.id = ea.student_id
        JOIN education.activity_definitions d ON d.id = ea.activity_id
        JOIN activity.items i ON i.organization_id = ea.organization_id
            AND i.plugin_code = CASE d.code
                WHEN 'kiberone-typing' THEN 'platform-typing'
                WHEN 'kiberone-logic' THEN 'platform-logic'
                ELSE d.code
            END
        LEFT JOIN LATERAL (
            SELECT id FROM activity.versions WHERE activity_id = i.id ORDER BY version_number DESC LIMIT 1
        ) v ON true
        LEFT JOIN education.activity_results ar ON ar.attempt_id = ea.id
        LEFT JOIN activity.assignments ag ON ag.id = ea.assignment_id
        ON CONFLICT (id) DO NOTHING;
    END IF;

    -- 6. Backfill results_published_at on already-migrated evaluated canonical attempts.
    UPDATE activity.attempts a
    SET results_published_at = COALESCE(a.evaluated_at, a.submitted_at, a.created_at)
    FROM activity.items i, activity.versions v
    WHERE i.id = a.activity_id
      AND v.id = a.version_id
      AND a.state = 'EVALUATED'
      AND a.results_published_at IS NULL
      AND (i.plugin_code <> 'platform-assessment' OR COALESCE(v.auto_publish_results, true) = true);

    -- 7. If homework tables already exist, switch their FKs to canonical activity.* tables.
    IF tbl_hw_links IS NOT NULL THEN
        ALTER TABLE education.homework_recipients
            DROP CONSTRAINT IF EXISTS homework_recipients_activity_assignment_id_fkey;
        ALTER TABLE education.homework_submissions
            DROP CONSTRAINT IF EXISTS homework_submissions_selected_activity_attempt_id_fkey;
        ALTER TABLE education.homework_submission_versions
            DROP CONSTRAINT IF EXISTS homework_submission_versions_activity_attempt_id_fkey;
        ALTER TABLE education.homework_attempt_links
            DROP CONSTRAINT IF EXISTS homework_attempt_links_attempt_id_fkey;

        ALTER TABLE education.homework_recipients
            ADD CONSTRAINT homework_recipients_activity_assignment_id_fkey
            FOREIGN KEY (activity_assignment_id) REFERENCES activity.assignments(id);
        ALTER TABLE education.homework_submissions
            ADD CONSTRAINT homework_submissions_selected_activity_attempt_id_fkey
            FOREIGN KEY (selected_activity_attempt_id) REFERENCES activity.attempts(id);
        ALTER TABLE education.homework_submission_versions
            ADD CONSTRAINT homework_submission_versions_activity_attempt_id_fkey
            FOREIGN KEY (activity_attempt_id) REFERENCES activity.attempts(id);
        ALTER TABLE education.homework_attempt_links
            ADD CONSTRAINT homework_attempt_links_attempt_id_fkey
            FOREIGN KEY (attempt_id) REFERENCES activity.attempts(id);
    END IF;
END $$;

-- Ensure Activity Engine permissions are mapped onto Education roles idempotently.
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
