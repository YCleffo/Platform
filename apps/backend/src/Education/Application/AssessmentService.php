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

        $sql = 'SELECT a.id::text, a.title, a.description, a.status, a.open_practice,
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
                'openPractice' => (bool) ($row['open_practice'] ?? false),
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
     * Creates an assessment always as DRAFT to guarantee immutable version transitions.
     * @return array<string, mixed>
     */
    public function createAssessment(Scope $scope, array $input): array
    {
        $title = Input::text($input['title'] ?? null, 200);
        $description = Input::text($input['description'] ?? '', 5000, false);
        $openPractice = (bool) ($input['openPractice'] ?? false);

        return $this->db->transaction(function () use ($scope, $title, $description, $openPractice, $input): array {
            $assessment = $this->db->createCommand(
                'INSERT INTO education.assessments (organization_id, author_person_id, title, description, status, open_practice)
                 VALUES (CAST(:org AS uuid), CAST(:author AS uuid), :title, :description, \'DRAFT\', :openPractice)
                 RETURNING id::text, title, description, status, open_practice, created_at, updated_at',
                [
                    ':org' => $scope->organizationId,
                    ':author' => $scope->personId,
                    ':title' => $title,
                    ':description' => $description,
                    ':openPractice' => $openPractice ? 'true' : 'false',
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

            // Create initial draft version
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
            'SELECT a.id::text, a.title, a.description, a.status, a.open_practice,
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
            'openPractice' => (bool) ($row['open_practice'] ?? false),
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

            // Publishing must only occur through publishAssessment to ensure validation
            if (isset($input['status']) && $input['status'] === 'PUBLISHED' && $current['status'] !== 'PUBLISHED') {
                throw new EducationError(422, 'use_publish_endpoint');
            }

            $title = isset($input['title']) ? Input::text($input['title'], 200) : $current['title'];
            $description = isset($input['description']) ? Input::text($input['description'], 5000, false) : $current['description'];
            $status = isset($input['status']) && in_array($input['status'], ['DRAFT', 'CLOSED', 'ARCHIVED'], true)
                ? $input['status']
                : $current['status'];
            $openPractice = array_key_exists('openPractice', $input) ? (bool) $input['openPractice'] : $current['openPractice'];

            $this->db->createCommand(
                'UPDATE education.assessments
                 SET title = :title, description = :description, status = :status, open_practice = :openPractice, updated_at = now()
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                [
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => $status,
                    ':openPractice' => $openPractice ? 'true' : 'false',
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
     * Validates question structure strictly at publication.
     * @param list<array<string, mixed>> $questions
     */
    public function validateQuestionDefinitions(array $questions): void
    {
        if ($questions === []) {
            throw new EducationError(422, 'empty_assessment_questions');
        }

        $seenIds = [];
        foreach ($questions as $q) {
            if (!is_array($q)) {
                throw new EducationError(422, 'invalid_question_structure');
            }

            $id = isset($q['id']) && is_string($q['id']) ? trim($q['id']) : '';
            if ($id === '' || isset($seenIds[$id])) {
                throw new EducationError(422, 'duplicate_or_empty_question_id');
            }
            $seenIds[$id] = true;

            $type = (string) ($q['type'] ?? '');
            if ($type === 'matching') {
                throw new EducationError(422, 'unsupported_question_type');
            }
            if (!in_array($type, ['single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric'], true)) {
                throw new EducationError(422, 'unsupported_question_type');
            }

            $prompt = isset($q['prompt']) && is_string($q['prompt']) ? trim($q['prompt']) : '';
            if ($prompt === '' || mb_strlen($prompt, 'UTF-8') > 4000) {
                throw new EducationError(422, 'invalid_question_prompt');
            }

            $points = isset($q['points']) ? ActivityHandlerRegistry::integer($q['points'], 1, 100) : 1;

            if ($type === 'single_choice' || $type === 'multi_choice') {
                $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                if (count($options) < 2) {
                    throw new EducationError(422, 'insufficient_options');
                }
                $seenOptIds = [];
                foreach ($options as $opt) {
                    if (!is_array($opt)) {
                        throw new EducationError(422, 'invalid_option_structure');
                    }
                    $optId = isset($opt['id']) && is_string($opt['id']) ? trim($opt['id']) : '';
                    $label = isset($opt['label']) && is_string($opt['label']) ? trim($opt['label']) : '';
                    if ($optId === '' || $label === '' || isset($seenOptIds[$optId])) {
                        throw new EducationError(422, 'invalid_option_id_or_label');
                    }
                    $seenOptIds[$optId] = true;
                }

                $answerKey = $q['answerKey'] ?? null;
                if ($type === 'single_choice') {
                    if (!is_string($answerKey) || !isset($seenOptIds[$answerKey])) {
                        throw new EducationError(422, 'invalid_answer_key');
                    }
                } else {
                    if (!is_array($answerKey) || $answerKey === []) {
                        throw new EducationError(422, 'invalid_answer_key');
                    }
                    foreach ($answerKey as $k) {
                        if (!is_string($k) || !isset($seenOptIds[$k])) {
                            throw new EducationError(422, 'invalid_answer_key');
                        }
                    }
                }
            } elseif ($type === 'true_false') {
                if (!isset($q['answerKey']) || !is_bool($q['answerKey'])) {
                    throw new EducationError(422, 'invalid_answer_key');
                }
            } elseif ($type === 'numeric') {
                if (!isset($q['answerKey']) || !is_numeric($q['answerKey'])) {
                    throw new EducationError(422, 'invalid_answer_key');
                }
            } elseif ($type === 'short_text') {
                $answerKey = $q['answerKey'] ?? null;
                if (is_string($answerKey)) {
                    if (trim($answerKey) === '') {
                        throw new EducationError(422, 'invalid_answer_key');
                    }
                } elseif (is_array($answerKey)) {
                    if ($answerKey === []) {
                        throw new EducationError(422, 'invalid_answer_key');
                    }
                    foreach ($answerKey as $ak) {
                        if (!is_string($ak) || trim($ak) === '') {
                            throw new EducationError(422, 'invalid_answer_key');
                        }
                    }
                } else {
                    throw new EducationError(422, 'invalid_answer_key');
                }
            }
        }
    }

    /**
     * Creates an immutable new published version snapshot after thorough question validation.
     * @return array<string, mixed>
     */
    public function publishAssessment(Scope $scope, string $id, array $input = []): array
    {
        $id = Input::uuid($id);
        return $this->db->transaction(function () use ($scope, $id, $input): array {
            $current = $this->getAssessment($scope, $id);

            $title = isset($input['title']) ? Input::text($input['title'], 200) : $current['title'];
            $instructions = (string) ($input['instructions'] ?? $current['instructions']);
            $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : $current['questions'];

            // Strict question validation
            $this->validateQuestionDefinitions($questions);

            $nextNumber = (int) $this->db->createCommand(
                'SELECT COALESCE(max(version_number), 0) + 1 FROM education.assessment_versions WHERE assessment_id = CAST(:id AS uuid)',
                [':id' => $id],
            )->queryScalar();

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
     * Lists assessments accessible to the authorized student:
     * Only open practice assessments OR tests assigned through active homework / activity assignments.
     * @return array<string, mixed>
     */
    public function listStudentAssessments(Scope $scope): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $this->assertStudentActive($scope);

        $sql = 'SELECT a.id::text, a.title, a.description, a.open_practice,
                       v.id::text AS version_id, v.version_number, v.time_limit_seconds, v.passing_score_percent,
                       COALESCE(jsonb_array_length(v.questions_snapshot), 0) AS questions_count,
                       (SELECT count(*) FROM education.assessment_attempts att WHERE att.version_id = v.id AND att.student_id = CAST(:student AS uuid)) AS student_attempts_count,
                       (SELECT max(att.percentage) FROM education.assessment_attempts att WHERE att.version_id = v.id AND att.student_id = CAST(:student AS uuid)) AS best_percentage
                FROM education.assessments a
                JOIN education.assessment_versions v ON a.latest_version_id = v.id
                WHERE a.organization_id = CAST(:org AS uuid) AND a.status = \'PUBLISHED\'
                  AND (
                      a.open_practice = true
                      OR EXISTS (
                          SELECT 1 FROM education.homework_recipients hr
                          JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
                          WHERE hr.student_id = CAST(:student AS uuid)
                            AND ha.organization_id = CAST(:org AS uuid)
                            AND ha.status = \'PUBLISHED\'
                            AND ha.activity_code = \'platform-assessment\'
                            AND ha.activity_config->>\'assessmentId\' = a.id::text
                      )
                      OR EXISTS (
                          SELECT 1 FROM education.activity_assignments aa
                          WHERE aa.organization_id = CAST(:org AS uuid)
                            AND (
                                aa.student_id = CAST(:student AS uuid)
                                OR aa.group_id IN (
                                    SELECT group_id FROM education.group_students WHERE student_id = CAST(:student AS uuid) AND left_at IS NULL
                                )
                            )
                            AND aa.config->>\'assessmentId\' = a.id::text
                            AND (aa.opens_at IS NULL OR aa.opens_at <= now())
                      )
                  )
                ORDER BY a.title ASC';

        $rows = $this->db->createCommand($sql, [':org' => $scope->organizationId, ':student' => $scope->studentId])->queryAll();

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
     * Starts or resumes an attempt for student with deterministic shuffle snapshot and active assignment validation.
     * Answer keys and explanations are masked!
     * @return array<string, mixed>
     */
    public function startStudentAttempt(Scope $scope, string $assessmentId, array $input = []): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $assessmentId = Input::uuid($assessmentId);
        $this->assertStudentActive($scope);

        return $this->db->transaction(function () use ($scope, $assessmentId, $input): array {
            $assessment = $this->db->createCommand(
                'SELECT a.id, a.title, a.status, a.open_practice, a.latest_version_id, v.id AS version_id, v.version_number,
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
            $openPractice = (bool) ($assessment['open_practice'] ?? false);

            // Verify student assignment authorization and deadline
            $verifiedAssignmentId = $this->resolveAndVerifyStudentAssignment($scope, $assessmentId, $openPractice, $input['assignmentId'] ?? null);

            // Check if there is an active running attempt
            $running = $this->db->createCommand(
                'SELECT id::text, started_at, state, answers, revision, question_order, option_order
                 FROM education.assessment_attempts
                 WHERE version_id = CAST(:vId AS uuid) AND student_id = CAST(:sId AS uuid) AND state = \'IN_PROGRESS\'
                 ORDER BY started_at DESC LIMIT 1',
                [':vId' => $versionId, ':sId' => $scope->studentId],
            )->queryOne();

            if (is_array($running)) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $startedTime = strtotime((string) $running['started_at']);
                $serverElapsed = max(0, $now - $startedTime);
                $timeLimit = $assessment['time_limit_seconds'] !== null ? (int) $assessment['time_limit_seconds'] : null;

                if ($timeLimit !== null && $serverElapsed > ($timeLimit + 30)) {
                    // Grace period expired: auto-finalize the running attempt deterministically
                    $this->finalizeExpiredAttempt($scope, (string) $running['id'], $assessment, $running, $timeLimit);
                } else {
                    // Resume existing running attempt
                    /** @var array<string, mixed> $savedAnswers */
                    $savedAnswers = ActivityHandlerRegistry::json($running['answers'] ?? []);
                    return $this->buildAttemptResponse(
                        $assessment,
                        (string) $running['id'],
                        (string) $running['started_at'],
                        $savedAnswers,
                        (int) ($running['revision'] ?? 1),
                        ActivityHandlerRegistry::json($running['question_order'] ?? []),
                        ActivityHandlerRegistry::json($running['option_order'] ?? []),
                    );
                }
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

            // Generate deterministic shuffle snapshots
            $rawQuestions = ActivityHandlerRegistry::json($assessment['questions_snapshot'] ?? []);
            /** @var list<array<string, mixed>> $questionsList */
            $questionsList = array_values(array_filter($rawQuestions, 'is_array'));

            $questionOrder = [];
            $optionOrder = [];

            if (!empty($assessment['shuffle_questions'])) {
                $indices = range(0, max(0, count($questionsList) - 1));
                shuffle($indices);
                foreach ($indices as $idx) {
                    if (isset($questionsList[$idx])) {
                        $questionOrder[] = (string) ($questionsList[$idx]['id'] ?? '');
                    }
                }
            } else {
                foreach ($questionsList as $q) {
                    $questionOrder[] = (string) ($q['id'] ?? '');
                }
            }

            foreach ($questionsList as $q) {
                $qId = (string) ($q['id'] ?? '');
                $opts = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                if (!empty($assessment['shuffle_options']) && count($opts) > 1) {
                    $optIndices = range(0, count($opts) - 1);
                    shuffle($optIndices);
                    $optIds = [];
                    foreach ($optIndices as $oIdx) {
                        $optIds[] = (string) ($opts[$oIdx]['id'] ?? '');
                    }
                    $optionOrder[$qId] = $optIds;
                } else {
                    $optIds = [];
                    foreach ($opts as $opt) {
                        $optIds[] = (string) ($opt['id'] ?? '');
                    }
                    $optionOrder[$qId] = $optIds;
                }
            }

            $seed = random_int(1, 99999999);

            $attempt = $this->db->createCommand(
                'INSERT INTO education.assessment_attempts
                 (version_id, student_id, organization_id, assignment_id, state, seed, started_at, answers, revision, question_order, option_order)
                 VALUES
                 (CAST(:vId AS uuid), CAST(:sId AS uuid), CAST(:org AS uuid), CAST(:assignId AS uuid), \'IN_PROGRESS\', :seed, now(), \'{}\'::jsonb, 1, CAST(:qOrder AS jsonb), CAST(:optOrder AS jsonb))
                 RETURNING id::text, started_at, state',
                [
                    ':vId' => $versionId,
                    ':sId' => $scope->studentId,
                    ':org' => $scope->organizationId,
                    ':assignId' => $verifiedAssignmentId,
                    ':seed' => $seed,
                    ':qOrder' => json_encode($questionOrder, JSON_THROW_ON_ERROR),
                    ':optOrder' => json_encode($optionOrder, JSON_THROW_ON_ERROR),
                ],
            )->queryOne();

            if (!is_array($attempt)) {
                throw new EducationError(500, 'database_error');
            }

            return $this->buildAttemptResponse($assessment, (string) $attempt['id'], (string) $attempt['started_at'], [], 1, $questionOrder, $optionOrder);
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
                    att.manual_review_notes, att.revision, att.question_order, att.option_order,
                    att.assignment_id,
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

        return $this->maskStudentAttempt($attempt);
    }

    /**
     * Projects and masks student attempt: strips answerKey and explanation unless evaluated and permitted by reveal policy.
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public function maskStudentAttempt(array $attempt): array
    {
        $questionsRaw = ActivityHandlerRegistry::json($attempt['questions_snapshot'] ?? $attempt['questions'] ?? []);
        /** @var list<array<string, mixed>> $questionsList */
        $questionsList = array_values(array_filter($questionsRaw, 'is_array'));

        $questionsById = [];
        foreach ($questionsList as $q) {
            $questionsById[(string) ($q['id'] ?? '')] = $q;
        }

        $questionOrder = ActivityHandlerRegistry::json($attempt['question_order'] ?? []);
        $optionOrder = ActivityHandlerRegistry::json($attempt['option_order'] ?? []);

        $orderedQuestions = [];
        if (is_array($questionOrder) && $questionOrder !== []) {
            foreach ($questionOrder as $qId) {
                if (isset($questionsById[$qId])) {
                    $orderedQuestions[] = $questionsById[$qId];
                }
            }
        } else {
            $orderedQuestions = $questionsList;
        }

        $revealPolicy = (string) ($attempt['reveal_answers_policy'] ?? $attempt['revealAnswersPolicy'] ?? 'after_grading');
        $isEvaluated = in_array($attempt['state'] ?? '', ['EVALUATED'], true);

        // Check deadline if policy is 'deadline'
        $deadlinePassed = false;
        if ($revealPolicy === 'deadline') {
            if (isset($attempt['deadline_passed'])) {
                $deadlinePassed = (bool) $attempt['deadline_passed'];
            } elseif (isset($attempt['due_at']) && is_string($attempt['due_at'])) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $deadlinePassed = $now >= strtotime($attempt['due_at']);
            } elseif (isset($attempt['dueAt']) && is_string($attempt['dueAt'])) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $deadlinePassed = $now >= strtotime($attempt['dueAt']);
            } elseif (!empty($attempt['assignment_id'])) {
                $deadline = $this->db->createCommand(
                    'SELECT due_at FROM education.activity_assignments WHERE id = CAST(:id AS uuid)
                     UNION ALL
                     SELECT due_at FROM education.homework_assignments ha
                     WHERE ha.id = CAST(:id AS uuid)
                     LIMIT 1',
                    [':id' => $attempt['assignment_id']],
                )->queryScalar();

                if (is_string($deadline)) {
                    $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                    $deadlinePassed = $now >= strtotime($deadline);
                }
            }
        }

        $shouldReveal = match ($revealPolicy) {
            'immediate' => $isEvaluated,
            'after_grading' => $isEvaluated,
            'deadline' => $isEvaluated && $deadlinePassed,
            default => false,
        };

        $publicQuestions = [];
        foreach ($orderedQuestions as $q) {
            $qId = (string) ($q['id'] ?? '');
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];

            if (isset($optionOrder[$qId]) && is_array($optionOrder[$qId])) {
                $optsById = [];
                foreach ($options as $opt) {
                    $optsById[(string) ($opt['id'] ?? '')] = $opt;
                }
                $orderedOpts = [];
                foreach ($optionOrder[$qId] as $optId) {
                    if (isset($optsById[$optId])) {
                        $orderedOpts[] = $optsById[$optId];
                    }
                }
                $options = $orderedOpts !== [] ? $orderedOpts : $options;
            }

            $publicQ = [
                'id' => $qId,
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
            if ($shouldReveal) {
                $publicQ['correctAnswer'] = $q['answerKey'] ?? null;
                $publicQ['explanation'] = (string) ($q['explanation'] ?? '');
            }
            $publicQuestions[] = $publicQ;
        }

        // Mask result evaluations: strip correctAnswer and explanation unless shouldReveal is true
        $rawResult = ActivityHandlerRegistry::json($attempt['result'] ?? []);
        $maskedResult = $rawResult;
        if (isset($maskedResult['questions']) && is_array($maskedResult['questions'])) {
            $maskedEvaluations = [];
            foreach ($maskedResult['questions'] as $ev) {
                if (!is_array($ev)) {
                    continue;
                }
                $sanitized = [
                    'questionId' => $ev['questionId'] ?? '',
                    'type' => $ev['type'] ?? 'single_choice',
                    'correct' => $ev['correct'] ?? false,
                    'earnedPoints' => $ev['earnedPoints'] ?? 0,
                    'possiblePoints' => $ev['possiblePoints'] ?? 1,
                    'clientAnswer' => $ev['clientAnswer'] ?? null,
                ];
                if ($shouldReveal) {
                    $sanitized['correctAnswer'] = $ev['correctAnswer'] ?? null;
                    $sanitized['explanation'] = $ev['explanation'] ?? '';
                }
                $maskedEvaluations[] = $sanitized;
            }
            $maskedResult['questions'] = $maskedEvaluations;
        }

        return [
            'id' => (string) ($attempt['id'] ?? ''),
            'versionId' => (string) ($attempt['version_id'] ?? $attempt['versionId'] ?? ''),
            'title' => (string) ($attempt['version_title'] ?? $attempt['title'] ?? ''),
            'instructions' => (string) ($attempt['instructions'] ?? ''),
            'state' => (string) ($attempt['state'] ?? 'IN_PROGRESS'),
            'revision' => (int) ($attempt['revision'] ?? 1),
            'startedAt' => (string) ($attempt['started_at'] ?? $attempt['startedAt'] ?? ''),
            'submittedAt' => !empty($attempt['submitted_at']) ? (string) $attempt['submitted_at'] : (!empty($attempt['submittedAt']) ? (string) $attempt['submittedAt'] : null),
            'timeLimitSeconds' => isset($attempt['time_limit_seconds']) ? (int) $attempt['time_limit_seconds'] : (isset($attempt['timeLimitSeconds']) ? (int) $attempt['timeLimitSeconds'] : null),
            'passingScorePercent' => (int) ($attempt['passing_score_percent'] ?? $attempt['passingScorePercent'] ?? 60),
            'questions' => $publicQuestions,
            'answers' => ActivityHandlerRegistry::json($attempt['answers'] ?? []),
            'result' => $maskedResult,
            'score' => isset($attempt['score']) && $attempt['score'] !== null ? (int) $attempt['score'] : null,
            'maxScore' => isset($attempt['max_score']) && $attempt['max_score'] !== null ? (int) $attempt['max_score'] : (isset($attempt['maxScore']) && $attempt['maxScore'] !== null ? (int) $attempt['maxScore'] : null),
            'percentage' => isset($attempt['percentage']) && $attempt['percentage'] !== null ? (float) $attempt['percentage'] : null,
            'passed' => isset($attempt['passed']) && $attempt['passed'] !== null ? (bool) $attempt['passed'] : null,
            'manualReviewNotes' => isset($attempt['manual_review_notes']) && $attempt['manual_review_notes'] !== null ? (string) $attempt['manual_review_notes'] : null,
        ];
    }

    /**
     * Autosave attempt with optimistic concurrency compare-and-swap and authoritative time enforcement.
     * @return array<string, mixed>
     */
    public function saveStudentAttempt(Scope $scope, string $attemptId, array $input): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
        $clientRevision = isset($input['revision']) ? (int) $input['revision'] : null;

        $attempt = $this->db->createCommand(
            'SELECT att.id, att.state, att.started_at, att.revision, v.time_limit_seconds
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
            throw new EducationError(409, 'attempt_not_editable');
        }

        // Time limit check
        $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
        $startedTime = strtotime((string) $attempt['started_at']);
        $serverElapsed = max(0, $now - $startedTime);
        $timeLimit = $attempt['time_limit_seconds'] !== null ? (int) $attempt['time_limit_seconds'] : null;
        if ($timeLimit !== null && $serverElapsed > ($timeLimit + 30)) {
            throw new EducationError(409, 'attempt_time_expired');
        }

        // Optimistic revision compare-and-swap
        $currentRevision = (int) ($attempt['revision'] ?? 1);
        if ($clientRevision !== null && $clientRevision !== $currentRevision) {
            throw new EducationError(409, 'revision_conflict');
        }

        $nextRevision = $currentRevision + 1;
        $this->db->createCommand(
            'UPDATE education.assessment_attempts
             SET answers = CAST(:answers AS jsonb), revision = :nextRev
             WHERE id = CAST(:id AS uuid)',
            [
                ':answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                ':nextRev' => $nextRevision,
                ':id' => $attemptId,
            ],
        )->execute();

        return ['saved' => true, 'revision' => $nextRevision];
    }

    /**
     * Submits and grades the attempt idempotently.
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
                'SELECT att.id, att.version_id, att.state, att.started_at, att.revision,
                        att.answers, att.assignment_id,
                        v.questions_snapshot, v.passing_score_percent, v.reveal_answers_policy,
                        v.time_limit_seconds
                 FROM education.assessment_attempts att
                 JOIN education.assessment_versions v ON att.version_id = v.id
                 WHERE att.id = CAST(:id AS uuid) AND att.student_id = CAST(:sId AS uuid) AND att.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF att',
                [':id' => $attemptId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
            )->queryOne();

            if (!is_array($attempt)) {
                throw new EducationError(404, 'not_found');
            }

            // Idempotent submit
            if ($attempt['state'] !== 'IN_PROGRESS') {
                return $this->getStudentAttempt($scope, $attemptId);
            }

            $answers = isset($input['answers']) && is_array($input['answers'])
                ? $input['answers']
                : ActivityHandlerRegistry::json($attempt['answers'] ?? []);

            $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
            $startedTime = strtotime((string) $attempt['started_at']);
            $serverElapsed = max(0, $now - $startedTime);
            $timeLimit = $attempt['time_limit_seconds'] !== null ? (int) $attempt['time_limit_seconds'] : null;
            $elapsedSeconds = $timeLimit !== null ? min($serverElapsed, $timeLimit) : $serverElapsed;

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
     * Teacher review & manual score override with audit history.
     * @return array<string, mixed>
     */
    public function reviewAttempt(Scope $scope, string $attemptId, array $input): array
    {
        $attemptId = Input::uuid($attemptId);
        $notes = isset($input['manualReviewNotes']) && is_string($input['manualReviewNotes'])
            ? trim($input['manualReviewNotes'])
            : (isset($input['feedback']) && is_string($input['feedback'])
                ? trim($input['feedback'])
                : (isset($input['notes']) && is_string($input['notes']) ? trim($input['notes']) : null));
        $score = isset($input['score']) && is_int($input['score']) ? $input['score'] : null;

        return $this->db->transaction(function () use ($scope, $attemptId, $notes, $score): array {
            $attempt = $this->db->createCommand(
                'SELECT att.id, att.max_score, att.score, att.percentage, att.passed, att.state,
                        att.review_history, v.passing_score_percent
                 FROM education.assessment_attempts att
                 JOIN education.assessment_versions v ON att.version_id = v.id
                 WHERE att.id = CAST(:id AS uuid) AND att.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF att',
                [':id' => $attemptId, ':org' => $scope->organizationId],
            )->queryOne();

            if (!is_array($attempt)) {
                throw new EducationError(404, 'not_found');
            }
            if ($attempt['state'] === 'IN_PROGRESS') {
                throw new EducationError(409, 'cannot_review_in_progress_attempt');
            }

            $maxScore = (int) ($attempt['max_score'] ?? 0);
            if ($score !== null) {
                if ($score < 0 || ($maxScore > 0 && $score > $maxScore)) {
                    throw new EducationError(422, 'invalid_score_bounds');
                }
            }

            $finalScore = $score ?? (int) ($attempt['score'] ?? 0);
            $percentage = $maxScore > 0 ? round(($finalScore / $maxScore) * 100, 2) : 0.0;
            $passingScore = (int) ($attempt['passing_score_percent'] ?? 60);
            $passed = $percentage >= $passingScore;

            $history = ActivityHandlerRegistry::json($attempt['review_history'] ?? []);
            if (!is_array($history)) {
                $history = [];
            }
            $history[] = [
                'reviewedByPersonId' => $scope->personId,
                'reviewedAt' => date(DATE_ATOM),
                'previousScore' => $attempt['score'] !== null ? (int) $attempt['score'] : null,
                'newScore' => $finalScore,
                'score' => $finalScore,
                'notes' => $notes,
                'feedback' => $notes,
            ];

            $this->db->createCommand(
                'UPDATE education.assessment_attempts
                 SET manual_review_notes = COALESCE(:notes, manual_review_notes),
                     score = :score,
                     percentage = :percentage,
                     passed = :passed,
                     reviewed_by_person_id = CAST(:reviewer AS uuid),
                     review_history = CAST(:history AS jsonb),
                     state = \'EVALUATED\'
                 WHERE id = CAST(:id AS uuid)',
                [
                    ':notes' => $notes,
                    ':score' => $finalScore,
                    ':percentage' => $percentage,
                    ':passed' => $passed ? 'true' : 'false',
                    ':reviewer' => $scope->personId,
                    ':history' => json_encode($history, JSON_THROW_ON_ERROR),
                    ':id' => $attemptId,
                ],
            )->execute();

            return [
                'success' => true,
                'score' => $finalScore,
                'maxScore' => $maxScore,
                'percentage' => $percentage,
                'passed' => $passed,
                'state' => 'EVALUATED',
                'reviewHistory' => $history,
            ];
        });
    }

    private function assertStudentActive(Scope $scope): void
    {
        $student = $this->db->createCommand(
            'SELECT s.id, sa.revoked_at
             FROM education.students s
             LEFT JOIN education.student_accounts sa ON s.id = sa.student_id AND sa.organization_id = s.organization_id
             WHERE s.id = CAST(:sId AS uuid) AND s.organization_id = CAST(:org AS uuid)',
            [':sId' => $scope->studentId, ':org' => $scope->organizationId],
        )->queryOne();

        if (!is_array($student) || $student['revoked_at'] !== null) {
            throw new EducationError(403, 'access_denied');
        }
    }

    private function resolveAndVerifyStudentAssignment(Scope $scope, string $assessmentId, bool $openPractice, ?string $clientAssignmentId): ?string
    {
        // 1. Check Homework assignment
        $hw = $this->db->createCommand(
            'SELECT ha.id::text, ha.due_at, ha.allow_late
             FROM education.homework_recipients hr
             JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
             WHERE hr.student_id = CAST(:sId AS uuid)
               AND ha.organization_id = CAST(:org AS uuid)
               AND ha.status = \'PUBLISHED\'
               AND ha.activity_code = \'platform-assessment\'
               AND ha.activity_config->>\'assessmentId\' = :aId
             ORDER BY ha.created_at DESC LIMIT 1',
            [':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
        )->queryOne();

        // 2. Check Activity assignment
        $act = $this->db->createCommand(
            'SELECT aa.id::text, aa.opens_at, aa.due_at
             FROM education.activity_assignments aa
             WHERE aa.organization_id = CAST(:org AS uuid)
               AND (
                   aa.student_id = CAST(:sId AS uuid)
                   OR aa.group_id IN (
                       SELECT group_id FROM education.group_students WHERE student_id = CAST(:sId AS uuid) AND left_at IS NULL
                   )
               )
               AND aa.config->>\'assessmentId\' = :aId
             ORDER BY aa.created_at DESC LIMIT 1',
            [':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
        )->queryOne();

        $now = $this->clock ? $this->clock->now()->getTimestamp() : time();

        if (is_array($hw)) {
            if ($hw['due_at'] !== null && empty($hw['allow_late'])) {
                if ($now > (strtotime((string) $hw['due_at']) + 30)) {
                    throw new EducationError(409, 'assignment_deadline_passed');
                }
            }
            return (string) $hw['id'];
        }

        if (is_array($act)) {
            if ($act['opens_at'] !== null && $now < strtotime((string) $act['opens_at'])) {
                throw new EducationError(403, 'assignment_not_started');
            }
            if ($act['due_at'] !== null && $now > (strtotime((string) $act['due_at']) + 30)) {
                throw new EducationError(409, 'assignment_deadline_passed');
            }
            return (string) $act['id'];
        }

        if (!$openPractice) {
            throw new EducationError(403, 'assessment_not_assigned');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $running
     */
    private function finalizeExpiredAttempt(Scope $scope, string $attemptId, array $assessment, array $running, int $timeLimit): void
    {
        $answers = ActivityHandlerRegistry::json($running['answers'] ?? []);
        $questions = ActivityHandlerRegistry::json($assessment['questions_snapshot'] ?? []);
        $passingScore = (int) ($assessment['passing_score_percent'] ?? 60);

        $handler = new AssessmentActivityHandler();
        $evalAttempt = [
            'config' => ['passingScorePercent' => $passingScore],
            'exercisePayload' => ['questions' => $questions, 'passingScorePercent' => $passingScore],
        ];
        $evaluated = $handler->evaluate($evalAttempt, [
            'answers' => $answers,
            'elapsedSeconds' => $timeLimit,
        ]);

        /** @var array{score: int|float, maxScore: int|float, percentage: int|float, passed: bool} $metrics */
        $metrics = is_array($evaluated['metrics'] ?? null) ? $evaluated['metrics'] : ['score' => 0, 'maxScore' => 0, 'percentage' => 0.0, 'passed' => false];

        $this->db->createCommand(
            'UPDATE education.assessment_attempts
             SET state = \'EVALUATED\', submitted_at = now(),
                 result = CAST(:result AS jsonb),
                 score = :score, max_score = :maxScore, percentage = :percentage, passed = :passed
             WHERE id = CAST(:id AS uuid)',
            [
                ':result' => json_encode($evaluated, JSON_THROW_ON_ERROR),
                ':score' => (int) $metrics['score'],
                ':maxScore' => (int) $metrics['maxScore'],
                ':percentage' => (float) $metrics['percentage'],
                ':passed' => $metrics['passed'] ? 'true' : 'false',
                ':id' => $attemptId,
            ],
        )->execute();
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $answers
     * @param list<string> $questionOrder
     * @param array<string, list<string>> $optionOrder
     * @return array<string, mixed>
     */
    private function buildAttemptResponse(
        array $assessment,
        string $attemptId,
        string $startedAt,
        array $answers,
        int $revision,
        array $questionOrder,
        array $optionOrder,
    ): array {
        $questionsRaw = ActivityHandlerRegistry::json($assessment['questions_snapshot'] ?? []);
        /** @var list<array<string, mixed>> $questionsList */
        $questionsList = array_values(array_filter($questionsRaw, 'is_array'));

        $questionsById = [];
        foreach ($questionsList as $q) {
            $questionsById[(string) ($q['id'] ?? '')] = $q;
        }

        $orderedQuestions = [];
        if ($questionOrder !== []) {
            foreach ($questionOrder as $qId) {
                if (isset($questionsById[$qId])) {
                    $orderedQuestions[] = $questionsById[$qId];
                }
            }
        } else {
            $orderedQuestions = $questionsList;
        }

        $publicQuestions = [];
        foreach ($orderedQuestions as $q) {
            $qId = (string) ($q['id'] ?? '');
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];

            if (isset($optionOrder[$qId]) && is_array($optionOrder[$qId])) {
                $optsById = [];
                foreach ($options as $opt) {
                    $optsById[(string) ($opt['id'] ?? '')] = $opt;
                }
                $orderedOpts = [];
                foreach ($optionOrder[$qId] as $optId) {
                    if (isset($optsById[$optId])) {
                        $orderedOpts[] = $optsById[$optId];
                    }
                }
                $options = $orderedOpts !== [] ? $orderedOpts : $options;
            }

            $publicQ = [
                'id' => $qId,
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
            'revision' => $revision,
            'startedAt' => $startedAt,
            'timeLimitSeconds' => $assessment['time_limit_seconds'] !== null ? (int) $assessment['time_limit_seconds'] : null,
            'passingScorePercent' => (int) $assessment['passing_score_percent'],
            'questions' => $publicQuestions,
            'answers' => $answers,
        ];
    }
}
