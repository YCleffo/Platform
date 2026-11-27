<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Activity\Application\ActivityEngineService;
use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Thin Education domain adapter for Assessment HTTP routes over Universal Activity Engine.
 * All authoring, versioning, question bank, attempts, scoring, and manual review run through ActivityEngineService.
 */
final readonly class AssessmentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ActivityEngineService $engine,
        private ?ActivityResultSink $activityResults = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listAssessments(Scope $scope, array $query, AuthenticatedIdentity $identity): array
    {
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

    /**
     * Creates an assessment always as DRAFT to guarantee immutable version transitions.
     *
     * @return array<string, mixed>
     */
    public function createAssessment(Scope $scope, array $input, AuthenticatedIdentity $identity): array
    {
        try {
            $payload = $this->mapEducationInputToActivityPayload($input, false);
            $activity = $this->engine->createActivity($identity, $scope->organizationId, $payload);

            return $this->mapActivityToAssessment($activity);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssessment(Scope $scope, string $id, AuthenticatedIdentity $identity): array
    {
        $id = Input::uuid($id);
        try {
            $activity = $this->engine->getActivity($identity, $scope->organizationId, $id);

            return $this->mapActivityToAssessment($activity);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAssessment(Scope $scope, string $id, array $input, AuthenticatedIdentity $identity): array
    {
        $id = Input::uuid($id);
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

    /**
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
     *
     * @return array<string, mixed>
     */
    public function publishAssessment(Scope $scope, string $id, array $input, AuthenticatedIdentity $identity): array
    {
        $id = Input::uuid($id);
        try {
            $current = $this->engine->getActivity($identity, $scope->organizationId, $id);
            $payload = $this->mapEducationInputToActivityPayload($input, true, $current);
            $activity = $this->engine->updateActivity($identity, $scope->organizationId, $id, $payload);

            return $this->mapActivityToAssessment($activity);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(Scope $scope, string $assessmentId, AuthenticatedIdentity $identity): array
    {
        $id = Input::uuid($assessmentId);
        try {
            return $this->engine->listVersions($identity, $scope->organizationId, $id);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function listQuestionBank(Scope $scope, array $query, AuthenticatedIdentity $identity): array
    {
        try {
            return $this->engine->listQuestionBank($identity, $scope->organizationId, $query);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createQuestionBankItem(Scope $scope, array $input, AuthenticatedIdentity $identity): array
    {
        try {
            return $this->engine->createQuestionBankItem($identity, $scope->organizationId, $input);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    public function deleteQuestionBankItem(Scope $scope, string $id, AuthenticatedIdentity $identity): void
    {
        $id = Input::uuid($id);
        try {
            $this->engine->archiveQuestionBankItem($identity, $scope->organizationId, $id, false);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * Student view: published assessments available via open practice or assigned.
     *
     * @return array<string, mixed>
     */
    public function listStudentAssessments(Scope $scope, AuthenticatedIdentity $identity): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $this->assertStudentActive($scope);

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

    /**
     * Starts (or idempotently resumes an IN_PROGRESS) student attempt.
     *
     * @return array<string, mixed>
     */
    public function startStudentAttempt(Scope $scope, string $assessmentId, array $input, AuthenticatedIdentity $identity): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $assessmentId = Input::uuid($assessmentId);
        $this->assertStudentActive($scope);

        if (empty($input['assignmentId'])) {
            $resolvedAssignmentId = $this->findActiveAssignmentId($scope, $assessmentId);
            if ($resolvedAssignmentId !== null) {
                $input['assignmentId'] = $resolvedAssignmentId;
            }
        }

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

    private function findActiveAssignmentId(Scope $scope, string $activityId): ?string
    {
        $row = $this->db->createCommand(
            "SELECT a.id::text
             FROM activity.assignments a
             WHERE a.organization_id = CAST(:org AS uuid)
               AND a.activity_id = CAST(:aid AS uuid)
               AND a.status = 'ACTIVE'
               AND a.revoked_at IS NULL
               AND (a.opens_at IS NULL OR a.opens_at <= now())
               AND (a.due_at IS NULL OR a.allow_late = true OR a.due_at >= now())
               AND (
                   (a.target_type IN ('student', 'homework') AND a.target_id = CAST(:student AS uuid))
                   OR (a.target_type = 'person' AND a.target_id = CAST(:person AS uuid))
                   OR (a.target_type = 'group' AND a.target_id IN (
                       SELECT group_id FROM education.group_students
                       WHERE student_id = CAST(:student AS uuid) AND organization_id = CAST(:org AS uuid)
                   ))
               )
             ORDER BY a.created_at DESC
             LIMIT 1",
            [
                ':org' => $scope->organizationId,
                ':aid' => $activityId,
                ':student' => (string) $scope->studentId,
                ':person' => $scope->personId,
            ],
        )->queryScalar();

        return is_string($row) && $row !== '' ? $row : null;
    }

    /**
     * Retrieves attempt view with masking rules enforced by ActivityEngineService.
     *
     * @return array<string, mixed>
     */
    public function getStudentAttempt(Scope $scope, string $attemptId, AuthenticatedIdentity $identity): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        try {
            $attempt = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);

            return $this->mapEngineAttemptToLegacy($attempt);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * Saves in-progress answers with CAS optimistic locking on revision.
     *
     * @return array<string, mixed>
     */
    public function saveStudentAttempt(Scope $scope, string $attemptId, array $input, AuthenticatedIdentity $identity): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        if (!isset($input['revision']) && isset($input['version']) && is_numeric($input['version'])) {
            $input['revision'] = (int) $input['version'] + 1;
        }

        try {
            return $this->engine->saveDraft($identity, $scope->organizationId, $attemptId, $input);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason === 'attempt_expired' ? 'attempt_time_expired' : $e->reason);
        }
    }

    /**
     * Submits and grades the attempt idempotently.
     *
     * @return array<string, mixed>
     */
    public function submitStudentAttempt(Scope $scope, string $attemptId, array $input, AuthenticatedIdentity $identity): array
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }

        $attemptId = Input::uuid($attemptId);

        try {
            if (!isset($input['revision']) && isset($input['version']) && is_numeric($input['version'])) {
                $input['revision'] = (int) $input['version'] + 1;
            }
            if (!isset($input['revision'])) {
                $current = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);
                $input['revision'] = (int) ($current['revision'] ?? 1);
            }
            try {
                $attempt = $this->engine->submitAttempt($identity, $scope->organizationId, $attemptId, $input);
            } catch (HttpError $e) {
                if ($e->status === 409 && $e->reason === 'attempt_expired') {
                    $attempt = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);
                } else {
                    throw $e;
                }
            }

            if ($this->activityResults !== null && in_array((string) ($attempt['state'] ?? ''), ['EVALUATED', 'COMPLETED', 'EXPIRED'], true)) {
                $this->activityResults->finished($scope, (string) ($attempt['id'] ?? $attemptId));
            }

            return $this->mapEngineAttemptToLegacy($attempt);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }
    }

    /**
     * Teacher list of attempts for an assessment (canonical + read-only historical fallback).
     *
     * @return list<array<string, mixed>>
     */
    public function listAssessmentAttempts(Scope $scope, string $assessmentId, AuthenticatedIdentity $identity): array
    {
        $assessmentId = Input::uuid($assessmentId);
        try {
            $this->engine->getActivity($identity, $scope->organizationId, $assessmentId);
        } catch (HttpError $e) {
            throw new EducationError($e->status, $e->reason);
        }

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

    /**
     * Teacher review & manual score override with audit history.
     *
     * @return array<string, mixed>
     */
    public function reviewAttempt(Scope $scope, string $attemptId, array $input, AuthenticatedIdentity $identity): array
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
            if ($score === null && $questionReviews === []) {
                $current = $this->engine->getAttempt($identity, $scope->organizationId, $attemptId);
                $payload['rawScore'] = is_numeric($current['rawScore'] ?? null) ? (float) $current['rawScore'] : 0.0;
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
            throw new EducationError($e->status, $e->reason);
        }
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
