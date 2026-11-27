<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Activity\Application\ActivityEngineService;
use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class AssessmentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ActivityHandlerRegistry $activityHandlers,
        private ?ClockInterface $clock = null,
        private ?ActivityResultSink $activityResults = null,
        private ?ActivityEngineService $engine = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listAssessments(Scope $scope, array $query = [], ?AuthenticatedIdentity $identity = null): array
    {
        if ($this->engine !== null && $identity !== null) {
            try {
                $res = $this->engine->listActivities($identity, $scope->organizationId, [
                    'pluginCode' => 'platform-assessment',
                    'status' => isset($query['status']) && is_string($query['status']) ? $query['status'] : null,
                    'q' => isset($query['search']) && is_string($query['search']) ? $query['search'] : null,
                ]);
                return [
                    'items' => array_map(fn(array $row): array => $this->mapActivityListRow($row), $res['items'] ?? []),
                ];
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
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
    public function createAssessment(Scope $scope, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        if ($this->engine !== null && $identity !== null) {
            try {
                $payload = $this->mapEducationInputToActivityPayload($input, false);
                $activity = $this->engine->createActivity($identity, $scope->organizationId, $payload);
                return $this->mapActivityToAssessment($activity);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
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

                $this->syncAssessmentToActivityEngine(
                    $scope,
                    $assessmentId,
                    $title,
                    $description,
                    'DRAFT',
                    $openPractice,
                    (string) $version['id'],
                    1,
                    (string) ($input['instructions'] ?? ''),
                    $timeLimit,
                    $passingScore,
                    $maxAttempts,
                    $shuffleQ,
                    $shuffleOpt,
                    $revealPolicy,
                    $gradingPolicy,
                    $questions,
                );
            }

            return $this->getAssessment($scope, $assessmentId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssessment(Scope $scope, string $id, ?AuthenticatedIdentity $identity = null): array
    {
        $id = Input::uuid($id);
        if ($this->engine !== null && $identity !== null) {
            try {
                $activity = $this->engine->getActivity($identity, $scope->organizationId, $id);
                return $this->mapActivityToAssessment($activity);
            } catch (HttpError $e) {
                if ($e->status !== 404) {
                    throw new EducationError($e->status, $e->reason);
                }
                // Fall back to legacy table only for pre-migration assessments not yet in activity.items
            }
        }
        $row = $this->db->createCommand(
            'SELECT a.id::text, a.title, a.description, a.status, a.open_practice,
                    a.latest_version_id::text, a.created_at, a.updated_at,
                    v.version_number, v.instructions, v.time_limit_seconds, v.passing_score_percent,
                    v.max_attempts, v.shuffle_questions, v.shuffle_options, v.reveal_answers_policy,
                    v.grading_policy, v.questions_snapshot
             FROM education.assessments a
             LEFT JOIN education.assessment_versions v ON v.id = COALESCE(a.latest_version_id, (
                 SELECT id FROM education.assessment_versions WHERE assessment_id = a.id ORDER BY version_number DESC LIMIT 1
             ))
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
    public function updateAssessment(Scope $scope, string $id, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        $id = Input::uuid($id);
        if ($this->engine !== null && $identity !== null) {
            try {
                $current = $this->engine->getActivity($identity, $scope->organizationId, $id);
                if (isset($input['status']) && $input['status'] === 'PUBLISHED' && ($current['status'] ?? '') !== 'PUBLISHED') {
                    throw new EducationError(422, 'use_publish_endpoint');
                }
                $payload = $this->mapEducationInputToActivityPayload($input, false, $current);
                $activity = $this->engine->updateActivity($identity, $scope->organizationId, $id, $payload);
                return $this->mapActivityToAssessment($activity);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
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

                $this->syncAssessmentToActivityEngine(
                    $scope,
                    $id,
                    (string) $title,
                    (string) $description,
                    (string) $status,
                    (bool) $openPractice,
                    (string) $current['latestVersionId'],
                    (int) ($current['versionNumber'] ?? 1),
                    (string) ($input['instructions'] ?? $current['instructions']),
                    is_numeric($timeLimit) ? (int) $timeLimit : null,
                    (int) $passingScore,
                    (int) $maxAttempts,
                    (bool) $shuffleQ,
                    (bool) $shuffleOpt,
                    (string) $revealPolicy,
                    (string) $gradingPolicy,
                    is_array($questions) ? $questions : [],
                );
            } else {
                $this->syncAssessmentMetadataToActivityEngine(
                    $scope->organizationId,
                    $id,
                    (string) $title,
                    (string) $description,
                    (string) $status,
                    (bool) $openPractice,
                );
            }

            return $this->getAssessment($scope, $id);
        });
    }

    /**
     * Validates question structure strictly at publication.
     * @param array<array-key, mixed> $questions
     */
    public function validateQuestionDefinitions(array $questions): void
    {
        try {
            AssessmentActivityPlugin::validateQuestions($questions);
        } catch (HttpError $e) {
            $reason = $e->reason === 'invalid_question_points' ? 'invalid_input' : $e->reason;
            throw new EducationError($e->status, $reason);
        }
    }

    /**
     * Creates an immutable new published version snapshot after thorough question validation.
     * @return array<string, mixed>
     */
    public function publishAssessment(Scope $scope, string $id, array $input = [], ?AuthenticatedIdentity $identity = null): array
    {
        $id = Input::uuid($id);
        if ($this->engine !== null && $identity !== null) {
            try {
                $current = $this->engine->getActivity($identity, $scope->organizationId, $id);
                $payload = $this->mapEducationInputToActivityPayload($input, true, $current);
                $activity = $this->engine->updateActivity($identity, $scope->organizationId, $id, $payload);
                return $this->mapActivityToAssessment($activity);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
        return $this->db->transaction(function () use ($scope, $id, $input): array {
            $current = $this->getAssessment($scope, $id);

            $title = isset($input['title']) ? Input::text($input['title'], 200) : $current['title'];
            $instructions = (string) ($input['instructions'] ?? $current['instructions']);
            $rawQuestions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : $current['questions'];
            /** @var array<array-key, mixed> $questions */
            $questions = is_array($rawQuestions) ? $rawQuestions : [];

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

            $this->syncAssessmentToActivityEngine(
                $scope,
                $id,
                (string) $title,
                (string) ($current['description'] ?? ''),
                'PUBLISHED',
                (bool) ($current['openPractice'] ?? false),
                $versionId,
                $nextNumber,
                $instructions,
                is_numeric($timeLimit) ? (int) $timeLimit : null,
                (int) $passingScore,
                (int) $maxAttempts,
                (bool) $shuffleQ,
                (bool) $shuffleOpt,
                (string) $revealPolicy,
                (string) $gradingPolicy,
                $questions,
            );

            return $this->getAssessment($scope, $id);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(Scope $scope, string $assessmentId, ?AuthenticatedIdentity $identity = null): array
    {
        $id = Input::uuid($assessmentId);
        if ($this->engine !== null && $identity !== null) {
            try {
                return $this->engine->listVersions($identity, $scope->organizationId, $id);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
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
    public function listQuestionBank(Scope $scope, array $query = [], ?AuthenticatedIdentity $identity = null): array
    {
        if ($this->engine !== null && $identity !== null) {
            try {
                return $this->engine->listQuestionBank($identity, $scope->organizationId, $query);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
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
    public function createQuestionBankItem(Scope $scope, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        if ($this->engine !== null && $identity !== null) {
            try {
                return $this->engine->createQuestionBankItem($identity, $scope->organizationId, $input);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
        $type = (string) ($input['questionType'] ?? 'single_choice');
        if (!in_array($type, ['single_choice', 'multi_choice', 'true_false', 'short_text', 'long_text', 'numeric', 'matching', 'ordering'], true)) {
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

        $this->db->createCommand(
            'INSERT INTO activity.question_bank
             (id, organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points)
             VALUES
             (CAST(:id AS uuid), CAST(:org AS uuid), CAST(:author AS uuid), :cat, CAST(:tags AS jsonb), :diff, :type, CAST(:content AS jsonb), CAST(:answerKey AS jsonb), :explanation, :points)
             ON CONFLICT (id) DO NOTHING',
            [
                ':id' => (string) $row['id'],
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
        )->execute();

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

    public function deleteQuestionBankItem(Scope $scope, string $id, ?AuthenticatedIdentity $identity = null): void
    {
        $id = Input::uuid($id);
        if ($this->engine !== null && $identity !== null) {
            try {
                $this->engine->archiveQuestionBankItem($identity, $scope->organizationId, $id, false);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }
        $this->db->createCommand(
            'DELETE FROM education.question_bank WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $id, ':org' => $scope->organizationId],
        )->execute();
        $this->db->createCommand(
            'UPDATE activity.question_bank SET archived_at = COALESCE(archived_at, now()), updated_at = now()
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $id, ':org' => $scope->organizationId],
        )->execute();
    }

    /**
     * Lists assessments accessible to the authorized student:
     * Only open practice assessments OR tests assigned through active homework / activity assignments.
     * @return array<string, mixed>
     */
    public function listStudentAssessments(Scope $scope, ?AuthenticatedIdentity $identity = null): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $this->assertStudentActive($scope);

        if ($this->engine !== null && $identity !== null) {
            try {
                $activitiesRes = $this->engine->listActivities($identity, $scope->organizationId, [
                    'pluginCode' => 'platform-assessment',
                    'status' => 'PUBLISHED',
                ]);
                $assignmentsRes = $this->engine->listAssignments($identity, $scope->organizationId);
                $assignmentsByActivity = [];
                foreach ($assignmentsRes['items'] ?? [] as $asgn) {
                    if (!is_array($asgn) || ($asgn['pluginCode'] ?? '') !== 'platform-assessment') {
                        continue;
                    }
                    $aid = (string) ($asgn['activityId'] ?? '');
                    if ($aid !== '' && !isset($assignmentsByActivity[$aid])) {
                        $assignmentsByActivity[$aid] = $asgn;
                    }
                }

                $items = [];
                foreach ($activitiesRes['items'] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $aid = (string) ($row['id'] ?? '');
                    $asgn = $assignmentsByActivity[$aid] ?? null;
                    $items[] = [
                        'id' => $aid,
                        'title' => (string) ($row['title'] ?? ''),
                        'description' => (string) ($row['description'] ?? ''),
                        'versionId' => (string) ($asgn['versionId'] ?? $row['activeVersionId'] ?? ''),
                        'versionNumber' => (int) ($asgn['versionNumber'] ?? $row['versionNumber'] ?? 1),
                        'timeLimitSeconds' => $asgn['timeLimitSeconds'] ?? $row['timeLimitSeconds'] ?? null,
                        'passingScorePercent' => (int) ($asgn['passingScorePercent'] ?? $row['passingScorePercent'] ?? 60),
                        'maxAttempts' => (int) ($asgn['maxAttempts'] ?? $row['maxAttempts'] ?? 0),
                        'questionsCount' => (int) ($row['questionsCount'] ?? 0),
                        'studentAttemptsCount' => (int) ($asgn['myAttemptsCount'] ?? $row['myAttemptsCount'] ?? 0),
                        'bestPercentage' => $asgn['myBestPercentage'] ?? $row['myBestPercentage'] ?? null,
                        'assignmentId' => $asgn['id'] ?? null,
                        'activeAttemptId' => $asgn['activeAttemptId'] ?? null,
                        'opensAt' => $asgn['opensAt'] ?? null,
                        'dueAt' => $asgn['dueAt'] ?? null,
                    ];
                }
                return ['items' => $items];
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }

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
                                    SELECT group_id FROM education.group_students WHERE student_id = CAST(:student AS uuid) AND organization_id = CAST(:org AS uuid)
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
    public function startStudentAttempt(Scope $scope, string $assessmentId, array $input = [], ?AuthenticatedIdentity $identity = null): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $assessmentId = Input::uuid($assessmentId);
        $this->assertStudentActive($scope);

        if ($this->engine !== null && $identity !== null) {
            try {
                $attempt = $this->engine->startAttempt($identity, $scope->organizationId, $assessmentId, [
                    ...$input,
                    'resumeActive' => true,
                ]);
                return $this->mapEngineAttemptToLegacy($attempt);
            } catch (HttpError $e) {
                throw new EducationError($e->status, $e->reason);
            }
        }

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
            $assignmentId = isset($input['assignmentId']) && is_string($input['assignmentId']) ? $input['assignmentId'] : null;
            $assignmentInfo = $this->resolveAndVerifyStudentAssignment($scope, $assessmentId, $openPractice, $assignmentId);
            $verifiedAssignmentId = $assignmentInfo['assignmentId'];
            $pinnedVersionId = $assignmentInfo['versionId'];

            if ($pinnedVersionId !== null) {
                $verRow = $this->db->createCommand(
                    'SELECT v.id AS version_id, v.version_number, v.title AS version_title, v.instructions,
                            v.time_limit_seconds, v.passing_score_percent, v.max_attempts, v.shuffle_questions,
                            v.shuffle_options, v.reveal_answers_policy, v.questions_snapshot
                     FROM education.assessment_versions v
                     WHERE v.id = CAST(:vId AS uuid) AND v.assessment_id = CAST(:aId AS uuid)',
                    [':vId' => $pinnedVersionId, ':aId' => $assessmentId],
                )->queryOne();
                if (is_array($verRow)) {
                    $versionId = (string) $verRow['version_id'];
                    $assessment = array_merge($assessment, $verRow);
                }
            }

            // Check if there is an active running attempt scoped to assignment
            $running = $this->db->createCommand(
                'SELECT id::text, started_at, state, answers, revision, question_order, option_order
                 FROM education.assessment_attempts
                 WHERE version_id = CAST(:vId AS uuid)
                   AND student_id = CAST(:sId AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND state = \'IN_PROGRESS\'
                   AND ' . ($verifiedAssignmentId !== null ? 'assignment_id = CAST(:assignId AS uuid)' : 'assignment_id IS NULL') . '
                 ORDER BY started_at DESC LIMIT 1',
                array_merge(
                    [':vId' => $versionId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
                    $verifiedAssignmentId !== null ? [':assignId' => $verifiedAssignmentId] : [],
                ),
            )->queryOne();

            if (is_array($running)) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $startedTime = strtotime((string) $running['started_at']);
                $serverElapsed = max(0, $now - ($startedTime !== false ? $startedTime : $now));
                $timeLimit = $assessment['time_limit_seconds'] !== null ? (int) $assessment['time_limit_seconds'] : null;

                if ($timeLimit !== null && $serverElapsed > $timeLimit) {
                    // Time limit expired: auto-finalize the running attempt deterministically without accepting new edits
                    $this->finalizeExpiredAttempt($scope, (string) $running['id'], $assessment, $running, $timeLimit);
                } else {
                    // Resume existing running attempt using its pinned version questions and state
                    return $this->getStudentAttempt($scope, (string) $running['id']);
                }
            }

            // Check attempt limits scoped to assignment
            $count = (int) $this->db->createCommand(
                'SELECT count(*) FROM education.assessment_attempts
                 WHERE version_id = CAST(:vId AS uuid)
                   AND student_id = CAST(:sId AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND ' . ($verifiedAssignmentId !== null ? 'assignment_id = CAST(:assignId AS uuid)' : 'assignment_id IS NULL'),
                array_merge(
                    [':vId' => $versionId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
                    $verifiedAssignmentId !== null ? [':assignId' => $verifiedAssignmentId] : [],
                ),
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
                /** @var list<array<string, mixed>> $opts */
                $opts = isset($q['options']) && is_array($q['options']) ? array_values(array_filter($q['options'], 'is_array')) : [];
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

            $timeLimitSec = $assessment['time_limit_seconds'] !== null ? (int) $assessment['time_limit_seconds'] : null;
            $expiresAt = $timeLimitSec !== null && $timeLimitSec > 0 ? gmdate(DATE_ATOM, time() + $timeLimitSec) : null;
            $this->syncAssessmentAttemptStartToEngine(
                $scope,
                (string) $attempt['id'],
                $assessmentId,
                $versionId,
                $verifiedAssignmentId,
                $seed,
                [
                    'shuffleQuestions' => (bool) ($assessment['shuffle_questions'] ?? false),
                    'shuffleOptions' => (bool) ($assessment['shuffle_options'] ?? false),
                    'revealAnswersPolicy' => (string) ($assessment['reveal_answers_policy'] ?? 'after_grading'),
                    'passingScorePercent' => (int) ($assessment['passing_score_percent'] ?? 60),
                    'timeLimitSeconds' => $timeLimitSec,
                ],
                ['questionOrder' => $questionOrder, 'optionOrder' => $optionOrder],
                $expiresAt,
            );

            return $this->getStudentAttempt($scope, (string) $attempt['id']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getStudentAttempt(Scope $scope, string $attemptId, ?AuthenticatedIdentity $identity = null): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        if ($this->engine !== null && $identity !== null) {
            try {
                $attempt = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);
                return $this->mapEngineAttemptToLegacy($attempt);
            } catch (HttpError $e) {
                if ($e->status !== 404 || $e->reason !== 'attempt_not_found') {
                    throw new EducationError($e->status, $e->reason);
                }
                // Fallback to historical education.assessment_attempts only when not found in activity.attempts
            }
        }
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

        /** @var list<string> $questionOrder */
        $questionOrder = array_values(array_filter(ActivityHandlerRegistry::json($attempt['question_order'] ?? []), 'is_string'));
        /** @var array<string, list<string>> $optionOrder */
        $optionOrder = ActivityHandlerRegistry::json($attempt['option_order'] ?? []);

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

        $revealPolicy = (string) ($attempt['reveal_answers_policy'] ?? $attempt['revealAnswersPolicy'] ?? 'after_grading');
        $isEvaluated = in_array($attempt['state'] ?? '', ['EVALUATED'], true);

        // Check deadline if policy is 'deadline'
        $deadlinePassed = false;
        if ($revealPolicy === 'deadline') {
            if (isset($attempt['deadline_passed'])) {
                $deadlinePassed = (bool) $attempt['deadline_passed'];
            } elseif (isset($attempt['due_at']) && is_string($attempt['due_at'])) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $dueTs = strtotime($attempt['due_at']);
                $deadlinePassed = $dueTs !== false && $now >= $dueTs;
            } elseif (isset($attempt['dueAt']) && is_string($attempt['dueAt'])) {
                $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
                $dueTs = strtotime($attempt['dueAt']);
                $deadlinePassed = $dueTs !== false && $now >= $dueTs;
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
                    $dueTs = strtotime($deadline);
                    $deadlinePassed = $dueTs !== false && $now >= $dueTs;
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
            /** @var list<array<string, mixed>> $options */
            $options = isset($q['options']) && is_array($q['options']) ? array_values(array_filter($q['options'], 'is_array')) : [];

            if (isset($optionOrder[$qId])) {
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
            'assignmentId' => !empty($attempt['assignment_id']) ? (string) $attempt['assignment_id'] : (!empty($attempt['assignmentId']) ? (string) $attempt['assignmentId'] : null),
        ];
    }

    /**
     * Autosave attempt with optimistic concurrency compare-and-swap and authoritative time enforcement.
     * @return array<string, mixed>
     */
    public function saveStudentAttempt(Scope $scope, string $attemptId, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        if ($this->engine !== null && $identity !== null) {
            try {
                return $this->engine->saveDraft($identity, $scope->organizationId, $attemptId, $input);
            } catch (HttpError $e) {
                if ($e->status !== 404 || $e->reason !== 'attempt_not_found') {
                    throw new EducationError($e->status, $e->reason === 'attempt_expired' ? 'attempt_time_expired' : $e->reason);
                }
                // Fallback to historical education.assessment_attempts
            }
        }
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];

        if (!isset($input['revision']) || !is_int($input['revision']) || $input['revision'] < 1) {
            throw new EducationError(422, 'revision_required');
        }
        $clientRevision = $input['revision'];

        $attempt = $this->db->createCommand(
            'SELECT att.id, att.state, att.started_at, att.revision, v.time_limit_seconds
             FROM education.assessment_attempts att
             JOIN education.assessment_versions v ON att.version_id = v.id
             WHERE att.id = CAST(:id AS uuid) AND att.student_id = CAST(:sId AS uuid) AND att.organization_id = CAST(:org AS uuid)',
            [':id' => $attemptId, ':sId' => $scope->studentId, ':org' => $scope->organizationId],
        )->queryOne();

        if (!is_array($attempt)) {
            throw new EducationError(404, 'not_found');
        }
        if ($attempt['state'] !== 'IN_PROGRESS') {
            throw new EducationError(409, 'attempt_not_editable');
        }

        // Server-enforced time limit check
        $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
        $startedTime = strtotime((string) $attempt['started_at']);
        $serverElapsed = max(0, $now - ($startedTime !== false ? $startedTime : $now));
        $timeLimit = $attempt['time_limit_seconds'] !== null ? (int) $attempt['time_limit_seconds'] : null;
        if ($timeLimit !== null && $serverElapsed > $timeLimit) {
            throw new EducationError(409, 'attempt_time_expired');
        }

        $nextRevision = $clientRevision + 1;

        // True atomic Compare-And-Swap on revision, state, student, organization
        $affected = $this->db->createCommand(
            'UPDATE education.assessment_attempts
             SET answers = CAST(:answers AS jsonb), revision = :nextRev
             WHERE id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND student_id = CAST(:sId AS uuid)
               AND state = \'IN_PROGRESS\'
               AND revision = :expectedRev',
            [
                ':answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                ':nextRev' => $nextRevision,
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
                ':sId' => $scope->studentId,
                ':expectedRev' => $clientRevision,
            ],
        )->execute();

        if ($affected === 0) {
            $current = $this->db->createCommand(
                'SELECT state, revision FROM education.assessment_attempts
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid) AND student_id = CAST(:sId AS uuid)',
                [':id' => $attemptId, ':org' => $scope->organizationId, ':sId' => $scope->studentId],
            )->queryOne();

            if (!is_array($current)) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['state'] !== 'IN_PROGRESS') {
                throw new EducationError(409, 'attempt_not_editable');
            }
            throw new EducationError(409, 'revision_conflict');
        }

        $this->db->createCommand(
            "UPDATE activity.attempts
             SET answers = CAST(:answers AS jsonb), revision = :nextRev, updated_at = now()
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid) AND state = 'IN_PROGRESS'",
            [
                ':answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                ':nextRev' => $nextRevision,
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
            ],
        )->execute();

        return ['saved' => true, 'revision' => $nextRevision];
    }

    /**
     * Submits and grades the attempt idempotently.
     * @return array<string, mixed>
     */
    public function submitStudentAttempt(Scope $scope, string $attemptId, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        if ($this->engine !== null && $identity !== null) {
            try {
                try {
                    $attempt = $this->engine->submitAttempt($identity, $scope->organizationId, $attemptId, $input);
                } catch (HttpError $e) {
                    if ($e->status === 409 && $e->reason === 'attempt_expired') {
                        // Server already evaluated saved answers and marked EXPIRED; return the finalized attempt
                        $attempt = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);
                    } else {
                        throw $e;
                    }
                }
                if ($this->activityResults !== null && in_array((string) ($attempt['state'] ?? ''), ['EVALUATED', 'COMPLETED', 'EXPIRED'], true)) {
                    $this->activityResults->finished(
                        $scope->organizationId,
                        $scope->studentId,
                        'platform-assessment',
                        [
                            'attemptId' => (string) ($attempt['id'] ?? $attemptId),
                            'type' => 'assessment',
                            'score' => (float) ($attempt['rawScore'] ?? 0),
                            'maxScore' => (float) ($attempt['maxScore'] ?? 0),
                            'accuracy' => (float) ($attempt['percentage'] ?? 0),
                            'passed' => (bool) ($attempt['passed'] ?? false),
                        ],
                    );
                }
                return $this->mapEngineAttemptToLegacy($attempt);
            } catch (HttpError $e) {
                if ($e->status !== 404 || $e->reason !== 'attempt_not_found') {
                    throw new EducationError($e->status, $e->reason);
                }
                // Fallback to historical education.assessment_attempts
            }
        }

        return $this->db->transaction(function () use ($scope, $attemptId, $input): array {
            $attempt = $this->db->createCommand(
                'SELECT att.id, att.version_id, att.state, att.started_at, att.revision,
                        att.answers, att.assignment_id, v.assessment_id::text AS assessment_id,
                        v.title AS version_title, v.questions_snapshot, v.passing_score_percent, v.reveal_answers_policy,
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

            // Optimistic concurrency check: stale revisions cannot overwrite newer server answers
            if (isset($input['revision']) && (int) $input['revision'] !== (int) $attempt['revision']) {
                throw new EducationError(409, 'revision_conflict');
            }
            if (!isset($input['revision']) && isset($input['answers']) && is_array($input['answers']) && (int) $attempt['revision'] > 1) {
                throw new EducationError(409, 'revision_conflict');
            }

            $now = $this->clock ? $this->clock->now()->getTimestamp() : time();
            $startedTime = strtotime((string) $attempt['started_at']);
            $serverElapsed = max(0, $now - ($startedTime !== false ? $startedTime : $now));
            $timeLimit = $attempt['time_limit_seconds'] !== null ? (int) $attempt['time_limit_seconds'] : null;

            // Server-enforced deadline check:
            // When official time limit expires ($serverElapsed > $timeLimit):
            // Finalize ONLY previously saved answers from DB. New client answers are NOT accepted.
            if ($timeLimit !== null && $serverElapsed > $timeLimit) {
                $answers = ActivityHandlerRegistry::json($attempt['answers'] ?? []);
                $elapsedSeconds = $timeLimit;
            } else {
                $answers = isset($input['answers']) && is_array($input['answers'])
                    ? $input['answers']
                    : ActivityHandlerRegistry::json($attempt['answers'] ?? []);
                $elapsedSeconds = $timeLimit !== null ? min($serverElapsed, $timeLimit) : $serverElapsed;
            }

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

            $this->syncAssessmentAttemptEvaluationToEngine(
                $scope,
                $attemptId,
                $answers,
                $evaluated,
                (float) $score,
                (float) $maxScore,
                $percentage,
                $passed,
                'attempt_submitted',
            );

            // Synchronize with activity_attempts table & sink to trigger HomeworkAttemptLinker
            $actDefId = $this->db->createCommand(
                "SELECT id::text FROM education.activity_definitions WHERE code = 'platform-assessment' LIMIT 1",
            )->queryScalar();

            if (is_string($actDefId)) {
                $assignId = !empty($attempt['assignment_id']) ? (string) $attempt['assignment_id'] : null;
                $configData = [
                    'assessmentId' => (string) $attempt['assessment_id'],
                    'versionId' => (string) $attempt['version_id'],
                    'passingScorePercent' => $passingScore,
                ];
                if ($assignId !== null) {
                    $hwCfg = $this->db->createCommand(
                        'SELECT ha.activity_config FROM education.homework_recipients hr
                         JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
                         WHERE hr.activity_assignment_id = CAST(:assignId AS uuid) OR ha.id = CAST(:assignId AS uuid)
                         LIMIT 1',
                        [':assignId' => $assignId],
                    )->queryScalar();
                    if (is_string($hwCfg)) {
                        $decoded = json_decode($hwCfg, true);
                        if (is_array($decoded)) {
                            $configData = $decoded;
                        }
                    } else {
                        $actCfg = $this->db->createCommand(
                            'SELECT aa.config FROM education.activity_assignments aa WHERE aa.id = CAST(:assignId AS uuid) LIMIT 1',
                            [':assignId' => $assignId],
                        )->queryScalar();
                        if (is_string($actCfg)) {
                            $decoded = json_decode($actCfg, true);
                            if (is_array($decoded)) {
                                $configData = $decoded;
                            }
                        }
                    }
                }

                $this->db->createCommand(
                    'INSERT INTO education.activity_attempts
                     (id, organization_id, student_id, account_id, activity_id, assignment_id, status, source, contract_version, started_at, finished_at, exercise_text, config, exercise_payload, result_payload, client_state, state_version)
                     VALUES
                     (CAST(:id AS uuid), CAST(:org AS uuid), CAST(:student AS uuid), CAST(:account AS uuid), CAST(:actId AS uuid), CAST(:assignId AS uuid), \'finished\', \'browser\', 1, CAST(:startedAt AS timestamptz), now(), :title, CAST(:config AS jsonb), CAST(:exercise AS jsonb), CAST(:result AS jsonb), \'{}\'::jsonb, 1)
                     ON CONFLICT (id) DO UPDATE SET
                         status = \'finished\',
                         finished_at = now(),
                         result_payload = EXCLUDED.result_payload',
                    [
                        ':id' => $attemptId,
                        ':org' => $scope->organizationId,
                        ':student' => $scope->studentId,
                        ':account' => $scope->accountId,
                        ':actId' => $actDefId,
                        ':assignId' => $assignId,
                        ':startedAt' => (string) $attempt['started_at'],
                        ':title' => (string) ($attempt['version_title'] ?? 'Assessment'),
                        ':config' => json_encode($configData, JSON_THROW_ON_ERROR),
                        ':exercise' => json_encode(['questions' => $questions, 'passingScorePercent' => $passingScore], JSON_THROW_ON_ERROR),
                        ':result' => json_encode($evaluated, JSON_THROW_ON_ERROR),
                    ],
                )->execute();

                $this->activityResults?->finished($scope, $attemptId);
            }

            return $this->getStudentAttempt($scope, $attemptId);
        });
    }

    /**
     * Teacher list of attempts for an assessment.
     * @return list<array<string, mixed>>
     */
    public function listAssessmentAttempts(Scope $scope, string $assessmentId, ?AuthenticatedIdentity $identity = null): array
    {
        $assessmentId = Input::uuid($assessmentId);
        if ($this->engine !== null && $identity !== null) {
            $engineRows = $this->db->createCommand(
                'SELECT a.id::text, a.version_id::text, v.version_number,
                        COALESCE(s.id::text, a.participant_person_id::text) AS student_id,
                        COALESCE(NULLIF(p.display_name, \'\'), NULLIF(trim(COALESCE(p.given_name, \'\') || \' \' || COALESCE(p.family_name, \'\')), \'\'), \'Student\') AS student_name,
                        a.state, a.raw_score AS score, a.max_score, a.percentage, a.passed,
                        a.started_at::text, a.submitted_at::text, a.review_notes AS manual_review_notes
                 FROM activity.attempts a
                 JOIN activity.versions v ON a.version_id = v.id
                 JOIN core.persons p ON a.participant_person_id = p.id
                 LEFT JOIN education.students s ON s.person_id = a.participant_person_id AND s.organization_id = a.organization_id
                 WHERE a.activity_id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                 ORDER BY a.started_at DESC',
                [':id' => $assessmentId, ':org' => $scope->organizationId],
            )->queryAll();

            $legacyRows = $this->db->createCommand(
                'SELECT att.id::text, att.version_id::text, v.version_number,
                        att.student_id::text, COALESCE(NULLIF(p.display_name, \'\'), NULLIF(trim(COALESCE(p.given_name, \'\') || \' \' || COALESCE(p.family_name, \'\')), \'\'), \'Student\') AS student_name,
                        att.state, att.score, att.max_score, att.percentage, att.passed,
                        att.started_at::text, att.submitted_at::text, att.manual_review_notes
                 FROM education.assessment_attempts att
                 JOIN education.assessment_versions v ON att.version_id = v.id
                 JOIN education.students s ON att.student_id = s.id
                 JOIN core.persons p ON s.person_id = p.id
                 WHERE v.assessment_id = CAST(:id AS uuid) AND att.organization_id = CAST(:org AS uuid)
                   AND NOT EXISTS (SELECT 1 FROM activity.attempts aa WHERE aa.id = att.id)
                 ORDER BY att.started_at DESC',
                [':id' => $assessmentId, ':org' => $scope->organizationId],
            )->queryAll();

            $rows = array_merge($engineRows, $legacyRows);
            return array_map(static fn(array $r): array => [
                'id' => (string) $r['id'],
                'versionId' => (string) $r['version_id'],
                'versionNumber' => (int) $r['version_number'],
                'studentId' => (string) $r['student_id'],
                'studentName' => (string) ($r['student_name'] ?? 'Student'),
                'state' => (string) $r['state'],
                'score' => $r['score'] !== null ? (int) round((float) $r['score']) : null,
                'maxScore' => $r['max_score'] !== null ? (int) round((float) $r['max_score']) : null,
                'percentage' => $r['percentage'] !== null ? (float) $r['percentage'] : null,
                'passed' => $r['passed'] !== null ? (bool) $r['passed'] : null,
                'startedAt' => (string) $r['started_at'],
                'submittedAt' => $r['submitted_at'] !== null ? (string) $r['submitted_at'] : null,
                'manualReviewNotes' => $r['manual_review_notes'] !== null ? (string) $r['manual_review_notes'] : null,
            ], $rows);
        }
        $rows = $this->db->createCommand(
            'SELECT att.id::text, att.version_id::text, v.version_number,
                    att.student_id::text, COALESCE(NULLIF(p.display_name, \'\'), NULLIF(trim(COALESCE(p.given_name, \'\') || \' \' || COALESCE(p.family_name, \'\')), \'\'), \'Student\') AS student_name,
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
    public function reviewAttempt(Scope $scope, string $attemptId, array $input, ?AuthenticatedIdentity $identity = null): array
    {
        $attemptId = Input::uuid($attemptId);
        $notes = isset($input['manualReviewNotes']) && is_string($input['manualReviewNotes'])
            ? trim($input['manualReviewNotes'])
            : (isset($input['feedback']) && is_string($input['feedback'])
                ? trim($input['feedback'])
                : (isset($input['notes']) && is_string($input['notes']) ? trim($input['notes']) : null));
        $score = isset($input['score']) && is_numeric($input['score']) ? (int) round((float) $input['score']) : null;
        $questionReviews = is_array($input['questionReviews'] ?? null) ? $input['questionReviews'] : [];
        $publish = array_key_exists('publish', $input) ? (bool) $input['publish'] : true;

        if ($this->engine !== null && $identity !== null && ($score !== null || $questionReviews !== [])) {
            try {
                $payload = [
                    'reviewNotes' => $notes ?? '',
                    'publish' => $publish,
                ];
                if ($score !== null) {
                    $payload['rawScore'] = $score;
                }
                if ($questionReviews !== []) {
                    $payload['questionReviews'] = $questionReviews;
                }
                $reviewed = $this->engine->reviewAttempt($identity, $scope->organizationId, $attemptId, $payload);
                $revScore = (float) ($reviewed['rawScore'] ?? $score ?? 0);
                $revMax = (float) ($reviewed['maxScore'] ?? 0);
                return [
                    'success' => true,
                    'score' => fmod($revScore, 1.0) === 0.0 ? (int) $revScore : $revScore,
                    'maxScore' => fmod($revMax, 1.0) === 0.0 ? (int) $revMax : $revMax,
                    'percentage' => (float) ($reviewed['percentage'] ?? 0.0),
                    'passed' => (bool) ($reviewed['passed'] ?? false),
                    'state' => (string) ($reviewed['state'] ?? 'EVALUATED'),
                    'resultsPublished' => (bool) ($reviewed['resultsPublished'] ?? false),
                    'reviewHistory' => is_array($reviewed['resultHistory'] ?? null) ? $reviewed['resultHistory'] : [],
                ];
            } catch (HttpError $e) {
                if ($e->status !== 404 || $e->reason !== 'attempt_not_found') {
                    throw new EducationError($e->status, $e->reason);
                }
            }
        }

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

            $this->syncAssessmentAttemptReviewToEngine(
                $scope,
                $attemptId,
                (string) $attempt['state'],
                $attempt['score'] !== null ? (float) $attempt['score'] : null,
                (float) $finalScore,
                $attempt['passed'] !== null ? (bool) $attempt['passed'] : null,
                $passed,
                $percentage,
                $notes,
            );

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

    /**
     * @return array{assignmentId: ?string, versionId: ?string}
     */
    public function resolveAndVerifyStudentAssignment(Scope $scope, string $assessmentId, bool $openPractice, ?string $clientAssignmentId): array
    {
        $now = $this->clock ? $this->clock->now()->getTimestamp() : time();

        if ($clientAssignmentId !== null) {
            $clientAssignmentId = Input::uuid($clientAssignmentId);

            // 1. Check if clientAssignmentId is a Homework assignment
            $hw = $this->db->createCommand(
                'SELECT ha.id::text, ha.due_at, ha.allow_late, ha.status, ha.group_id::text AS group_id,
                        ha.activity_config->>\'versionId\' AS version_id,
                        hr.activity_assignment_id::text AS activity_assignment_id
                 FROM education.homework_recipients hr
                 JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
                 WHERE (ha.id = CAST(:assignId AS uuid) OR hr.activity_assignment_id = CAST(:assignId AS uuid))
                   AND hr.student_id = CAST(:sId AS uuid)
                   AND ha.organization_id = CAST(:org AS uuid)
                   AND ha.status = \'PUBLISHED\'
                   AND ha.activity_code = \'platform-assessment\'
                   AND ha.activity_config->>\'assessmentId\' = :aId
                 LIMIT 1',
                [':assignId' => $clientAssignmentId, ':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
            )->queryOne();

            if (is_array($hw)) {
                // Check group membership if assignment was targeted to a group
                if ($hw['group_id'] !== null) {
                    $inGroup = $this->db->createCommand(
                        'SELECT 1 FROM education.group_students
                         WHERE group_id = CAST(:gId AS uuid) AND student_id = CAST(:sId AS uuid) AND organization_id = CAST(:org AS uuid)',
                        [':gId' => $hw['group_id'], ':sId' => $scope->studentId, ':org' => $scope->organizationId],
                    )->queryScalar();
                    if ($inGroup === false) {
                        throw new EducationError(403, 'student_not_in_group');
                    }
                }

                if ($hw['due_at'] !== null && empty($hw['allow_late'])) {
                    $dueTs = strtotime((string) $hw['due_at']);
                    if ($dueTs !== false && $now > $dueTs) {
                        throw new EducationError(409, 'assignment_deadline_passed');
                    }
                }

                $assignId = !empty($hw['activity_assignment_id']) ? (string) $hw['activity_assignment_id'] : (string) $hw['id'];
                $verId = !empty($hw['version_id']) ? (string) $hw['version_id'] : null;
                return ['assignmentId' => $assignId, 'versionId' => $verId];
            }

            // 2. Check if clientAssignmentId is an Activity assignment
            $act = $this->db->createCommand(
                'SELECT aa.id::text, aa.opens_at, aa.due_at, aa.group_id::text AS group_id,
                        aa.config->>\'versionId\' AS version_id
                 FROM education.activity_assignments aa
                 WHERE aa.id = CAST(:assignId AS uuid)
                   AND aa.organization_id = CAST(:org AS uuid)
                   AND (
                       aa.student_id = CAST(:sId AS uuid)
                       OR (aa.group_id IS NOT NULL AND EXISTS (
                           SELECT 1 FROM education.group_students gs
                           WHERE gs.group_id = aa.group_id AND gs.student_id = CAST(:sId AS uuid) AND gs.organization_id = CAST(:org AS uuid)
                       ))
                   )
                   AND aa.config->>\'assessmentId\' = :aId
                 LIMIT 1',
                [':assignId' => $clientAssignmentId, ':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
            )->queryOne();

            if (is_array($act)) {
                if ($act['opens_at'] !== null) {
                    $opensTs = strtotime((string) $act['opens_at']);
                    if ($opensTs !== false && $now < $opensTs) {
                        throw new EducationError(403, 'assignment_not_started');
                    }
                }
                if ($act['due_at'] !== null) {
                    $dueTs = strtotime((string) $act['due_at']);
                    if ($dueTs !== false && $now > $dueTs) {
                        throw new EducationError(409, 'assignment_deadline_passed');
                    }
                }
                return ['assignmentId' => (string) $act['id'], 'versionId' => !empty($act['version_id']) ? (string) $act['version_id'] : null];
            }

            // Specific assignment requested was not found or access denied
            throw new EducationError(404, 'assignment_not_found');
        }

        // When clientAssignmentId is NULL:
        // Check if open_practice is allowed
        if ($openPractice) {
            return ['assignmentId' => null, 'versionId' => null];
        }

        // Closed assessment: must resolve active published assignment
        $hw = $this->db->createCommand(
            'SELECT ha.id::text, ha.due_at, ha.allow_late, ha.group_id::text AS group_id,
                    ha.activity_config->>\'versionId\' AS version_id,
                    hr.activity_assignment_id::text AS activity_assignment_id
             FROM education.homework_recipients hr
             JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
             WHERE hr.student_id = CAST(:sId AS uuid)
               AND ha.organization_id = CAST(:org AS uuid)
               AND ha.status = \'PUBLISHED\'
               AND ha.activity_code = \'platform-assessment\'
               AND ha.activity_config->>\'assessmentId\' = :aId
               AND (ha.group_id IS NULL OR EXISTS (
                   SELECT 1 FROM education.group_students gs
                   WHERE gs.group_id = ha.group_id AND gs.student_id = CAST(:sId AS uuid) AND gs.organization_id = CAST(:org AS uuid)
               ))
             ORDER BY ha.created_at DESC LIMIT 1',
            [':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
        )->queryOne();

        if (is_array($hw)) {
            if ($hw['due_at'] !== null && empty($hw['allow_late'])) {
                $dueTs = strtotime((string) $hw['due_at']);
                if ($dueTs !== false && $now > $dueTs) {
                    throw new EducationError(409, 'assignment_deadline_passed');
                }
            }
            $assignId = !empty($hw['activity_assignment_id']) ? (string) $hw['activity_assignment_id'] : (string) $hw['id'];
            $verId = !empty($hw['version_id']) ? (string) $hw['version_id'] : null;
            return ['assignmentId' => $assignId, 'versionId' => $verId];
        }

        $act = $this->db->createCommand(
            'SELECT aa.id::text, aa.opens_at, aa.due_at,
                    aa.config->>\'versionId\' AS version_id
             FROM education.activity_assignments aa
             WHERE aa.organization_id = CAST(:org AS uuid)
               AND (
                   aa.student_id = CAST(:sId AS uuid)
                   OR (aa.group_id IS NOT NULL AND EXISTS (
                       SELECT 1 FROM education.group_students gs
                       WHERE gs.group_id = aa.group_id AND gs.student_id = CAST(:sId AS uuid) AND gs.organization_id = CAST(:org AS uuid)
                   ))
               )
               AND aa.config->>\'assessmentId\' = :aId
             ORDER BY aa.created_at DESC LIMIT 1',
            [':sId' => $scope->studentId, ':org' => $scope->organizationId, ':aId' => $assessmentId],
        )->queryOne();

        if (is_array($act)) {
            if ($act['opens_at'] !== null) {
                $opensTs = strtotime((string) $act['opens_at']);
                if ($opensTs !== false && $now < $opensTs) {
                    throw new EducationError(403, 'assignment_not_started');
                }
            }
            if ($act['due_at'] !== null) {
                $dueTs = strtotime((string) $act['due_at']);
                if ($dueTs !== false && $now > $dueTs) {
                    throw new EducationError(409, 'assignment_deadline_passed');
                }
            }
            return ['assignmentId' => (string) $act['id'], 'versionId' => !empty($act['version_id']) ? (string) $act['version_id'] : null];
        }

        throw new EducationError(403, 'assignment_required');
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

        $this->syncAssessmentAttemptEvaluationToEngine(
            $scope,
            $attemptId,
            $answers,
            $evaluated,
            (float) $metrics['score'],
            (float) $metrics['maxScore'],
            (float) $metrics['percentage'],
            $metrics['passed'],
            'deadline_auto_finalized',
        );

        $actDefId = $this->db->createCommand(
            "SELECT id::text FROM education.activity_definitions WHERE code = 'platform-assessment' LIMIT 1",
        )->queryScalar();

        if (is_string($actDefId)) {
            $assignId = !empty($running['assignment_id']) ? (string) $running['assignment_id'] : null;
            $configData = [
                'assessmentId' => (string) ($assessment['id'] ?? $assessment['latest_version_id']),
                'versionId' => (string) ($assessment['latest_version_id'] ?? $assessment['version_id'] ?? $assessment['id']),
                'passingScorePercent' => $passingScore,
            ];
            if ($assignId !== null) {
                $hwCfg = $this->db->createCommand(
                    'SELECT ha.activity_config FROM education.homework_recipients hr
                     JOIN education.homework_assignments ha ON hr.assignment_id = ha.id
                     WHERE hr.activity_assignment_id = CAST(:assignId AS uuid) OR ha.id = CAST(:assignId AS uuid)
                     LIMIT 1',
                    [':assignId' => $assignId],
                )->queryScalar();
                if (is_string($hwCfg)) {
                    $decoded = json_decode($hwCfg, true);
                    if (is_array($decoded)) {
                        $configData = $decoded;
                    }
                } else {
                    $actCfg = $this->db->createCommand(
                        'SELECT aa.config FROM education.activity_assignments aa WHERE aa.id = CAST(:assignId AS uuid) LIMIT 1',
                        [':assignId' => $assignId],
                    )->queryScalar();
                    if (is_string($actCfg)) {
                        $decoded = json_decode($actCfg, true);
                        if (is_array($decoded)) {
                            $configData = $decoded;
                        }
                    }
                }
            }

            $this->db->createCommand(
                'INSERT INTO education.activity_attempts
                 (id, organization_id, student_id, account_id, activity_id, assignment_id, status, source, contract_version, started_at, finished_at, exercise_text, config, exercise_payload, result_payload, client_state, state_version)
                 VALUES
                 (CAST(:id AS uuid), CAST(:org AS uuid), CAST(:student AS uuid), CAST(:account AS uuid), CAST(:actId AS uuid), CAST(:assignId AS uuid), \'finished\', \'browser\', 1, CAST(:startedAt AS timestamptz), now(), :title, CAST(:config AS jsonb), CAST(:exercise AS jsonb), CAST(:result AS jsonb), \'{}\'::jsonb, 1)
                 ON CONFLICT (id) DO UPDATE SET
                     status = \'finished\',
                     finished_at = now(),
                     result_payload = EXCLUDED.result_payload',
                [
                    ':id' => $attemptId,
                    ':org' => $scope->organizationId,
                    ':student' => $scope->studentId,
                    ':account' => $scope->accountId,
                    ':actId' => $actDefId,
                    ':assignId' => $assignId,
                    ':startedAt' => (string) $running['started_at'],
                    ':title' => (string) ($assessment['title'] ?? 'Assessment'),
                    ':config' => json_encode($configData, JSON_THROW_ON_ERROR),
                    ':exercise' => json_encode(['questions' => $questions, 'passingScorePercent' => $passingScore], JSON_THROW_ON_ERROR),
                    ':result' => json_encode($evaluated, JSON_THROW_ON_ERROR),
                ],
            )->execute();

            $this->activityResults?->finished($scope, $attemptId);
        }
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
            /** @var list<array<string, mixed>> $options */
            $options = isset($q['options']) && is_array($q['options']) ? array_values(array_filter($q['options'], 'is_array')) : [];

            if (isset($optionOrder[$qId])) {
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

    /**
     * @param array<array-key, mixed> $questions
     */
    private function syncAssessmentToActivityEngine(
        Scope $scope,
        string $assessmentId,
        string $title,
        string $description,
        string $status,
        bool $openPractice,
        string $versionId,
        int $versionNumber,
        string $instructions,
        ?int $timeLimit,
        int $passingScore,
        int $maxAttempts,
        bool $shuffleQ,
        bool $shuffleOpt,
        string $revealPolicy,
        string $gradingPolicy,
        array $questions,
    ): void {
        $visibility = $openPractice ? 'public_catalog' : 'organization';
        $config = [
            'shuffleQuestions' => $shuffleQ,
            'shuffleOptions' => $shuffleOpt,
            'revealAnswersPolicy' => $revealPolicy,
            'openPractice' => $openPractice,
            'examOnly' => !$openPractice,
            'timeLimitSeconds' => $timeLimit,
            'passingScorePercent' => $passingScore,
        ];
        $content = ['questions' => array_values($questions)];

        $this->db->createCommand(
            "INSERT INTO activity.items (
                id, organization_id, plugin_code, author_person_id, author_account_id,
                title, description, status, visibility, source_legacy_type, source_legacy_id
             ) VALUES (
                CAST(:id AS uuid), CAST(:org AS uuid), 'platform-assessment', CAST(:person AS uuid), CAST(:account AS uuid),
                :title, :desc, :status, :vis, 'assessment', CAST(:id AS uuid)
             )
             ON CONFLICT (id) DO UPDATE SET
                title = EXCLUDED.title,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                visibility = EXCLUDED.visibility,
                updated_at = now()",
            [
                ':id' => $assessmentId,
                ':org' => $scope->organizationId,
                ':person' => $scope->personId,
                ':account' => $scope->accountId,
                ':title' => mb_substr($title, 0, 200),
                ':desc' => $description,
                ':status' => $status,
                ':vis' => $visibility,
            ],
        )->execute();

        $this->db->createCommand(
            'INSERT INTO activity.versions (
                id, activity_id, version_number, title, instructions, config, content,
                time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, created_by_person_id
             ) VALUES (
                CAST(:vid AS uuid), CAST(:aid AS uuid), :ver, :title, :inst, CAST(:cfg AS jsonb), CAST(:cnt AS jsonb),
                :tl, :ma, :pp, :gp, :rp, CAST(:person AS uuid)
             )
             ON CONFLICT (id) DO UPDATE SET
                title = EXCLUDED.title,
                instructions = EXCLUDED.instructions,
                config = EXCLUDED.config,
                content = EXCLUDED.content,
                time_limit_seconds = EXCLUDED.time_limit_seconds,
                max_attempts = EXCLUDED.max_attempts,
                passing_score_percent = EXCLUDED.passing_score_percent,
                grading_policy = EXCLUDED.grading_policy,
                reveal_answers_policy = EXCLUDED.reveal_answers_policy',
            [
                ':vid' => $versionId,
                ':aid' => $assessmentId,
                ':ver' => $versionNumber,
                ':title' => mb_substr($title, 0, 200),
                ':inst' => $instructions,
                ':cfg' => json_encode($config, JSON_THROW_ON_ERROR),
                ':cnt' => json_encode($content, JSON_THROW_ON_ERROR),
                ':tl' => $timeLimit,
                ':ma' => $maxAttempts,
                ':pp' => $passingScore,
                ':gp' => $gradingPolicy,
                ':rp' => $revealPolicy,
                ':person' => $scope->personId,
            ],
        )->execute();

        $this->db->createCommand(
            'UPDATE activity.items SET active_version_id = CAST(:vid AS uuid), updated_at = now() WHERE id = CAST(:aid AS uuid)',
            [':vid' => $versionId, ':aid' => $assessmentId],
        )->execute();
    }

    private function syncAssessmentMetadataToActivityEngine(
        string $organizationId,
        string $assessmentId,
        string $title,
        string $description,
        string $status,
        bool $openPractice,
    ): void {
        $this->db->createCommand(
            'UPDATE activity.items
             SET title = :title, description = :desc, status = :status, visibility = :vis, updated_at = now()
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [
                ':title' => mb_substr($title, 0, 200),
                ':desc' => $description,
                ':status' => $status,
                ':vis' => $openPractice ? 'public_catalog' : 'organization',
                ':id' => $assessmentId,
                ':org' => $organizationId,
            ],
        )->execute();
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     * @param array<string, mixed> $draftState
     */
    private function syncAssessmentAttemptStartToEngine(
        Scope $scope,
        string $attemptId,
        string $assessmentId,
        string $versionId,
        ?string $assignmentId,
        int $seed,
        array $runtimeConfig,
        array $draftState,
        ?string $expiresAt,
    ): void {
        $this->db->createCommand(
            "INSERT INTO activity.attempts (
                id, organization_id, activity_id, version_id, assignment_id,
                participant_person_id, participant_account_id,
                state, revision, seed, runtime_config, draft_state,
                started_at, expires_at, source_legacy_type, source_legacy_id
             )
             SELECT
                CAST(:id AS uuid), CAST(:org AS uuid), av.activity_id, av.id,
                CASE WHEN CAST(:asgn AS uuid) IS NOT NULL AND EXISTS (SELECT 1 FROM activity.assignments ag WHERE ag.id = CAST(:asgn AS uuid))
                     THEN CAST(:asgn AS uuid) ELSE NULL END,
                CAST(:person AS uuid), CAST(:account AS uuid),
                'IN_PROGRESS', 1, :seed, CAST(:cfg AS jsonb), CAST(:draft AS jsonb),
                now(), CAST(:exp AS timestamptz), 'education_assessment_attempt', CAST(:id AS uuid)
             FROM activity.versions av
             WHERE av.id = CAST(:vid AS uuid) AND av.activity_id = CAST(:aid AS uuid)
             ON CONFLICT (id) DO NOTHING",
            [
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
                ':vid' => $versionId,
                ':aid' => $assessmentId,
                ':asgn' => $assignmentId,
                ':person' => $scope->personId,
                ':account' => $scope->accountId,
                ':seed' => $seed,
                ':cfg' => json_encode($runtimeConfig, JSON_THROW_ON_ERROR),
                ':draft' => json_encode($draftState, JSON_THROW_ON_ERROR),
                ':exp' => $expiresAt,
            ],
        )->execute();

        $this->db->createCommand(
            "INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason)
             SELECT id, 'CREATED', 'IN_PROGRESS', CAST(:person AS uuid), 'attempt_started'
             FROM activity.attempts
             WHERE id = CAST(:id AS uuid)
               AND NOT EXISTS (SELECT 1 FROM activity.attempt_events e WHERE e.attempt_id = activity.attempts.id)",
            [':id' => $attemptId, ':person' => $scope->personId],
        )->execute();
    }

    /**
     * @param array<array-key, mixed> $answers
     * @param array<array-key, mixed> $evaluated
     */
    private function syncAssessmentAttemptEvaluationToEngine(
        Scope $scope,
        string $attemptId,
        array $answers,
        array $evaluated,
        float $rawScore,
        float $maxScore,
        float $percentage,
        bool $passed,
        string $reason,
    ): void {
        $this->db->createCommand(
            "UPDATE activity.attempts
             SET state = 'EVALUATED',
                 revision = revision + 1,
                 answers = CAST(:ans AS jsonb),
                 evaluation = CAST(:ev AS jsonb),
                 raw_score = :raw,
                 max_score = :max,
                 percentage = :pct,
                 passed = :passed,
                 submitted_at = COALESCE(submitted_at, now()),
                 evaluated_at = now(),
                 updated_at = now()
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)",
            [
                ':ans' => json_encode($answers === [] ? (object) [] : $answers, JSON_THROW_ON_ERROR),
                ':ev' => json_encode($evaluated, JSON_THROW_ON_ERROR),
                ':raw' => $rawScore,
                ':max' => $maxScore,
                ':pct' => $percentage,
                ':passed' => $passed ? 'true' : 'false',
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
            ],
        )->execute();

        $this->db->createCommand(
            "INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason)
             SELECT id, 'IN_PROGRESS', 'EVALUATED', CAST(:actor AS uuid), :reason
             FROM activity.attempts
             WHERE id = CAST(:id AS uuid)",
            [':actor' => $scope->personId, ':reason' => $reason, ':id' => $attemptId],
        )->execute();
    }

    private function syncAssessmentAttemptReviewToEngine(
        Scope $scope,
        string $attemptId,
        string $prevState,
        ?float $previousScore,
        float $newScore,
        ?bool $previousPassed,
        bool $newPassed,
        float $percentage,
        ?string $notes,
    ): void {
        $this->db->createCommand(
            "UPDATE activity.attempts
             SET state = 'EVALUATED',
                 raw_score = :raw,
                 percentage = :pct,
                 passed = :passed,
                 reviewer_person_id = CAST(:rev AS uuid),
                 review_notes = COALESCE(:notes, review_notes),
                 evaluated_at = now(),
                 updated_at = now()
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)",
            [
                ':raw' => $newScore,
                ':pct' => $percentage,
                ':passed' => $newPassed ? 'true' : 'false',
                ':rev' => $scope->personId,
                ':notes' => $notes,
                ':id' => $attemptId,
                ':org' => $scope->organizationId,
            ],
        )->execute();

        $meta = [
            'previousScore' => $previousScore,
            'newScore' => $newScore,
            'previousPassed' => $previousPassed,
            'newPassed' => $newPassed,
            'notes' => $notes ?? '',
        ];
        $this->db->createCommand(
            "INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason, metadata)
             SELECT id, :fromState, 'EVALUATED', CAST(:actor AS uuid), 'manual_review', CAST(:meta AS jsonb)
             FROM activity.attempts
             WHERE id = CAST(:id AS uuid)",
            [
                ':fromState' => $prevState === 'AWAITING_MANUAL_REVIEW' ? 'PENDING_REVIEW' : $prevState,
                ':actor' => $scope->personId,
                ':meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                ':id' => $attemptId,
            ],
        )->execute();
    }

    /**
     * Maps Education assessment input to canonical ActivityEngineService payload.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function mapEducationInputToActivityPayload(array $input, bool $publish, ?array $current = null): array
    {
        $currentConfig = is_array($current['config'] ?? null) ? $current['config'] : [];
        $currentContent = is_array($current['content'] ?? null) ? $current['content'] : [];
        $openPractice = array_key_exists('openPractice', $input)
            ? (bool) $input['openPractice']
            : (bool) ($currentConfig['openPractice'] ?? false);

        $questions = isset($input['questions']) && is_array($input['questions'])
            ? array_values($input['questions'])
            : (is_array($currentContent['questions'] ?? null) ? $currentContent['questions'] : []);

        $config = [
            'shuffleQuestions' => array_key_exists('shuffleQuestions', $input) ? (bool) $input['shuffleQuestions'] : (bool) ($currentConfig['shuffleQuestions'] ?? false),
            'shuffleOptions' => array_key_exists('shuffleOptions', $input) ? (bool) $input['shuffleOptions'] : (bool) ($currentConfig['shuffleOptions'] ?? false),
            'revealAnswersPolicy' => $input['revealAnswersPolicy'] ?? ($currentConfig['revealAnswersPolicy'] ?? 'after_grading'),
            'openPractice' => $openPractice,
            'examOnly' => !$openPractice,
        ];

        $payload = [
            'pluginCode' => 'platform-assessment',
            'title' => array_key_exists('title', $input) ? (string) $input['title'] : (string) ($current['title'] ?? ''),
            'description' => array_key_exists('description', $input) ? (string) $input['description'] : (string) ($current['description'] ?? ''),
            'instructions' => array_key_exists('instructions', $input) ? (string) $input['instructions'] : (string) ($current['instructions'] ?? ''),
            'visibility' => $openPractice ? 'public_catalog' : 'organization',
            'config' => $config,
            'content' => ['questions' => $questions],
            'timeLimitSeconds' => array_key_exists('timeLimitSeconds', $input) ? $input['timeLimitSeconds'] : ($current['timeLimitSeconds'] ?? null),
            'passingScorePercent' => array_key_exists('passingScorePercent', $input) ? $input['passingScorePercent'] : ($current['passingScorePercent'] ?? 60),
            'maxAttempts' => array_key_exists('maxAttempts', $input) ? $input['maxAttempts'] : ($current['maxAttempts'] ?? 1),
            'gradingPolicy' => array_key_exists('gradingPolicy', $input) ? $input['gradingPolicy'] : ($current['gradingPolicy'] ?? 'highest'),
            'publish' => $publish,
        ];
        if (!$publish && isset($input['status']) && is_string($input['status'])) {
            $payload['status'] = $input['status'];
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapActivityListRow(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $verId = isset($row['activeVersionId']) && is_string($row['activeVersionId']) ? $row['activeVersionId'] : null;
        $stats = $this->db->createCommand(
            'SELECT COALESCE(jsonb_array_length(v.content->\'questions\'), 0)::int AS q_count,
                    (SELECT count(*)::int FROM activity.attempts att WHERE att.version_id = v.id) AS att_count,
                    (SELECT round(avg(att.percentage), 1) FROM activity.attempts att WHERE att.version_id = v.id AND att.percentage IS NOT NULL) AS avg_score
             FROM activity.versions v WHERE v.id = CAST(:vid AS uuid)',
            [':vid' => $verId ?? '00000000-0000-0000-0000-000000000000'],
        )->queryOne();

        return [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) ($row['status'] ?? 'DRAFT'),
            'openPractice' => ($row['visibility'] ?? 'organization') === 'public_catalog',
            'latestVersionId' => $verId,
            'latestVersionNumber' => isset($row['versionNumber']) ? (int) $row['versionNumber'] : 1,
            'passingScorePercent' => isset($row['passingScorePercent']) ? (int) $row['passingScorePercent'] : 60,
            'timeLimitSeconds' => $row['timeLimitSeconds'] !== null ? (int) $row['timeLimitSeconds'] : null,
            'questionsCount' => is_array($stats) ? (int) ($stats['q_count'] ?? 0) : 0,
            'attemptsCount' => is_array($stats) ? (int) ($stats['att_count'] ?? 0) : 0,
            'avgScore' => is_array($stats) && $stats['avg_score'] !== null ? (float) $stats['avg_score'] : null,
            'createdAt' => (string) ($row['updatedAt'] ?? ''),
            'updatedAt' => (string) ($row['updatedAt'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    private function mapActivityToAssessment(array $activity): array
    {
        $cfg = is_array($activity['config'] ?? null) ? $activity['config'] : [];
        $content = is_array($activity['content'] ?? null) ? $activity['content'] : [];
        $questions = is_array($content['questions'] ?? null) ? array_values($content['questions']) : [];
        $normalizedQuestions = array_map(static function (mixed $q): mixed {
            if (!is_array($q)) {
                return $q;
            }
            if (!isset($q['type']) && isset($q['questionType'])) {
                $q['type'] = $q['questionType'];
            }
            $qc = is_array($q['content'] ?? null) ? $q['content'] : [];
            if (!isset($q['prompt']) && isset($qc['prompt'])) {
                $q['prompt'] = $qc['prompt'];
            }
            if (!isset($q['options']) && isset($qc['options'])) {
                $q['options'] = $qc['options'];
            }
            if (!isset($q['pairs']) && isset($qc['pairs'])) {
                $q['pairs'] = $qc['pairs'];
            }
            if (!isset($q['items']) && isset($qc['items'])) {
                $q['items'] = $qc['items'];
            }
            if (!isset($q['tolerance']) && isset($qc['tolerance'])) {
                $q['tolerance'] = $qc['tolerance'];
            }
            if (!isset($q['points']) && isset($q['defaultPoints'])) {
                $q['points'] = $q['defaultPoints'];
            }
            return $q;
        }, $questions);

        return [
            'id' => (string) ($activity['id'] ?? ''),
            'title' => (string) ($activity['title'] ?? ''),
            'description' => (string) ($activity['description'] ?? ''),
            'status' => (string) ($activity['status'] ?? 'DRAFT'),
            'openPractice' => (bool) ($cfg['openPractice'] ?? (($activity['visibility'] ?? '') === 'public_catalog')),
            'latestVersionId' => $activity['activeVersionId'] ?? null,
            'versionNumber' => isset($activity['versionNumber']) ? (int) $activity['versionNumber'] : 1,
            'instructions' => (string) ($activity['instructions'] ?? ''),
            'timeLimitSeconds' => $activity['timeLimitSeconds'] !== null ? (int) $activity['timeLimitSeconds'] : null,
            'passingScorePercent' => (int) ($activity['passingScorePercent'] ?? 60),
            'maxAttempts' => (int) ($activity['maxAttempts'] ?? 1),
            'shuffleQuestions' => (bool) ($cfg['shuffleQuestions'] ?? false),
            'shuffleOptions' => (bool) ($cfg['shuffleOptions'] ?? false),
            'revealAnswersPolicy' => (string) ($activity['revealAnswersPolicy'] ?? $cfg['revealAnswersPolicy'] ?? 'after_grading'),
            'gradingPolicy' => (string) ($activity['gradingPolicy'] ?? 'highest'),
            'questions' => $normalizedQuestions,
            'questionsCount' => count($normalizedQuestions),
            'createdAt' => (string) ($activity['createdAt'] ?? $activity['updatedAt'] ?? ''),
            'updatedAt' => (string) ($activity['updatedAt'] ?? ''),
        ];
    }

    /**
     * Compatibility projection: keeps legacy education.assessments / education.assessment_versions
     * in sync with canonical activity.* records for StudentAssessmentRunner, homework assignments,
     * and historical attempt queries until PLATFORM_13_B migrates the runner.
     *
     * @param array<string, mixed> $activity
     */
    private function projectActivityToLegacyAssessment(Scope $scope, array $activity): void
    {
        $mapped = $this->mapActivityToAssessment($activity);
        $assessmentId = (string) $mapped['id'];
        $versionId = is_string($mapped['latestVersionId']) ? $mapped['latestVersionId'] : null;
        if ($assessmentId === '' || $versionId === null) {
            return;
        }

        $this->db->createCommand(
            'INSERT INTO education.assessments (id, organization_id, author_person_id, title, description, status, open_practice)
             VALUES (CAST(:id AS uuid), CAST(:org AS uuid), CAST(:author AS uuid), :title, :desc, :status, :openPractice)
             ON CONFLICT (id) DO UPDATE SET
                title = EXCLUDED.title,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                open_practice = EXCLUDED.open_practice,
                updated_at = now()',
            [
                ':id' => $assessmentId,
                ':org' => $scope->organizationId,
                ':author' => $scope->personId,
                ':title' => mb_substr((string) $mapped['title'], 0, 200),
                ':desc' => (string) $mapped['description'],
                ':status' => (string) $mapped['status'],
                ':openPractice' => $mapped['openPractice'] ? 'true' : 'false',
            ],
        )->execute();

        $this->db->createCommand(
            'INSERT INTO education.assessment_versions
             (id, assessment_id, version_number, title, instructions, time_limit_seconds, passing_score_percent, max_attempts,
              shuffle_questions, shuffle_options, reveal_answers_policy, grading_policy, questions_snapshot)
             VALUES
             (CAST(:vId AS uuid), CAST(:aId AS uuid), :ver, :title, :inst, :tl, :pp, :ma,
              :sq, :so, :rp, :gp, CAST(:questions AS jsonb))
             ON CONFLICT (id) DO UPDATE SET
                title = EXCLUDED.title,
                instructions = EXCLUDED.instructions,
                time_limit_seconds = EXCLUDED.time_limit_seconds,
                passing_score_percent = EXCLUDED.passing_score_percent,
                max_attempts = EXCLUDED.max_attempts,
                shuffle_questions = EXCLUDED.shuffle_questions,
                shuffle_options = EXCLUDED.shuffle_options,
                reveal_answers_policy = EXCLUDED.reveal_answers_policy,
                grading_policy = EXCLUDED.grading_policy,
                questions_snapshot = EXCLUDED.questions_snapshot',
            [
                ':vId' => $versionId,
                ':aId' => $assessmentId,
                ':ver' => (int) $mapped['versionNumber'],
                ':title' => mb_substr((string) $mapped['title'], 0, 200),
                ':inst' => (string) $mapped['instructions'],
                ':tl' => $mapped['timeLimitSeconds'],
                ':pp' => (int) $mapped['passingScorePercent'],
                ':ma' => max(1, (int) $mapped['maxAttempts']),
                ':sq' => $mapped['shuffleQuestions'] ? 'true' : 'false',
                ':so' => $mapped['shuffleOptions'] ? 'true' : 'false',
                ':rp' => (string) $mapped['revealAnswersPolicy'],
                ':gp' => in_array($mapped['gradingPolicy'], ['highest', 'latest', 'average'], true) ? $mapped['gradingPolicy'] : 'highest',
                ':questions' => json_encode($mapped['questions'], JSON_THROW_ON_ERROR),
            ],
        )->execute();

        $this->db->createCommand(
            'UPDATE education.assessments SET latest_version_id = CAST(:vId AS uuid), updated_at = now() WHERE id = CAST(:aId AS uuid)',
            [':vId' => $versionId, ':aId' => $assessmentId],
        )->execute();
    }

    /**
     * Compatibility projection for legacy education.question_bank.
     *
     * @param array<string, mixed> $item
     */
    private function projectQuestionBankItemToLegacy(Scope $scope, array $item): void
    {
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            return;
        }
        $this->db->createCommand(
            'INSERT INTO education.question_bank
             (id, organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points)
             VALUES
             (CAST(:id AS uuid), CAST(:org AS uuid), CAST(:author AS uuid), :cat, CAST(:tags AS jsonb), :diff, :type, CAST(:content AS jsonb), CAST(:answerKey AS jsonb), :explanation, :points)
             ON CONFLICT (id) DO NOTHING',
            [
                ':id' => $id,
                ':org' => $scope->organizationId,
                ':author' => $scope->personId,
                ':cat' => (string) ($item['category'] ?? 'general'),
                ':tags' => json_encode($item['tags'] ?? [], JSON_THROW_ON_ERROR),
                ':diff' => (int) ($item['difficulty'] ?? 1),
                ':type' => (string) ($item['questionType'] ?? 'single_choice'),
                ':content' => json_encode($item['content'] ?? [], JSON_THROW_ON_ERROR),
                ':answerKey' => json_encode($item['answerKey'] ?? null, JSON_THROW_ON_ERROR),
                ':explanation' => (string) ($item['explanation'] ?? ''),
                ':points' => (int) ($item['defaultPoints'] ?? 1),
            ],
        )->execute();
    }

    /**
     * Maps canonical ActivityEngineService attempt payload to legacy student attempt response shape.
     *
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function mapEngineAttemptToLegacy(array $attempt): array
    {
        $cfg = is_array($attempt['config'] ?? null) ? $attempt['config'] : [];
        $exercise = is_array($attempt['exercise'] ?? null) ? $attempt['exercise'] : [];
        $answers = is_array($attempt['answers'] ?? null) ? $attempt['answers'] : [];
        if (isset($answers['answers']) && is_array($answers['answers'])) {
            $answers = $answers['answers'];
        }
        $eval = is_array($attempt['evaluation'] ?? null) ? $attempt['evaluation'] : [];
        $state = (string) ($attempt['state'] ?? 'IN_PROGRESS');
        $rawQuestions = is_array($exercise['questions'] ?? null) ? $exercise['questions'] : [];
        $questions = array_map(static function (mixed $q): array {
            $qArr = is_array($q) ? $q : [];
            $content = is_array($qArr['content'] ?? null) ? $qArr['content'] : [];
            return [
                'id' => (string) ($qArr['id'] ?? ''),
                'type' => (string) ($qArr['questionType'] ?? $qArr['type'] ?? 'single_choice'),
                'questionType' => (string) ($qArr['questionType'] ?? $qArr['type'] ?? 'single_choice'),
                'prompt' => (string) ($content['prompt'] ?? $qArr['prompt'] ?? ''),
                'options' => is_array($content['options'] ?? null) ? $content['options'] : ($qArr['options'] ?? []),
                'items' => is_array($content['items'] ?? null) ? $content['items'] : ($qArr['items'] ?? []),
                'pairs' => is_array($content['pairs'] ?? null) ? $content['pairs'] : ($qArr['pairs'] ?? []),
                'points' => (int) ($qArr['points'] ?? 1),
                'content' => $content,
            ];
        }, $rawQuestions);

        $isFinalized = in_array($state, ['EVALUATED', 'COMPLETED', 'PENDING_REVIEW', 'EXPIRED'], true);
        $result = $isFinalized && $eval !== [] ? [
            'score' => (float) ($attempt['rawScore'] ?? $eval['rawScore'] ?? 0),
            'maxScore' => (float) ($attempt['maxScore'] ?? $eval['maxScore'] ?? 0),
            'percentage' => (float) ($attempt['percentage'] ?? $eval['percentage'] ?? 0),
            'passed' => (bool) ($attempt['passed'] ?? $eval['passed'] ?? false),
            'breakdown' => is_array($eval['breakdown'] ?? null) ? $eval['breakdown'] : [],
            'feedback' => (string) ($eval['feedback'] ?? ''),
            'requiresManualReview' => (bool) ($eval['requiresManualReview'] ?? false),
        ] : null;

        return [
            'id' => (string) ($attempt['id'] ?? ''),
            'activityId' => (string) ($attempt['activityId'] ?? ''),
            'versionId' => (string) ($attempt['versionId'] ?? ''),
            'assignmentId' => $attempt['assignmentId'] ?? null,
            'state' => $state,
            'revision' => (int) ($attempt['revision'] ?? 1),
            'startedAt' => $attempt['startedAt'] ?? null,
            'expiresAt' => $attempt['expiresAt'] ?? null,
            'serverNow' => $attempt['serverNow'] ?? gmdate(DATE_ATOM, time()),
            'remainingSeconds' => $attempt['remainingSeconds'] ?? null,
            'submittedAt' => $attempt['submittedAt'] ?? null,
            'title' => (string) ($attempt['activityTitle'] ?? ''),
            'instructions' => (string) ($cfg['instructions'] ?? ''),
            'timeLimitSeconds' => $exercise['timeLimitSeconds'] ?? $cfg['timeLimitSeconds'] ?? null,
            'passingScorePercent' => (int) ($cfg['passingScorePercent'] ?? 60),
            'questions' => $questions,
            'answers' => $answers,
            'score' => $attempt['rawScore'] !== null ? (float) $attempt['rawScore'] : null,
            'maxScore' => $attempt['maxScore'] !== null ? (float) $attempt['maxScore'] : null,
            'percentage' => $attempt['percentage'] !== null ? (float) $attempt['percentage'] : null,
            'passed' => $attempt['passed'] !== null ? (bool) $attempt['passed'] : null,
            'manualReviewNotes' => $attempt['reviewNotes'] ?? null,
            'result' => $result,
        ];
    }
}
