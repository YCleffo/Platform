<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Time\Application\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class AssessmentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ActivityHandlerRegistry $activityHandlers,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listAssessments(Scope $scope, array $query = []): array
    {
        $status = isset($query['status']) && is_string($query['status']) ? $query['status'] : null;
        $search = isset($query['search']) && is_string($query['search']) ? trim($query['search']) : null;

        $sql = 'SELECT a.id::text, a.title, a.description, a.status,
                       a.latest_version_id::text, a.created_at, a.updated_at,
                       v.version_number AS latest_version_number,
                       v.passing_score_percent,
                       v.time_limit_seconds,
                       COALESCE(jsonb_array_length(v.questions_snapshot), 0) AS questions_count,
                       (SELECT count(*) FROM education.assessment_attempts att WHERE att.version_id = a.latest_version_id) AS attempts_count,
                       (SELECT round(avg(att.percentage), 1) FROM education.assessment_attempts att WHERE att.version_id = a.latest_version_id AND att.percentage IS NOT NULL) AS avg_score
                FROM education.assessments a
                LEFT JOIN education.assessment_versions v ON a.latest_version_id = v.id
                WHERE a.organization_id = CAST(:org AS uuid)';
        $params = [':org' => $scope->organizationId];

        if ($status !== null && in_array($status, ['DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED'], true)) {
            $sql .= ' AND a.status = :status';
            $params[':status'] = $status;
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (a.title ILIKE :search OR a.description ILIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY a.updated_at DESC';

        $items = $this->db->createCommand($sql, $params)->queryAll();
        return [
            'items' => array_map(static fn(array $row): array => [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'description' => (string) ($row['description'] ?? ''),
                'status' => (string) $row['status'],
                'latestVersionId' => $row['latest_version_id'] !== null ? (string) $row['latest_version_id'] : null,
                'latestVersionNumber' => $row['latest_version_number'] !== null ? (int) $row['latest_version_number'] : null,
                'passingScorePercent' => $row['passing_score_percent'] !== null ? (int) $row['passing_score_percent'] : 60,
                'timeLimitSeconds' => $row['time_limit_seconds'] !== null ? (int) $row['time_limit_seconds'] : null,
                'questionsCount' => (int) ($row['questions_count'] ?? 0),
                'attemptsCount' => (int) ($row['attempts_count'] ?? 0),
                'avgScore' => $row['avg_score'] !== null ? (float) $row['avg_score'] : null,
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
            ], $items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createAssessment(Scope $scope, array $input): array
    {
        $title = Input::text($input['title'] ?? null, 200);
        $description = Input::text($input['description'] ?? '', 5000, false);
        $status = isset($input['status']) && in_array($input['status'], ['DRAFT', 'PUBLISHED'], true) ? $input['status'] : 'DRAFT';

        return $this->db->transaction(function () use ($scope, $title, $description, $status, $input): array {
            $assessment = $this->db->createCommand(
                'INSERT INTO education.assessments (organization_id, author_person_id, title, description, status)
                 VALUES (CAST(:org AS uuid), CAST(:author AS uuid), :title, :description, :status)
                 RETURNING id::text, title, description, status, created_at, updated_at',
                [
                    ':org' => $scope->organizationId,
                    ':author' => $scope->personId,
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => $status,
                ],
            )->queryOne();

            if (!is_array($assessment)) {
                throw new EducationError(500, 'database_error');
            }

            $assessmentId = (string) $assessment['id'];
            $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : [];
            $passingScore = isset($input['passingScorePercent']) ? ActivityHandlerRegistry::integer($input['passingScorePercent'], 0, 100) : 60;
            $timeLimit = isset($input['timeLimitSeconds']) && $input['timeLimitSeconds'] !== null
                ? ActivityHandlerRegistry::integer($input['timeLimitSeconds'], 30, 14400)
                : null;
            $maxAttempts = isset($input['maxAttempts']) ? ActivityHandlerRegistry::integer($input['maxAttempts'], 1, 20) : 1;
            $shuffleQ = (bool) ($input['shuffleQuestions'] ?? false);
            $shuffleOpt = (bool) ($input['shuffleOptions'] ?? false);
            $revealPolicy = in_array($input['revealAnswersPolicy'] ?? '', ['never', 'immediate', 'after_grading', 'deadline'], true)
                ? (string) $input['revealAnswersPolicy']
                : 'after_grading';
            $gradingPolicy = in_array($input['gradingPolicy'] ?? '', ['highest', 'latest', 'average'], true)
                ? (string) $input['gradingPolicy']
                : 'highest';

            // Create initial version
            $version = $this->db->createCommand(
                'INSERT INTO education.assessment_versions
                 (assessment_id, version_number, title, instructions, time_limit_seconds, passing_score_percent, max_attempts,
                  shuffle_questions, shuffle_options, reveal_answers_policy, grading_policy, questions_snapshot)
                 VALUES
                 (CAST(:aId AS uuid), 1, :title, :instructions, :timeLimit, :passingScore, :maxAttempts,
                  :shuffleQ, :shuffleOpt, :revealPolicy, :gradingPolicy, CAST(:questions AS jsonb))
                 RETURNING id::text, version_number, created_at',
                [
                    ':aId' => $assessmentId,
                    ':title' => $title,
                    ':instructions' => (string) ($input['instructions'] ?? ''),
                    ':timeLimit' => $timeLimit,
                    ':passingScore' => $passingScore,
                    ':maxAttempts' => $maxAttempts,
                    ':shuffleQ' => $shuffleQ ? 'true' : 'false',
                    ':shuffleOpt' => $shuffleOpt ? 'true' : 'false',
                    ':revealPolicy' => $revealPolicy,
                    ':gradingPolicy' => $gradingPolicy,
                    ':questions' => json_encode($questions, JSON_THROW_ON_ERROR),
                ],
            )->queryOne();

            if (is_array($version)) {
                $this->db->createCommand(
                    'UPDATE education.assessments SET latest_version_id = CAST(:vId AS uuid) WHERE id = CAST(:aId AS uuid)',
                    [':vId' => $version['id'], ':aId' => $assessmentId],
                )->execute();
            }

            return $this->getAssessment($scope, $assessmentId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssessment(Scope $scope, string $id): array
    {
        $id = Input::uuid($id);
        $row = $this->db->createCommand(
            'SELECT a.id::text, a.title, a.description, a.status,
                    a.latest_version_id::text, a.created_at, a.updated_at,
                    v.version_number, v.instructions, v.time_limit_seconds, v.passing_score_percent,
                    v.max_attempts, v.shuffle_questions, v.shuffle_options, v.reveal_answers_policy,
                    v.grading_policy, v.questions_snapshot
             FROM education.assessments a
             LEFT JOIN education.assessment_versions v ON a.latest_version_id = v.id
             WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)',
            [':id' => $id, ':org' => $scope->organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new EducationError(404, 'not_found');
        }

        $questions = ActivityHandlerRegistry::json($row['questions_snapshot'] ?? []);

        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) $row['status'],
            'latestVersionId' => $row['latest_version_id'] !== null ? (string) $row['latest_version_id'] : null,
            'versionNumber' => $row['version_number'] !== null ? (int) $row['version_number'] : 1,
            'instructions' => (string) ($row['instructions'] ?? ''),
            'timeLimitSeconds' => $row['time_limit_seconds'] !== null ? (int) $row['time_limit_seconds'] : null,
            'passingScorePercent' => $row['passing_score_percent'] !== null ? (int) $row['passing_score_percent'] : 60,
            'maxAttempts' => $row['max_attempts'] !== null ? (int) $row['max_attempts'] : 1,
            'shuffleQuestions' => (bool) ($row['shuffle_questions'] ?? false),
            'shuffleOptions' => (bool) ($row['shuffle_options'] ?? false),
            'revealAnswersPolicy' => (string) ($row['reveal_answers_policy'] ?? 'after_grading'),
            'gradingPolicy' => (string) ($row['grading_policy'] ?? 'highest'),
            'questions' => $questions,
            'questionsCount' => count($questions),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAssessment(Scope $scope, string $id, array $input): array
    {
        $id = Input::uuid($id);
        return $this->db->transaction(function () use ($scope, $id, $input): array {
            $current = $this->getAssessment($scope, $id);

            $title = isset($input['title']) ? Input::text($input['title'], 200) : $current['title'];
            $description = isset($input['description']) ? Input::text($input['description'], 5000, false) : $current['description'];
            $status = isset($input['status']) && in_array($input['status'], ['DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED'], true)
                ? $input['status']
                : $current['status'];

            $this->db->createCommand(
                'UPDATE education.assessments
                 SET title = :title, description = :description, status = :status, updated_at = now()
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                [
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => $status,
                    ':id' => $id,
                    ':org' => $scope->organizationId,
                ],
            )->execute();

            // If draft, update the current version in-place
            if ($current['status'] === 'DRAFT' && $current['latestVersionId'] !== null) {
                $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : $current['questions'];
                $passingScore = isset($input['passingScorePercent']) ? ActivityHandlerRegistry::integer($input['passingScorePercent'], 0, 100) : $current['passingScorePercent'];
                $timeLimit = array_key_exists('timeLimitSeconds', $input)
                    ? ($input['timeLimitSeconds'] !== null ? ActivityHandlerRegistry::integer($input['timeLimitSeconds'], 30, 14400) : null)
                    : $current['timeLimitSeconds'];
                $maxAttempts = isset($input['maxAttempts']) ? ActivityHandlerRegistry::integer($input['maxAttempts'], 1, 20) : $current['maxAttempts'];
                $shuffleQ = isset($input['shuffleQuestions']) ? (bool) $input['shuffleQuestions'] : $current['shuffleQuestions'];
                $shuffleOpt = isset($input['shuffleOptions']) ? (bool) $input['shuffleOptions'] : $current['shuffleOptions'];
                $revealPolicy = in_array($input['revealAnswersPolicy'] ?? '', ['never', 'immediate', 'after_grading', 'deadline'], true)
                    ? (string) $input['revealAnswersPolicy']
                    : $current['revealAnswersPolicy'];
                $gradingPolicy = in_array($input['gradingPolicy'] ?? '', ['highest', 'latest', 'average'], true)
                    ? (string) $input['gradingPolicy']
                    : $current['gradingPolicy'];

                $this->db->createCommand(
                    'UPDATE education.assessment_versions
                     SET title = :title, instructions = :instructions, time_limit_seconds = :timeLimit,
                         passing_score_percent = :passingScore, max_attempts = :maxAttempts,
                         shuffle_questions = :shuffleQ, shuffle_options = :shuffleOpt,
                         reveal_answers_policy = :revealPolicy, grading_policy = :gradingPolicy,
                         questions_snapshot = CAST(:questions AS jsonb)
                     WHERE id = CAST(:vId AS uuid) AND assessment_id = CAST(:id AS uuid)',
                    [
                        ':title' => $title,
                        ':instructions' => (string) ($input['instructions'] ?? $current['instructions']),
                        ':timeLimit' => $timeLimit,
                        ':passingScore' => $passingScore,
                        ':maxAttempts' => $maxAttempts,
                        ':shuffleQ' => $shuffleQ ? 'true' : 'false',
                        ':shuffleOpt' => $shuffleOpt ? 'true' : 'false',
                        ':revealPolicy' => $revealPolicy,
                        ':gradingPolicy' => $gradingPolicy,
                        ':questions' => json_encode($questions, JSON_THROW_ON_ERROR),
                        ':vId' => $current['latestVersionId'],
                        ':id' => $id,
                    ],
                )->execute();
            }

            return $this->getAssessment($scope, $id);
        });
    }

    /**
     * Creates an immutable new published version snapshot.
     * @return array<string, mixed>
     */
    public function publishAssessment(Scope $scope, string $id, array $input = []): array
    {
        $id = Input::uuid($id);
        return $this->db->transaction(function () use ($scope, $id, $input): array {
            $current = $this->getAssessment($scope, $id);

            $nextNumber = (int) $this->db->createCommand(
                'SELECT COALESCE(max(version_number), 0) + 1 FROM education.assessment_versions WHERE assessment_id = CAST(:id AS uuid)',
                [':id' => $id],
            )->queryScalar();

            $title = isset($input['title']) ? Input::text($input['title'], 200) : $current['title'];
            $instructions = (string) ($input['instructions'] ?? $current['instructions']);
            $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : $current['questions'];
            if ($questions === []) {
                throw new EducationError(422, 'empty_assessment_questions');
            }

            $passingScore = isset($input['passingScorePercent']) ? ActivityHandlerRegistry::integer($input['passingScorePercent'], 0, 100) : $current['passingScorePercent'];
            $timeLimit = array_key_exists('timeLimitSeconds', $input)
                ? ($input['timeLimitSeconds'] !== null ? ActivityHandlerRegistry::integer($input['timeLimitSeconds'], 30, 14400) : null)
                : $current['timeLimitSeconds'];
            $maxAttempts = isset($input['maxAttempts']) ? ActivityHandlerRegistry::integer($input['maxAttempts'], 1, 20) : $current['maxAttempts'];
            $shuffleQ = isset($input['shuffleQuestions']) ? (bool) $input['shuffleQuestions'] : $current['shuffleQuestions'];
            $shuffleOpt = isset($input['shuffleOptions']) ? (bool) $input['shuffleOptions'] : $current['shuffleOptions'];
            $revealPolicy = in_array($input['revealAnswersPolicy'] ?? '', ['never', 'immediate', 'after_grading', 'deadline'], true)
                ? (string) $input['revealAnswersPolicy']
                : $current['revealAnswersPolicy'];
            $gradingPolicy = in_array($input['gradingPolicy'] ?? '', ['highest', 'latest', 'average'], true)
                ? (string) $input['gradingPolicy']
                : $current['gradingPolicy'];

            $version = $this->db->createCommand(
                'INSERT INTO education.assessment_versions
                 (assessment_id, version_number, title, instructions, time_limit_seconds, passing_score_percent, max_attempts,
                  shuffle_questions, shuffle_options, reveal_answers_policy, grading_policy, questions_snapshot)
                 VALUES
                 (CAST(:aId AS uuid), :versionNum, :title, :instructions, :timeLimit, :passingScore, :maxAttempts,
                  :shuffleQ, :shuffleOpt, :revealPolicy, :gradingPolicy, CAST(:questions AS jsonb))
                 RETURNING id::text, version_number, created_at',
                [
                    ':aId' => $id,
                    ':versionNum' => $nextNumber,
                    ':title' => $title,
                    ':instructions' => $instructions,
                    ':timeLimit' => $timeLimit,
                    ':passingScore' => $passingScore,
                    ':maxAttempts' => $maxAttempts,
                    ':shuffleQ' => $shuffleQ ? 'true' : 'false',
                    ':shuffleOpt' => $shuffleOpt ? 'true' : 'false',
                    ':revealPolicy' => $revealPolicy,
                    ':gradingPolicy' => $gradingPolicy,
                    ':questions' => json_encode($questions, JSON_THROW_ON_ERROR),
                ],
            )->queryOne();

            if (!is_array($version)) {
                throw new EducationError(500, 'database_error');
            }

            $versionId = (string) $version['id'];

            $this->db->createCommand(
                'UPDATE education.assessments
                 SET status = \'PUBLISHED\', latest_version_id = CAST(:vId AS uuid), updated_at = now()
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':vId' => $versionId, ':id' => $id, ':org' => $scope->organizationId],
            )->execute();

            return $this->getAssessment($scope, $id);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(Scope $scope, string $assessmentId): array
    {
        $id = Input::uuid($assessmentId);
        $rows = $this->db->createCommand(
            'SELECT v.id::text, v.version_number, v.title, v.instructions,
                    v.time_limit_seconds, v.passing_score_percent, v.max_attempts,
                    v.reveal_answers_policy, v.grading_policy,
                    COALESCE(jsonb_array_length(v.questions_snapshot), 0) AS questions_count,
                    v.created_at,
                    (SELECT count(*) FROM education.assessment_attempts att WHERE att.version_id = v.id) AS attempts_count
             FROM education.assessment_versions v
             JOIN education.assessments a ON v.assessment_id = a.id
             WHERE v.assessment_id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
             ORDER BY v.version_number DESC',
            [':id' => $id, ':org' => $scope->organizationId],
        )->queryAll();

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['id'],
            'versionNumber' => (int) $r['version_number'],
            'title' => (string) $r['title'],
            'instructions' => (string) ($r['instructions'] ?? ''),
            'timeLimitSeconds' => $r['time_limit_seconds'] !== null ? (int) $r['time_limit_seconds'] : null,
            'passingScorePercent' => (int) $r['passing_score_percent'],
            'maxAttempts' => (int) $r['max_attempts'],
            'revealAnswersPolicy' => (string) $r['reveal_answers_policy'],
            'gradingPolicy' => (string) $r['grading_policy'],
            'questionsCount' => (int) $r['questions_count'],
            'attemptsCount' => (int) $r['attempts_count'],
            'createdAt' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Question Bank operations
     * @return array<string, mixed>
     */
    public function listQuestionBank(Scope $scope, array $query = []): array
    {
        $category = isset($query['category']) && is_string($query['category']) ? trim($query['category']) : null;
        $difficulty = isset($query['difficulty']) ? (int) $query['difficulty'] : null;
        $type = isset($query['type']) && is_string($query['type']) ? trim($query['type']) : null;
        $search = isset($query['search']) && is_string($query['search']) ? trim($query['search']) : null;

        $sql = 'SELECT id::text, category, tags, difficulty, question_type, content, answer_key, explanation, default_points, created_at, updated_at
                FROM education.question_bank
                WHERE organization_id = CAST(:org AS uuid)';
        $params = [':org' => $scope->organizationId];

        if ($category !== null && $category !== '') {
            $sql .= ' AND category = :cat';
            $params[':cat'] = $category;
        }
        if ($difficulty !== null && $difficulty >= 1 && $difficulty <= 3) {
            $sql .= ' AND difficulty = :diff';
            $params[':diff'] = $difficulty;
        }
        if ($type !== null && $type !== '') {
            $sql .= ' AND question_type = :type';
            $params[':type'] = $type;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (content->>\'prompt\' ILIKE :search OR explanation ILIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 200';

        $items = $this->db->createCommand($sql, $params)->queryAll();
        return [
            'items' => array_map(static fn(array $r): array => [
                'id' => (string) $r['id'],
                'category' => (string) $r['category'],
                'tags' => ActivityHandlerRegistry::json($r['tags'] ?? []),
                'difficulty' => (int) $r['difficulty'],
                'questionType' => (string) $r['question_type'],
                'content' => ActivityHandlerRegistry::json($r['content'] ?? []),
                'answerKey' => ActivityHandlerRegistry::json($r['answer_key'] ?? []),
                'explanation' => (string) ($r['explanation'] ?? ''),
                'defaultPoints' => (int) $r['default_points'],
                'createdAt' => (string) $r['created_at'],
                'updatedAt' => (string) $r['updated_at'],
            ], $items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createQuestionBankItem(Scope $scope, array $input): array
    {
        $type = (string) ($input['questionType'] ?? 'single_choice');
        if (!in_array($type, ['single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric', 'matching'], true)) {
            throw new EducationError(422, 'invalid_question_type');
        }

        $content = isset($input['content']) && is_array($input['content']) ? $input['content'] : [];
        $answerKey = $input['answerKey'] ?? null;
        if ($answerKey === null) {
            throw new EducationError(422, 'missing_answer_key');
        }

        $category = isset($input['category']) && is_string($input['category']) ? mb_substr(trim($input['category']), 0, 100) : 'general';
        $difficulty = isset($input['difficulty']) ? ActivityHandlerRegistry::integer($input['difficulty'], 1, 3) : 1;
        $points = isset($input['defaultPoints']) ? ActivityHandlerRegistry::integer($input['defaultPoints'], 1, 100) : 1;
        $tags = isset($input['tags']) && is_array($input['tags'])
            ? array_values(array_map(static fn(mixed $t): string => is_scalar($t) ? (string) $t : '', $input['tags']))
            : [];
        $explanation = (string) ($input['explanation'] ?? '');

        $row = $this->db->createCommand(
            'INSERT INTO education.question_bank
             (organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points)
             VALUES
             (CAST(:org AS uuid), CAST(:author AS uuid), :cat, CAST(:tags AS jsonb), :diff, :type, CAST(:content AS jsonb), CAST(:answerKey AS jsonb), :explanation, :points)
             RETURNING id::text, category, tags, difficulty, question_type, content, answer_key, explanation, default_points, created_at, updated_at',
            [
                ':org' => $scope->organizationId,
                ':author' => $scope->personId,
                ':cat' => $category,
                ':tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                ':diff' => $difficulty,
                ':type' => $type,
                ':content' => json_encode($content, JSON_THROW_ON_ERROR),
                ':answerKey' => json_encode($answerKey, JSON_THROW_ON_ERROR),
                ':explanation' => $explanation,
                ':points' => $points,
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new EducationError(500, 'database_error');
        }

        return [
            'id' => (string) $row['id'],
            'category' => (string) $row['category'],
            'tags' => ActivityHandlerRegistry::json($row['tags'] ?? []),
            'difficulty' => (int) $row['difficulty'],
            'questionType' => (string) $row['question_type'],
            'content' => ActivityHandlerRegistry::json($row['content'] ?? []),
            'answerKey' => ActivityHandlerRegistry::json($row['answer_key'] ?? []),
            'explanation' => (string) ($row['explanation'] ?? ''),
            'defaultPoints' => (int) $row['default_points'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    public function deleteQuestionBankItem(Scope $scope, string $id): void
    {
        $id = Input::uuid($id);
        $this->db->createCommand(
            'DELETE FROM education.question_bank WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $id, ':org' => $scope->organizationId],
        )->execute();
    }

    /**
     * Student operations
     * @return array<string, mixed>
     */
    public function listStudentAssessments(Scope $scope): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $rows = $this->db->createCommand(
            'SELECT a.id::text, a.title, a.description,
                    v.id::text AS version_id, v.version_number, v.time_limit_seconds, v.passing_score_percent,
                    COALESCE(jsonb_array_length(v.questions_snapshot), 0) AS questions_count,
                    (SELECT count(*) FROM education.assessment_attempts att WHERE att.version_id = v.id AND att.student_id = CAST(:student AS uuid)) AS student_attempts_count,
                    (SELECT max(att.percentage) FROM education.assessment_attempts att WHERE att.version_id = v.id AND att.student_id = CAST(:student AS uuid)) AS best_percentage
             FROM education.assessments a
             JOIN education.assessment_versions v ON a.latest_version_id = v.id
             WHERE a.organization_id = CAST(:org AS uuid) AND a.status = \'PUBLISHED\'
             ORDER BY a.title ASC',
            [':org' => $scope->organizationId, ':student' => $scope->studentId],
        )->queryAll();

        return [
            'items' => array_map(static fn(array $r): array => [
                'id' => (string) $r['id'],
                'title' => (string) $r['title'],
                'description' => (string) ($r['description'] ?? ''),
                'versionId' => (string) $r['version_id'],
                'versionNumber' => (int) $r['version_number'],
                'timeLimitSeconds' => $r['time_limit_seconds'] !== null ? (int) $r['time_limit_seconds'] : null,
                'passingScorePercent' => (int) $r['passing_score_percent'],
                'questionsCount' => (int) $r['questions_count'],
                'studentAttemptsCount' => (int) $r['student_attempts_count'],
                'bestPercentage' => $r['best_percentage'] !== null ? (float) $r['best_percentage'] : null,
            ], $rows),
        ];
    }

    /**
     * Starts or resumes an attempt for student.
     * Answer keys and explanations are masked!
     * @return array<string, mixed>
     */
    public function startStudentAttempt(Scope $scope, string $assessmentId, array $input = []): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $assessmentId = Input::uuid($assessmentId);

        return $this->db->transaction(function () use ($scope, $assessmentId, $input): array {
            $assessment = $this->db->createCommand(
                'SELECT a.id, a.title, a.status, a.latest_version_id, v.id AS version_id, v.version_number,
                        v.title AS version_title, v.instructions, v.time_limit_seconds, v.passing_score_percent,
                        v.max_attempts, v.shuffle_questions, v.shuffle_options, v.reveal_answers_policy,
                        v.questions_snapshot
                 FROM education.assessments a
                 JOIN education.assessment_versions v ON a.latest_version_id = v.id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid) FOR UPDATE OF a',
                [':id' => $assessmentId, ':org' => $scope->organizationId],
            )->queryOne();

            if (!is_array($assessment) || $assessment['status'] !== 'PUBLISHED') {
                throw new EducationError(404, 'assessment_unavailable');
            }

            $versionId = (string) $assessment['version_id'];
            $maxAttempts = (int) $assessment['max_attempts'];

            // Check if there is an active running attempt
            $running = $this->db->createCommand(
                'SELECT id::text, started_at, state, answers
                 FROM education.assessment_attempts
                 WHERE version_id = CAST(:vId AS uuid) AND student_id = CAST(:sId AS uuid) AND state = \'IN_PROGRESS\'
                 ORDER BY started_at DESC LIMIT 1',
                [':vId' => $versionId, ':sId' => $scope->studentId],
            )->queryOne();

            if (is_array($running)) {
                /** @var array<string, mixed> $savedAnswers */
                $savedAnswers = ActivityHandlerRegistry::json($running['answers'] ?? []);
                return $this->buildAttemptResponse($assessment, (string) $running['id'], (string) $running['started_at'], $savedAnswers);
            }

            // Check attempt limits
            $count = (int) $this->db->createCommand(
                'SELECT count(*) FROM education.assessment_attempts
                 WHERE version_id = CAST(:vId AS uuid) AND student_id = CAST(:sId AS uuid)',
                [':vId' => $versionId, ':sId' => $scope->studentId],
            )->queryScalar();

            if ($count >= $maxAttempts) {
                throw new EducationError(409, 'attempt_limit_reached');
            }

            $assignmentId = isset($input['assignmentId']) ? Input::uuid($input['assignmentId']) : null;
            $seed = random_int(1, 99999999);

            $attempt = $this->db->createCommand(
                'INSERT INTO education.assessment_attempts
                 (version_id, student_id, organization_id, assignment_id, state, seed, started_at, answers)
                 VALUES
                 (CAST(:vId AS uuid), CAST(:sId AS uuid), CAST(:org AS uuid), CAST(:assignId AS uuid), \'IN_PROGRESS\', :seed, now(), \'{}\'::jsonb)
                 RETURNING id::text, started_at, state',
                [
                    ':vId' => $versionId,
                    ':sId' => $scope->studentId,
                    ':org' => $scope->organizationId,
                    ':assignId' => $assignmentId,
                    ':seed' => $seed,
                ],
            )->queryOne();

            if (!is_array($attempt)) {
                throw new EducationError(500, 'database_error');
            }

            return $this->buildAttemptResponse($assessment, (string) $attempt['id'], (string) $attempt['started_at'], []);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getStudentAttempt(Scope $scope, string $attemptId): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);
        $attempt = $this->db->createCommand(
            'SELECT att.id::text, att.version_id::text, att.state, att.started_at, att.submitted_at,
                    att.answers, att.result, att.score, att.max_score, att.percentage, att.passed,
                    att.manual_review_notes,
                    v.title AS version_title, v.instructions, v.time_limit_seconds, v.passing_score_percent,
                    v.reveal_answers_policy, v.questions_snapshot
             FROM education.assessment_attempts att
             JOIN education.assessment_versions v ON att.version_id = v.id
             WHERE att.id = CAST(:id AS uuid) AND att.student_id = CAST(:sId AS uuid) AND att.organization_id = CAST(:org AS uuid)',
            [':id' => $attemptId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
        )->queryOne();

        if (!is_array($attempt)) {
            throw new EducationError(404, 'not_found');
        }

        $questionsRaw = ActivityHandlerRegistry::json($attempt['questions_snapshot'] ?? []);
        /** @var list<array<string, mixed>> $questions */
        $questions = array_values(array_filter($questionsRaw, 'is_array'));
        $revealPolicy = (string) ($attempt['reveal_answers_policy'] ?? 'after_grading');
        $isEvaluated = in_array($attempt['state'], ['EVALUATED', 'AWAITING_MANUAL_REVIEW'], true);
        $shouldReveal = $isEvaluated && ($revealPolicy === 'immediate' || $revealPolicy === 'after_grading');

        $publicQuestions = [];
        foreach ($questions as $q) {
            $publicQ = [
                'id' => (string) ($q['id'] ?? ''),
                'type' => (string) ($q['type'] ?? 'single_choice'),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'points' => (int) ($q['points'] ?? 1),
            ];
            if (isset($q['options']) && is_array($q['options'])) {
                $publicQ['options'] = array_map(static fn(array $opt): array => [
                    'id' => (string) ($opt['id'] ?? ''),
                    'label' => (string) ($opt['label'] ?? ''),
                ], $q['options']);
            }
            if ($shouldReveal) {
                $publicQ['correctAnswer'] = $q['answerKey'] ?? null;
                $publicQ['explanation'] = (string) ($q['explanation'] ?? '');
            }
            $publicQuestions[] = $publicQ;
        }

        return [
            'id' => (string) $attempt['id'],
            'versionId' => (string) $attempt['version_id'],
            'title' => (string) $attempt['version_title'],
            'instructions' => (string) ($attempt['instructions'] ?? ''),
            'state' => (string) $attempt['state'],
            'startedAt' => (string) $attempt['started_at'],
            'submittedAt' => $attempt['submitted_at'] !== null ? (string) $attempt['submitted_at'] : null,
            'timeLimitSeconds' => $attempt['time_limit_seconds'] !== null ? (int) $attempt['time_limit_seconds'] : null,
            'passingScorePercent' => (int) $attempt['passing_score_percent'],
            'questions' => $publicQuestions,
            'answers' => ActivityHandlerRegistry::json($attempt['answers'] ?? []),
            'result' => ActivityHandlerRegistry::json($attempt['result'] ?? []),
            'score' => $attempt['score'] !== null ? (int) $attempt['score'] : null,
            'maxScore' => $attempt['max_score'] !== null ? (int) $attempt['max_score'] : null,
            'percentage' => $attempt['percentage'] !== null ? (float) $attempt['percentage'] : null,
            'passed' => $attempt['passed'] !== null ? (bool) $attempt['passed'] : null,
            'manualReviewNotes' => $attempt['manual_review_notes'] !== null ? (string) $attempt['manual_review_notes'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveStudentAttempt(Scope $scope, string $attemptId, array $input): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];

        $updated = $this->db->createCommand(
            'UPDATE education.assessment_attempts
             SET answers = CAST(:answers AS jsonb)
             WHERE id = CAST(:id AS uuid) AND student_id = CAST(:sId AS uuid) AND organization_id = CAST(:org AS uuid) AND state = \'IN_PROGRESS\'',
            [
                ':answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                ':id' => $attemptId,
                ':sId' => $scope->studentId,
                ':org' => $scope->organizationId,
            ],
        )->execute();

        if ($updated === 0) {
            throw new EducationError(409, 'attempt_not_editable');
        }

        return ['saved' => true];
    }

    /**
     * Submits and grades the attempt deterministically.
     * @return array<string, mixed>
     */
    public function submitStudentAttempt(Scope $scope, string $attemptId, array $input): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        return $this->db->transaction(function () use ($scope, $attemptId, $input): array {
            $attempt = $this->db->createCommand(
                'SELECT att.id, att.version_id, att.state, att.started_at,
                        v.questions_snapshot, v.passing_score_percent, v.reveal_answers_policy
                 FROM education.assessment_attempts att
                 JOIN education.assessment_versions v ON att.version_id = v.id
                 WHERE att.id = CAST(:id AS uuid) AND att.student_id = CAST(:sId AS uuid) AND att.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF att',
                [':id' => $attemptId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
            )->queryOne();

            if (!is_array($attempt)) {
                throw new EducationError(404, 'not_found');
            }
            if ($attempt['state'] !== 'IN_PROGRESS') {
                throw new EducationError(409, 'attempt_already_submitted');
            }

            $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
            $elapsedSeconds = isset($input['elapsedSeconds']) ? ActivityHandlerRegistry::integer($input['elapsedSeconds'], 0, 86400) : 0;
            $questions = ActivityHandlerRegistry::json($attempt['questions_snapshot'] ?? []);
            $passingScore = (int) ($attempt['passing_score_percent'] ?? 60);

            $handler = new AssessmentActivityHandler();
            $evalAttempt = [
                'config' => ['passingScorePercent' => $passingScore],
                'exercisePayload' => ['questions' => $questions, 'passingScorePercent' => $passingScore],
            ];
            $evaluated = $handler->evaluate($evalAttempt, [
                'answers' => $answers,
                'elapsedSeconds' => $elapsedSeconds,
            ]);

            /** @var array{score: int|float, maxScore: int|float, percentage: int|float, passed: bool} $metrics */
            $metrics = is_array($evaluated['metrics'] ?? null) ? $evaluated['metrics'] : ['score' => 0, 'maxScore' => 0, 'percentage' => 0.0, 'passed' => false];
            $score = (int) $metrics['score'];
            $maxScore = (int) $metrics['maxScore'];
            $percentage = (float) $metrics['percentage'];
            $passed = $metrics['passed'];

            $this->db->createCommand(
                'UPDATE education.assessment_attempts
                 SET state = \'EVALUATED\', submitted_at = now(),
                     answers = CAST(:answers AS jsonb),
                     result = CAST(:result AS jsonb),
                     score = :score, max_score = :maxScore, percentage = :percentage, passed = :passed
                 WHERE id = CAST(:id AS uuid)',
                [
                    ':answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                    ':result' => json_encode($evaluated, JSON_THROW_ON_ERROR),
                    ':score' => $score,
                    ':maxScore' => $maxScore,
                    ':percentage' => $percentage,
                    ':passed' => $passed ? 'true' : 'false',
                    ':id' => $attemptId,
                ],
            )->execute();

            return $this->getStudentAttempt($scope, $attemptId);
        });
    }

    /**
     * Teacher list of attempts for an assessment.
     * @return list<array<string, mixed>>
     */
    public function listAssessmentAttempts(Scope $scope, string $assessmentId): array
    {
        $assessmentId = Input::uuid($assessmentId);
        $rows = $this->db->createCommand(
            'SELECT att.id::text, att.version_id::text, v.version_number,
                    att.student_id::text, p.full_name AS student_name,
                    att.state, att.score, att.max_score, att.percentage, att.passed,
                    att.started_at, att.submitted_at, att.manual_review_notes
             FROM education.assessment_attempts att
             JOIN education.assessment_versions v ON att.version_id = v.id
             JOIN education.students s ON att.student_id = s.id
             JOIN core.persons p ON s.person_id = p.id
             WHERE v.assessment_id = CAST(:id AS uuid) AND att.organization_id = CAST(:org AS uuid)
             ORDER BY att.started_at DESC',
            [':id' => $assessmentId, ':org' => $scope->organizationId],
        )->queryAll();

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['id'],
            'versionId' => (string) $r['version_id'],
            'versionNumber' => (int) $r['version_number'],
            'studentId' => (string) $r['student_id'],
            'studentName' => (string) ($r['student_name'] ?? 'Student'),
            'state' => (string) $r['state'],
            'score' => $r['score'] !== null ? (int) $r['score'] : null,
            'maxScore' => $r['max_score'] !== null ? (int) $r['max_score'] : null,
            'percentage' => $r['percentage'] !== null ? (float) $r['percentage'] : null,
            'passed' => $r['passed'] !== null ? (bool) $r['passed'] : null,
            'startedAt' => (string) $r['started_at'],
            'submittedAt' => $r['submitted_at'] !== null ? (string) $r['submitted_at'] : null,
            'manualReviewNotes' => $r['manual_review_notes'] !== null ? (string) $r['manual_review_notes'] : null,
        ], $rows);
    }

    /**
     * Teacher review & manual score override.
     * @return array<string, mixed>
     */
    public function reviewAttempt(Scope $scope, string $attemptId, array $input): array
    {
        $attemptId = Input::uuid($attemptId);
        $notes = isset($input['manualReviewNotes']) ? (string) $input['manualReviewNotes'] : null;
        $score = isset($input['score']) ? (int) $input['score'] : null;
        $percentage = isset($input['percentage']) ? (float) $input['percentage'] : null;
        $passed = isset($input['passed']) ? (bool) $input['passed'] : null;

        $this->db->createCommand(
            'UPDATE education.assessment_attempts
             SET manual_review_notes = COALESCE(:notes, manual_review_notes),
                 score = COALESCE(:score, score),
                 percentage = COALESCE(:percentage, percentage),
                 passed = COALESCE(:passed, passed),
                 reviewed_by_person_id = CAST(:reviewer AS uuid),
                 state = \'EVALUATED\'
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [
                ':notes' => $notes,
                ':score' => $score,
                ':percentage' => $percentage,
                ':passed' => $passed !== null ? ($passed ? 'true' : 'false') : null,
                ':reviewer' => $scope->personId,
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
            ],
        )->execute();

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function buildAttemptResponse(array $assessment, string $attemptId, string $startedAt, array $answers): array
    {
        $questionsRaw = ActivityHandlerRegistry::json($assessment['questions_snapshot'] ?? []);
        /** @var list<array<string, mixed>> $questions */
        $questions = array_values(array_filter($questionsRaw, 'is_array'));
        if (!empty($assessment['shuffle_questions'])) {
            shuffle($questions);
        }

        $publicQuestions = [];
        foreach ($questions as $q) {
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
            if (!empty($assessment['shuffle_options']) && count($options) > 1) {
                shuffle($options);
            }
            $publicQ = [
                'id' => (string) ($q['id'] ?? ''),
                'type' => (string) ($q['type'] ?? 'single_choice'),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'points' => (int) ($q['points'] ?? 1),
            ];
            if ($options !== []) {
                $publicQ['options'] = array_map(static fn(array $opt): array => [
                    'id' => (string) ($opt['id'] ?? ''),
                    'label' => (string) ($opt['label'] ?? ''),
                ], $options);
            }
            $publicQuestions[] = $publicQ;
        }

        return [
            'id' => $attemptId,
            'versionId' => (string) $assessment['version_id'],
            'title' => (string) $assessment['version_title'],
            'instructions' => (string) ($assessment['instructions'] ?? ''),
            'state' => 'IN_PROGRESS',
            'startedAt' => $startedAt,
            'timeLimitSeconds' => $assessment['time_limit_seconds'] !== null ? (int) $assessment['time_limit_seconds'] : null,
            'passingScorePercent' => (int) $assessment['passing_score_percent'],
            'questions' => $publicQuestions,
            'answers' => $answers,
        ];
    }
}
