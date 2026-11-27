<?php

declare(strict_types=1);

namespace App\Activity\Application;

use App\Activity\Infrastructure\DefaultActivityDomainPolicy;
use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Notifications\Application\NotificationsService;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Universal Activity Engine (PLATFORM_11 / PLATFORM_12).
 * Handles multi-plugin authoring, immutable versions, Question Bank 2.0, assignments,
 * strict Attempt State Machine (CREATED -> IN_PROGRESS -> SUBMITTED -> PENDING_REVIEW -> EVALUATED/COMPLETED/EXPIRED/CANCELLED/INVALIDATED),
 * CAS revision drafts, server deadline enforcement, answer-disclosure policies, and real-time analytics.
 */
final readonly class ActivityEngineService
{
    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private ActivityPluginRegistry $plugins,
        private RealtimeService $realtime,
        private NotificationsService $notifications,
        private ?ActivityDomainPolicyInterface $domainPolicy = null,
        private ?ActivityResultListenerInterface $resultListener = null,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function listPlugins(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $this->assertAccess($identity, $organizationId, 'activity.use');
        return ['items' => $this->plugins->describeAll()];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listActivities(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $where = 'i.organization_id = CAST(:org AS uuid)';
        $params = [':org' => $orgId, ':person' => $personId];

        $rawStatus = $query['status'] ?? null;
        if (is_string($rawStatus) && $rawStatus !== '') {
            $where .= ' AND i.status = :status';
            $params[':status'] = $rawStatus;
        } else {
            $where .= " AND i.status <> 'ARCHIVED'";
        }
        $rawPlugin = $query['pluginCode'] ?? null;
        if (is_string($rawPlugin) && $rawPlugin !== '') {
            $where .= ' AND i.plugin_code = :pcode';
            $params[':pcode'] = $rawPlugin;
        }
        $rawSearch = $query['q'] ?? null;
        if (is_string($rawSearch) && trim($rawSearch) !== '') {
            $where .= ' AND (i.title ILIKE :q OR i.description ILIKE :q)';
            $params[':q'] = '%' . trim($rawSearch) . '%';
        }

        $rows = $this->db->createCommand(
            "SELECT
                i.id::text, i.plugin_code AS \"pluginCode\", p.title AS \"pluginTitle\", p.category,
                i.author_person_id::text AS \"authorPersonId\",
                i.title, i.description, i.status, i.visibility, i.tags::text AS \"tagsJson\",
                i.active_version_id::text AS \"activeVersionId\",
                v.version_number AS \"versionNumber\",
                v.config::text AS \"configJson\",
                v.time_limit_seconds AS \"timeLimitSeconds\",
                v.passing_score_percent AS \"passingScorePercent\",
                v.max_attempts AS \"maxAttempts\",
                i.updated_at::text AS \"updatedAt\",
                (SELECT count(*)::int FROM activity.attempts at WHERE at.activity_id = i.id AND at.participant_person_id = CAST(:person AS uuid)) AS \"myAttemptsCount\",
                (SELECT MAX(at.percentage) FROM activity.attempts at WHERE at.activity_id = i.id AND at.participant_person_id = CAST(:person AS uuid) AND at.state IN ('EVALUATED', 'COMPLETED')) AS \"myBestPercentage\"
             FROM activity.items i
             JOIN activity.plugins p ON p.code = i.plugin_code
             LEFT JOIN activity.versions v ON v.id = i.active_version_id
             WHERE {$where}
             ORDER BY i.updated_at DESC, i.id DESC
             LIMIT 300",
            $params,
        )->queryAll();

        $policy = $this->policy();
        $visible = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $r['config'] = ActivityPayload::decodeObject((string) ($r['configJson'] ?? '{}'));
            if (!$policy->canAccessActivity($identity, $orgId, $r)) {
                continue;
            }
            $decodedTags = json_decode((string) ($r['tagsJson'] ?? '[]'), true);
            $r['tags'] = is_array($decodedTags) ? $decodedTags : [];
            unset($r['tagsJson'], $r['configJson'], $r['config']);
            $visible[] = $r;
            if (count($visible) >= 100) {
                break;
            }
        }

        return ['items' => $visible];
    }

    /** @return array<string, mixed> */
    public function getActivity(AuthenticatedIdentity $identity, string $organizationId, string $activityId): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');

        $row = $this->loadActivityRow($orgId, $aid);
        if (!is_array($row)) {
            throw new HttpError(404, 'activity_not_found');
        }

        $row['tags'] = json_decode((string) ($row['tagsJson'] ?? '[]'), true) ?: [];
        $row['config'] = ActivityPayload::decodeObject((string) ($row['configJson'] ?? '{}'));
        if (!$this->policy()->canAccessActivity($identity, $orgId, $row)) {
            throw new HttpError(403, 'activity_forbidden');
        }

        $isAuthorOrAdmin = ((string) ($row['authorPersonId'] ?? '') === $personId) || $this->isOrgAdmin($identity->accountId);
        $rawContent = ActivityPayload::decodeObject((string) ($row['contentJson'] ?? '{}'));
        // Never leak answer keys or explanations to non-authors!
        if (!$isAuthorOrAdmin && isset($rawContent['questions']) && is_array($rawContent['questions'])) {
            $rawContent['questions'] = array_map(static function (mixed $q): mixed {
                if (!is_array($q)) {
                    return $q;
                }
                unset($q['answerKey'], $q['answer_key'], $q['explanation']);
                return $q;
            }, $rawContent['questions']);
        }
        $row['content'] = $rawContent;
        unset($row['tagsJson'], $row['configJson'], $row['contentJson']);
        return $row;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createActivity(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.create');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rawPlugin = $input['pluginCode'] ?? 'platform-assessment';
        $pluginCode = is_string($rawPlugin) ? $rawPlugin : 'platform-assessment';
        $plugin = $this->plugins->get($pluginCode);

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new HttpError(422, 'title_required');
        }
        $description = trim((string) ($input['description'] ?? ''));
        $instructions = trim((string) ($input['instructions'] ?? ''));
        $visibility = is_string($input['visibility'] ?? null) && in_array($input['visibility'], ['private', 'organization', 'group', 'public_catalog'], true)
            ? $input['visibility'] : 'organization';
        $tags = ActivityPayload::stringList($input['tags'] ?? null);
        $config = array_merge($plugin->defaultConfig(), ActivityPayload::assoc($input['config'] ?? null));
        $revealPolicy = $this->normalizeRevealPolicy($config['revealAnswersPolicy'] ?? $input['revealAnswersPolicy'] ?? 'after_grading');
        $config['revealAnswersPolicy'] = $revealPolicy;
        $content = ActivityPayload::assoc($input['content'] ?? null);
        $timeLimit = null;
        if (array_key_exists('timeLimitSeconds', $input) && $input['timeLimitSeconds'] !== null && $input['timeLimitSeconds'] !== '') {
            if (!is_numeric($input['timeLimitSeconds']) || (int) $input['timeLimitSeconds'] < 30 || (int) $input['timeLimitSeconds'] > 14400) {
                throw new HttpError(422, 'invalid_time_limit');
            }
            $timeLimit = (int) $input['timeLimitSeconds'];
        }
        $maxAttempts = 0;
        if (array_key_exists('maxAttempts', $input) && $input['maxAttempts'] !== null && $input['maxAttempts'] !== '') {
            if (!is_numeric($input['maxAttempts']) || (int) $input['maxAttempts'] < 0 || (int) $input['maxAttempts'] > 20) {
                throw new HttpError(422, 'invalid_max_attempts');
            }
            $maxAttempts = (int) $input['maxAttempts'];
        }
        $passingPct = 60;
        if (array_key_exists('passingScorePercent', $input) && $input['passingScorePercent'] !== null && $input['passingScorePercent'] !== '') {
            if (!is_numeric($input['passingScorePercent']) || (int) $input['passingScorePercent'] < 0 || (int) $input['passingScorePercent'] > 100) {
                throw new HttpError(422, 'invalid_passing_score');
            }
            $passingPct = (int) $input['passingScorePercent'];
        }
        $gradingPolicy = is_string($input['gradingPolicy'] ?? null) && in_array($input['gradingPolicy'], ['highest', 'latest', 'average', 'practice_no_grade'], true)
            ? $input['gradingPolicy'] : 'highest';
        $status = ($input['publish'] ?? false) === true ? 'PUBLISHED' : 'DRAFT';
        if ($status === 'PUBLISHED' && $pluginCode === 'platform-assessment') {
            $questions = is_array($content['questions'] ?? null) ? $content['questions'] : [];
            AssessmentActivityPlugin::validateQuestions($questions);
        }

        $activityId = '';
        $this->db->transaction(function () use (
            &$activityId,
            $identity,
            $orgId,
            $personId,
            $pluginCode,
            $title,
            $description,
            $instructions,
            $visibility,
            $tags,
            $config,
            $revealPolicy,
            $content,
            $timeLimit,
            $maxAttempts,
            $passingPct,
            $gradingPolicy,
            $status,
        ): void {
            $activityId = (string) $this->db->createCommand(
                'INSERT INTO activity.items (
                    organization_id, plugin_code, author_person_id, author_account_id, title, description, status, visibility, tags
                 ) VALUES (
                    CAST(:org AS uuid), :pcode, CAST(:person AS uuid), CAST(:account AS uuid), :title, :desc, :status, :vis, CAST(:tags AS jsonb)
                 ) RETURNING id::text',
                [
                    ':org' => $orgId,
                    ':pcode' => $pluginCode,
                    ':person' => $personId,
                    ':account' => $identity->accountId,
                    ':title' => mb_substr($title, 0, 200),
                    ':desc' => $description,
                    ':status' => $status,
                    ':vis' => $visibility,
                    ':tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                ],
            )->queryScalar();

            $versionId = (string) $this->db->createCommand(
                'INSERT INTO activity.versions (
                    activity_id, version_number, title, instructions, config, content,
                    time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, created_by_person_id
                 ) VALUES (
                    CAST(:aid AS uuid), 1, :title, :inst, CAST(:cfg AS jsonb), CAST(:cnt AS jsonb),
                    :tl, :ma, :pp, :gp, :rp, CAST(:person AS uuid)
                 ) RETURNING id::text',
                [
                    ':aid' => $activityId,
                    ':title' => mb_substr($title, 0, 200),
                    ':inst' => $instructions,
                    ':cfg' => json_encode($config, JSON_THROW_ON_ERROR),
                    ':cnt' => $content === [] ? '{}' : json_encode($content, JSON_THROW_ON_ERROR),
                    ':tl' => $timeLimit,
                    ':ma' => $maxAttempts,
                    ':pp' => $passingPct,
                    ':gp' => $gradingPolicy,
                    ':rp' => $revealPolicy,
                    ':person' => $personId,
                ],
            )->queryScalar();

            $this->db->createCommand(
                'UPDATE activity.items SET active_version_id = CAST(:vid AS uuid) WHERE id = CAST(:aid AS uuid)',
                [':vid' => $versionId, ':aid' => $activityId],
            )->execute();
        });

        return $this->getActivity($identity, $orgId, $activityId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateActivity(AuthenticatedIdentity $identity, string $organizationId, string $activityId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.create');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');
        $current = $this->getActivity($identity, $orgId, $aid);
        if (((string) ($current['authorPersonId'] ?? '') !== $personId) && !$this->isOrgAdmin($identity->accountId)) {
            throw new HttpError(403, 'activity_forbidden');
        }

        $currentStatus = (string) ($current['status'] ?? 'DRAFT');
        $publishRequested = ($input['publish'] ?? false) === true;

        // Published versions are immutable: only lifecycle transitions (CLOSED/ARCHIVED) or publishing a new version are allowed.
        if ($currentStatus === 'PUBLISHED' && !$publishRequested) {
            $requestedStatus = is_string($input['status'] ?? null) ? $input['status'] : $currentStatus;
            if (in_array($requestedStatus, ['CLOSED', 'ARCHIVED'], true) && $requestedStatus !== $currentStatus) {
                $this->db->createCommand(
                    'UPDATE activity.items SET status = :status, updated_at = now()
                     WHERE id = CAST(:aid AS uuid) AND organization_id = CAST(:org AS uuid)',
                    [':status' => $requestedStatus, ':aid' => $aid, ':org' => $orgId],
                )->execute();
                return $this->getActivity($identity, $orgId, $aid);
            }
            throw new HttpError(409, 'published_version_immutable');
        }

        $title = trim((string) ($input['title'] ?? $current['title']));
        if ($title === '') {
            throw new HttpError(422, 'title_required');
        }
        $description = trim((string) ($input['description'] ?? $current['description']));
        $instructions = trim((string) ($input['instructions'] ?? $current['instructions']));
        $visibility = is_string($input['visibility'] ?? null) && in_array($input['visibility'], ['private', 'organization', 'group', 'public_catalog'], true)
            ? $input['visibility'] : (string) ($current['visibility'] ?? 'organization');
        $config = is_array($input['config'] ?? null)
            ? array_merge(ActivityPayload::assoc($current['config'] ?? null), ActivityPayload::assoc($input['config']))
            : (is_array($current['config']) ? ActivityPayload::assoc($current['config']) : []);
        $revealPolicy = $this->normalizeRevealPolicy($config['revealAnswersPolicy'] ?? $input['revealAnswersPolicy'] ?? $current['revealAnswersPolicy'] ?? 'after_grading');
        $config['revealAnswersPolicy'] = $revealPolicy;
        $content = is_array($input['content'] ?? null) ? $input['content'] : (is_array($current['content']) ? $current['content'] : []);
        if (array_key_exists('timeLimitSeconds', $input)) {
            if ($input['timeLimitSeconds'] === null || $input['timeLimitSeconds'] === '') {
                $timeLimit = null;
            } elseif (!is_numeric($input['timeLimitSeconds']) || (int) $input['timeLimitSeconds'] < 30 || (int) $input['timeLimitSeconds'] > 14400) {
                throw new HttpError(422, 'invalid_time_limit');
            } else {
                $timeLimit = (int) $input['timeLimitSeconds'];
            }
        } else {
            $timeLimit = $current['timeLimitSeconds'] !== null ? (int) $current['timeLimitSeconds'] : null;
        }
        if (array_key_exists('maxAttempts', $input)) {
            if (!is_numeric($input['maxAttempts']) || (int) $input['maxAttempts'] < 0 || (int) $input['maxAttempts'] > 20) {
                throw new HttpError(422, 'invalid_max_attempts');
            }
            $maxAttempts = (int) $input['maxAttempts'];
        } else {
            $maxAttempts = (int) $current['maxAttempts'];
        }
        if (array_key_exists('passingScorePercent', $input)) {
            if (!is_numeric($input['passingScorePercent']) || (int) $input['passingScorePercent'] < 0 || (int) $input['passingScorePercent'] > 100) {
                throw new HttpError(422, 'invalid_passing_score');
            }
            $passingPct = (int) $input['passingScorePercent'];
        } else {
            $passingPct = (int) $current['passingScorePercent'];
        }
        $gradingPolicy = is_string($input['gradingPolicy'] ?? null) && in_array($input['gradingPolicy'], ['highest', 'latest', 'average', 'practice_no_grade'], true)
            ? $input['gradingPolicy'] : (string) $current['gradingPolicy'];
        $newStatus = $publishRequested
            ? 'PUBLISHED'
            : (is_string($input['status'] ?? null) && in_array($input['status'], ['DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED'], true)
                ? $input['status'] : $currentStatus);

        if ($newStatus === 'PUBLISHED' && ($current['pluginCode'] ?? '') === 'platform-assessment') {
            $questions = is_array($content['questions'] ?? null) ? $content['questions'] : [];
            AssessmentActivityPlugin::validateQuestions($questions);
        }

        $activeVersionId = is_string($current['activeVersionId'] ?? null) ? $current['activeVersionId'] : null;

        $this->db->transaction(function () use (
            $orgId,
            $aid,
            $personId,
            $currentStatus,
            $activeVersionId,
            $title,
            $description,
            $instructions,
            $visibility,
            $config,
            $revealPolicy,
            $content,
            $timeLimit,
            $maxAttempts,
            $passingPct,
            $gradingPolicy,
            $newStatus,
        ): void {
            if ($currentStatus === 'DRAFT' && $activeVersionId !== null) {
                $this->db->createCommand(
                    'UPDATE activity.versions
                     SET title = :title, instructions = :inst, config = CAST(:cfg AS jsonb), content = CAST(:cnt AS jsonb),
                         time_limit_seconds = :tl, max_attempts = :ma, passing_score_percent = :pp, grading_policy = :gp, reveal_answers_policy = :rp
                     WHERE id = CAST(:vid AS uuid) AND activity_id = CAST(:aid AS uuid)',
                    [
                        ':vid' => $activeVersionId,
                        ':aid' => $aid,
                        ':title' => mb_substr($title, 0, 200),
                        ':inst' => $instructions,
                        ':cfg' => json_encode($config, JSON_THROW_ON_ERROR),
                        ':cnt' => $content === [] ? '{}' : json_encode($content, JSON_THROW_ON_ERROR),
                        ':tl' => $timeLimit,
                        ':ma' => $maxAttempts,
                        ':pp' => $passingPct,
                        ':gp' => $gradingPolicy,
                        ':rp' => $revealPolicy,
                    ],
                )->execute();
                $vid = $activeVersionId;
            } else {
                $nextVer = (int) $this->db->createCommand(
                    'SELECT COALESCE(MAX(version_number), 0) + 1 FROM activity.versions WHERE activity_id = CAST(:aid AS uuid)',
                    [':aid' => $aid],
                )->queryScalar();

                $vid = (string) $this->db->createCommand(
                    'INSERT INTO activity.versions (
                        activity_id, version_number, title, instructions, config, content,
                        time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, created_by_person_id
                     ) VALUES (
                        CAST(:aid AS uuid), :ver, :title, :inst, CAST(:cfg AS jsonb), CAST(:cnt AS jsonb),
                        :tl, :ma, :pp, :gp, :rp, CAST(:person AS uuid)
                     ) RETURNING id::text',
                    [
                        ':aid' => $aid,
                        ':ver' => $nextVer,
                        ':title' => mb_substr($title, 0, 200),
                        ':inst' => $instructions,
                        ':cfg' => json_encode($config, JSON_THROW_ON_ERROR),
                        ':cnt' => $content === [] ? '{}' : json_encode($content, JSON_THROW_ON_ERROR),
                        ':tl' => $timeLimit,
                        ':ma' => $maxAttempts,
                        ':pp' => $passingPct,
                        ':gp' => $gradingPolicy,
                        ':rp' => $revealPolicy,
                        ':person' => $personId,
                    ],
                )->queryScalar();
            }

            $this->db->createCommand(
                'UPDATE activity.items SET title = :title, description = :desc, status = :status, visibility = :vis, active_version_id = CAST(:vid AS uuid), updated_at = now()
                 WHERE id = CAST(:aid AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':title' => mb_substr($title, 0, 200), ':desc' => $description, ':status' => $newStatus, ':vis' => $visibility, ':vid' => $vid, ':aid' => $aid, ':org' => $orgId],
            )->execute();
        });

        return $this->getActivity($identity, $orgId, $aid);
    }

    /** @return array<string, mixed> */
    public function duplicateActivity(AuthenticatedIdentity $identity, string $organizationId, string $activityId): array
    {
        $src = $this->getActivity($identity, $organizationId, $activityId);
        $rawTitle = $src['title'] ?? null;
        $baseTitle = is_string($rawTitle) ? $rawTitle : 'Activity';
        return $this->createActivity($identity, $organizationId, [
            'pluginCode' => $src['pluginCode'],
            'title' => $baseTitle . ' (Copy)',
            'description' => $src['description'],
            'instructions' => $src['instructions'],
            'visibility' => $src['visibility'] ?? 'organization',
            'config' => $src['config'],
            'content' => $src['content'],
            'timeLimitSeconds' => $src['timeLimitSeconds'],
            'maxAttempts' => $src['maxAttempts'],
            'passingScorePercent' => $src['passingScorePercent'],
            'gradingPolicy' => $src['gradingPolicy'],
            'tags' => $src['tags'],
        ]);
    }

    /**
     * Lists immutable version history for an activity.
     *
     * @return list<array<string, mixed>>
     */
    public function listVersions(AuthenticatedIdentity $identity, string $organizationId, string $activityId): array
    {
        $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');
        $this->getActivity($identity, $orgId, $aid);

        $rows = $this->db->createCommand(
            'SELECT v.id::text, v.version_number AS "versionNumber", v.title, v.instructions,
                    v.time_limit_seconds AS "timeLimitSeconds", v.passing_score_percent AS "passingScorePercent",
                    v.max_attempts AS "maxAttempts", v.reveal_answers_policy AS "revealAnswersPolicy",
                    v.grading_policy AS "gradingPolicy",
                    COALESCE(jsonb_array_length(v.content->\'questions\'), 0) AS "questionsCount",
                    v.created_at::text AS "createdAt",
                    (SELECT count(*)::int FROM activity.attempts att WHERE att.version_id = v.id) AS "attemptsCount"
             FROM activity.versions v
             WHERE v.activity_id = CAST(:aid AS uuid)
             ORDER BY v.version_number DESC',
            [':aid' => $aid],
        )->queryAll();

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['id'],
            'versionNumber' => (int) $r['versionNumber'],
            'title' => (string) $r['title'],
            'instructions' => (string) ($r['instructions'] ?? ''),
            'timeLimitSeconds' => $r['timeLimitSeconds'] !== null ? (int) $r['timeLimitSeconds'] : null,
            'passingScorePercent' => (int) $r['passingScorePercent'],
            'maxAttempts' => (int) $r['maxAttempts'],
            'revealAnswersPolicy' => (string) $r['revealAnswersPolicy'],
            'gradingPolicy' => (string) $r['gradingPolicy'],
            'questionsCount' => (int) $r['questionsCount'],
            'attemptsCount' => (int) $r['attemptsCount'],
            'createdAt' => (string) $r['createdAt'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function startAttempt(AuthenticatedIdentity $identity, string $organizationId, string $activityId, array $input = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');

        $activity = $this->loadActivityRow($orgId, $aid);
        if (!is_array($activity)) {
            throw new HttpError(404, 'activity_not_found');
        }
        $activity['config'] = ActivityPayload::decodeObject((string) ($activity['configJson'] ?? '{}'));
        if (!$this->policy()->canAccessActivity($identity, $orgId, $activity)) {
            throw new HttpError(403, 'activity_forbidden');
        }
        if (($activity['status'] ?? '') !== 'PUBLISHED') {
            throw new HttpError(409, 'activity_not_published');
        }

        $rawAssignmentId = $input['assignmentId'] ?? null;
        $assignmentId = is_string($rawAssignmentId) && $rawAssignmentId !== ''
            ? RealtimeService::assertUuid($rawAssignmentId, 'assignmentId') : null;

        $rawIdempotency = $input['idempotencyKey'] ?? null;
        $idempotencyKey = is_string($rawIdempotency) && trim($rawIdempotency) !== ''
            ? mb_substr(trim($rawIdempotency), 0, 128) : null;
        if ($idempotencyKey !== null) {
            $existingId = $this->db->createCommand(
                'SELECT id::text FROM activity.attempts
                 WHERE organization_id = CAST(:org AS uuid) AND participant_person_id = CAST(:person AS uuid)
                   AND activity_id = CAST(:aid AS uuid) AND idempotency_key = :ik LIMIT 1',
                [':org' => $orgId, ':person' => $personId, ':aid' => $aid, ':ik' => $idempotencyKey],
            )->queryScalar();
            if (is_string($existingId)) {
                return $this->getAttempt($identity, $orgId, $existingId);
            }
        }

        // Persist any overdue IN_PROGRESS attempts as EXPIRED in a committed transaction BEFORE starting/checking limits.
        $this->expireOverdueAttemptsForParticipant($orgId, $personId, $aid);

        $resumeActive = ($input['resumeActive'] ?? false) === true || ($input['resume'] ?? false) === true;
        $rawPlugin = $activity['pluginCode'] ?? null;
        $pluginCode = is_string($rawPlugin) ? $rawPlugin : 'platform-assessment';
        $plugin = $this->plugins->get($pluginCode);
        $policy = $this->policy();

        /** @var array{id: string, isNew: bool} $txResult */
        $txResult = $this->db->transaction(function () use (
            $identity,
            $orgId,
            $aid,
            $personId,
            $assignmentId,
            $idempotencyKey,
            $resumeActive,
            $activity,
            $plugin,
            $pluginCode,
            $policy,
            $input,
        ): array {
            // Serialize concurrent starts for the same (org, participant, activity)
            $this->db->createCommand(
                "SELECT pg_advisory_xact_lock(('x' || substr(md5(:lk), 1, 16))::bit(64)::bigint)",
                [':lk' => $orgId . ':' . $personId . ':' . $aid],
            )->execute();

            if ($idempotencyKey !== null) {
                $existingId = $this->db->createCommand(
                    'SELECT id::text FROM activity.attempts
                     WHERE organization_id = CAST(:org AS uuid) AND participant_person_id = CAST(:person AS uuid)
                       AND activity_id = CAST(:aid AS uuid) AND idempotency_key = :ik LIMIT 1',
                    [':org' => $orgId, ':person' => $personId, ':aid' => $aid, ':ik' => $idempotencyKey],
                )->queryScalar();
                if (is_string($existingId)) {
                    return ['id' => $existingId, 'isNew' => false];
                }
            }

            $assignOverrideCfg = [];
            if ($assignmentId !== null) {
                $assign = $this->db->createCommand(
                    'SELECT id::text, organization_id::text AS "organizationId", activity_id::text AS "activityId",
                            version_id::text AS "versionId", assigned_by_person_id::text AS "assignedByPersonId",
                            target_type AS "targetType", target_id::text AS "targetId",
                            opens_at::text AS "opensAt", due_at::text AS "dueAt", allow_late AS "allowLate",
                            config_override::text AS "configOverrideJson", status, revoked_at::text AS "revokedAt"
                     FROM activity.assignments
                     WHERE id = CAST(:id AS uuid)
                     FOR UPDATE',
                    [':id' => $assignmentId],
                )->queryOne();

                if (!is_array($assign) || (string) $assign['organizationId'] !== $orgId || (string) $assign['activityId'] !== $aid) {
                    throw new HttpError(404, 'assignment_not_found');
                }
                if ((string) $assign['status'] !== 'ACTIVE' || $assign['revokedAt'] !== null) {
                    throw new HttpError(409, 'assignment_unavailable');
                }
                if (!$policy->isAssignmentRecipient($identity, $orgId, $assign)) {
                    throw new HttpError(403, 'assignment_forbidden');
                }
                if ($assign['opensAt'] !== null && strtotime((string) $assign['opensAt']) > time()) {
                    throw new HttpError(409, 'assignment_not_open_yet');
                }
                if ($assign['dueAt'] !== null && empty($assign['allowLate']) && strtotime((string) $assign['dueAt']) < time()) {
                    throw new HttpError(409, 'assignment_deadline_passed');
                }
                // Controlled assignment MUST use its pinned version_id, NEVER activeVersionId
                $versionId = (string) $assign['versionId'];
                $assignOverrideCfg = ActivityPayload::decodeObject((string) ($assign['configOverrideJson'] ?? '{}'));
            } else {
                if (!$policy->canStartOpenPractice($identity, $orgId, $activity)) {
                    throw new HttpError(403, 'assignment_required');
                }
                $versionId = (string) ($activity['activeVersionId'] ?? '');
                if ($versionId === '') {
                    throw new HttpError(409, 'activity_has_no_version');
                }
            }

            $versionRow = $this->db->createCommand(
                'SELECT id::text, config::text AS "configJson", content::text AS "contentJson",
                        time_limit_seconds AS "timeLimitSeconds", max_attempts AS "maxAttempts",
                        passing_score_percent AS "passingScorePercent"
                 FROM activity.versions
                 WHERE id = CAST(:vid AS uuid) AND activity_id = CAST(:aid AS uuid)',
                [':vid' => $versionId, ':aid' => $aid],
            )->queryOne();

            if (!is_array($versionRow)) {
                throw new HttpError(409, 'activity_has_no_version');
            }

            if ($resumeActive) {
                $activeSql = "SELECT id::text FROM activity.attempts
                    WHERE organization_id = CAST(:org AS uuid)
                      AND participant_person_id = CAST(:person AS uuid)
                      AND activity_id = CAST(:aid AS uuid)
                      AND state = 'IN_PROGRESS'
                      AND (expires_at IS NULL OR expires_at >= now())";
                $activeParams = [':org' => $orgId, ':person' => $personId, ':aid' => $aid];
                if ($assignmentId !== null) {
                    $activeSql .= ' AND assignment_id = CAST(:asgn AS uuid)';
                    $activeParams[':asgn'] = $assignmentId;
                } else {
                    $activeSql .= ' AND assignment_id IS NULL';
                }
                $activeSql .= ' ORDER BY created_at DESC LIMIT 1';
                $runningId = $this->db->createCommand($activeSql, $activeParams)->queryScalar();
                if (is_string($runningId)) {
                    return ['id' => $runningId, 'isNew' => false];
                }
            }

            // Enforce attempt limit atomically inside lock
            $maxAttempts = (int) ($versionRow['maxAttempts'] ?? 0);
            if ($maxAttempts > 0) {
                $cntSql = "SELECT count(*) FROM activity.attempts
                    WHERE organization_id = CAST(:org AS uuid)
                      AND participant_person_id = CAST(:person AS uuid)
                      AND activity_id = CAST(:aid AS uuid)
                      AND state NOT IN ('CANCELLED', 'INVALIDATED')";
                $cntParams = [':org' => $orgId, ':person' => $personId, ':aid' => $aid];
                if ($assignmentId !== null) {
                    $cntSql .= ' AND assignment_id = CAST(:asgn AS uuid)';
                    $cntParams[':asgn'] = $assignmentId;
                } else {
                    $cntSql .= ' AND assignment_id IS NULL';
                }
                $cnt = (int) $this->db->createCommand($cntSql, $cntParams)->queryScalar();
                if ($cnt >= $maxAttempts) {
                    throw new HttpError(409, 'max_attempts_reached');
                }
            }

            $cfg = ActivityPayload::decodeObject((string) ($versionRow['configJson'] ?? '{}'));
            $cnt = ActivityPayload::decodeObject((string) ($versionRow['contentJson'] ?? '{}'));

            if ($assignmentId !== null) {
                // Controlled exam: apply ONLY server-stored config_override filtered to safe assignment-overridable keys
                $safeOverride = $this->filterAssignmentOverrideConfig($plugin, $assignOverrideCfg);
                $mergedCfg = array_merge($cfg, $safeOverride);
            } else {
                // Free practice: allow ONLY whitelisted safe participant preference keys declared by plugin
                $clientCfg = ActivityPayload::assoc($input['config'] ?? null);
                $safeClientCfg = $this->filterParticipantConfig($plugin, $clientCfg);
                $mergedCfg = array_merge($cfg, $safeClientCfg);
            }

            $mergedCfg['passingScorePercent'] = (int) ($versionRow['passingScorePercent'] ?? $mergedCfg['passingScorePercent'] ?? 60);

            $seed = random_int(1, 2_000_000_000);
            $exercise = $plugin->generateExercise($mergedCfg, $seed, $cnt);
            if ($assignmentId !== null) {
                $timeLimit = $mergedCfg['timeLimitSeconds'] ?? $versionRow['timeLimitSeconds'] ?? null;
            } elseif ($pluginCode === 'platform-typing') {
                $submode = (string) ($mergedCfg['submode'] ?? 'time');
                $timeLimit = $submode === 'time'
                    ? ($mergedCfg['timeLimitSeconds'] ?? $versionRow['timeLimitSeconds'] ?? 60)
                    : null;
            } else {
                $timeLimit = $versionRow['timeLimitSeconds'] ?? $mergedCfg['timeLimitSeconds'] ?? null;
            }
            $expiresAt = is_numeric($timeLimit) && (int) $timeLimit > 0
                ? gmdate(DATE_ATOM, time() + (int) $timeLimit) : null;

            $attemptId = (string) $this->db->createCommand(
                "INSERT INTO activity.attempts (
                    organization_id, activity_id, version_id, assignment_id,
                    participant_person_id, participant_account_id,
                    state, revision, seed, idempotency_key, runtime_config, draft_state,
                    started_at, expires_at
                 ) VALUES (
                    CAST(:org AS uuid), CAST(:aid AS uuid), CAST(:vid AS uuid), CAST(:asgn AS uuid),
                    CAST(:person AS uuid), CAST(:account AS uuid),
                    'IN_PROGRESS', 1, :seed, :ik, CAST(:cfg AS jsonb), CAST(:ex AS jsonb),
                    now(), CAST(:exp AS timestamptz)
                 ) RETURNING id::text",
                [
                    ':org' => $orgId,
                    ':aid' => $aid,
                    ':vid' => $versionId,
                    ':asgn' => $assignmentId,
                    ':person' => $personId,
                    ':account' => $identity->accountId,
                    ':seed' => $seed,
                    ':ik' => $idempotencyKey,
                    ':cfg' => json_encode($mergedCfg, JSON_THROW_ON_ERROR),
                    ':ex' => $exercise === [] ? '{}' : json_encode($exercise, JSON_THROW_ON_ERROR),
                    ':exp' => $expiresAt,
                ],
            )->queryScalar();

            $this->recordTransition($attemptId, 'CREATED', 'IN_PROGRESS', $personId, 'attempt_started');
            $this->realtime->publish(
                organizationId: $orgId,
                channel: 'user:' . $personId,
                eventType: 'activity.attempt.started',
                payload: ['attemptId' => $attemptId, 'activityId' => $aid, 'pluginCode' => $pluginCode],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                targetPersonIds: [$personId],
                aggregateType: 'activity_attempt',
                aggregateId: $attemptId,
            );

            return ['id' => $attemptId, 'isNew' => true];
        });

        if ($txResult['isNew']) {
            $this->realtime->relayPending(50);
        }

        return $this->getAttempt($identity, $orgId, $txResult['id']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{saved: bool, revision: int}
     */
    public function saveDraft(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');

        $rawRev = $input['revision'] ?? null;
        if (!is_numeric($rawRev) || (int) $rawRev < 1) {
            throw new HttpError(422, 'revision_required');
        }
        $expectedRev = (int) $rawRev;
        $rawAnswers = $input['answers'] ?? null;
        $rawTelemetry = $input['telemetry'] ?? null;
        $answers = is_array($rawAnswers) ? $rawAnswers : [];
        $telemetry = is_array($rawTelemetry) ? $rawTelemetry : [];

        /** @var array{expired: bool, revision: int, remainingSeconds: int|null} $draftTx */
        $draftTx = $this->db->transaction(function () use ($orgId, $attId, $personId, $expectedRev, $answers, $telemetry): array {
            $row = $this->db->createCommand(
                'SELECT a.state, a.revision, a.expires_at,
                        a.runtime_config::text AS "cfgJson", a.draft_state::text AS "exJson",
                        a.answers::text AS "ansJson", a.telemetry::text AS "telJson",
                        i.plugin_code AS "pluginCode", v.passing_score_percent AS "passingScorePercent"
                 FROM activity.attempts a
                 LEFT JOIN activity.items i ON i.id = a.activity_id
                 LEFT JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid) AND a.participant_person_id = CAST(:person AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId, ':person' => $personId],
            )->queryOne();

            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            if ((string) $row['state'] !== 'IN_PROGRESS') {
                throw new HttpError(409, 'attempt_not_editable');
            }
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
                $this->finalizeExpiredAttemptRow($attId, $personId, $row);
                return ['expired' => true, 'revision' => 0, 'remainingSeconds' => 0];
            }
            $currentRev = (int) $row['revision'];
            if ($expectedRev !== $currentRev) {
                throw new HttpError(409, 'revision_conflict');
            }
            $newRev = $currentRev + 1;
            $this->db->createCommand(
                'UPDATE activity.attempts SET answers = CAST(:ans AS jsonb), telemetry = CAST(:tel AS jsonb), revision = :rev, updated_at = now()
                 WHERE id = CAST(:id AS uuid)',
                [
                    ':ans' => $answers === [] ? '{}' : json_encode($answers, JSON_THROW_ON_ERROR),
                    ':tel' => $telemetry === [] ? '{}' : json_encode($telemetry, JSON_THROW_ON_ERROR),
                    ':rev' => $newRev,
                    ':id' => $attId,
                ],
            )->execute();
            $remaining = $row['expires_at'] !== null ? max(0, (int) strtotime((string) $row['expires_at']) - time()) : null;
            return ['expired' => false, 'revision' => $newRev, 'remainingSeconds' => $remaining];
        });

        if ($draftTx['expired']) {
            throw new HttpError(409, 'attempt_expired');
        }

        return [
            'saved' => true,
            'revision' => $draftTx['revision'],
            'serverNow' => gmdate(DATE_ATOM, time()),
            'remainingSeconds' => $draftTx['remainingSeconds'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submitAttempt(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');

        $rawRev = $input['revision'] ?? null;
        if (!is_numeric($rawRev) || (int) $rawRev < 1) {
            throw new HttpError(422, 'revision_required');
        }
        $expectedRev = (int) $rawRev;
        $rawSubmission = $input['submission'] ?? null;
        $rawAnswers = $input['answers'] ?? null;
        $rawTelemetry = $input['telemetry'] ?? null;

        $rawIdempotency = $input['idempotencyKey'] ?? null;
        $hasIdempotency = is_string($rawIdempotency) && trim($rawIdempotency) !== '';

        /** @var array{outcome: string, state: string} $tx */
        $tx = $this->db->transaction(function () use ($identity, $orgId, $attId, $personId, $expectedRev, $input, $rawSubmission, $rawAnswers, $rawTelemetry, $hasIdempotency): array {
            $row = $this->db->createCommand(
                'SELECT a.state, a.revision, a.expires_at, a.activity_id::text AS "activityId",
                        a.runtime_config::text AS "cfgJson", a.draft_state::text AS "exJson",
                        a.answers::text AS "ansJson", a.telemetry::text AS "telJson",
                        i.plugin_code AS "pluginCode", i.author_person_id::text AS "authorPersonId", i.title,
                        v.passing_score_percent AS "passingScorePercent",
                        v.auto_publish_results AS "autoPublishResults"
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid) AND a.participant_person_id = CAST(:person AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId, ':person' => $personId],
            )->queryOne();

            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            if ((string) $row['state'] !== 'IN_PROGRESS') {
                if ($hasIdempotency) {
                    return ['outcome' => 'already_finalized', 'state' => (string) $row['state']];
                }
                throw new HttpError(409, 'attempt_already_finalized');
            }
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
                $this->finalizeExpiredAttemptRow($attId, $personId, $row);
                return ['outcome' => 'expired', 'state' => 'EXPIRED'];
            }
            $currentRev = (int) $row['revision'];
            if ($expectedRev !== $currentRev) {
                throw new HttpError(409, 'revision_conflict');
            }

            $cfg = ActivityPayload::decodeObject((string) ($row['cfgJson'] ?? '{}'));
            $cfg['passingScorePercent'] = (int) ($row['passingScorePercent'] ?? $cfg['passingScorePercent'] ?? 60);
            $exercise = ActivityPayload::decodeObject((string) ($row['exJson'] ?? '{}'));
            $savedAnswers = ActivityPayload::decodeObject((string) ($row['ansJson'] ?? '{}'));
            $submission = is_array($rawSubmission)
                ? ActivityPayload::assoc($rawSubmission)
                : (is_array($rawAnswers) ? ['answers' => $rawAnswers] : $input);
            if (!isset($submission['answers']) && $savedAnswers !== []) {
                $submission['answers'] = $savedAnswers;
            }
            $telemetry = array_merge(
                ActivityPayload::decodeObject((string) ($row['telJson'] ?? '{}')),
                ActivityPayload::assoc($rawTelemetry),
            );

            $plugin = $this->plugins->get((string) $row['pluginCode']);
            $eval = $plugin->evaluate($cfg, $exercise, $submission, $telemetry);

            $nextState = $eval['requiresManualReview'] ? 'PENDING_REVIEW' : 'EVALUATED';
            $autoPublish = !$eval['requiresManualReview'] && ((bool) ($row['autoPublishResults'] ?? true));

            $this->db->createCommand(
                'UPDATE activity.attempts SET
                    state = :state,
                    revision = revision + 1,
                    answers = CAST(:ans AS jsonb),
                    telemetry = CAST(:tel AS jsonb),
                    evaluation = CAST(:ev AS jsonb),
                    raw_score = :raw,
                    max_score = :max,
                    percentage = :pct,
                    passed = :passed,
                    submitted_at = now(),
                    evaluated_at = CASE WHEN :isEvaluated THEN now() ELSE NULL END,
                    results_published_at = CASE WHEN :autoPublish THEN now() ELSE results_published_at END,
                    updated_at = now()
                 WHERE id = CAST(:id AS uuid)',
                [
                    ':state' => $nextState,
                    ':ans' => $submission === [] ? '{}' : json_encode($submission, JSON_THROW_ON_ERROR),
                    ':tel' => $telemetry === [] ? '{}' : json_encode($telemetry, JSON_THROW_ON_ERROR),
                    ':ev' => json_encode($eval, JSON_THROW_ON_ERROR),
                    ':raw' => $eval['rawScore'],
                    ':max' => $eval['maxScore'],
                    ':pct' => $eval['percentage'],
                    ':passed' => $eval['passed'],
                    ':isEvaluated' => !$eval['requiresManualReview'],
                    ':autoPublish' => $autoPublish,
                    ':id' => $attId,
                ],
            )->execute();

            $this->recordTransition($attId, 'IN_PROGRESS', $nextState, $personId, 'attempt_submitted', [
                'rawScore' => $eval['rawScore'],
                'maxScore' => $eval['maxScore'],
                'percentage' => $eval['percentage'],
                'passed' => $eval['passed'],
                'autoPublished' => $autoPublish,
            ]);

            $this->realtime->publish(
                organizationId: $orgId,
                channel: 'user:' . $personId,
                eventType: 'activity.attempt.' . strtolower($nextState),
                payload: ['attemptId' => $attId, 'activityId' => (string) $row['activityId'], 'state' => $nextState, 'percentage' => $eval['percentage']],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                targetPersonIds: [$personId],
                aggregateType: 'activity_attempt',
                aggregateId: $attId,
            );

            $authorId = (string) ($row['authorPersonId'] ?? '');
            if ($authorId !== '' && $authorId !== $personId) {
                $this->notifications->notify(
                    organizationId: $orgId,
                    personId: $authorId,
                    category: $row['pluginCode'] === 'platform-assessment' ? 'assessment' : 'activity',
                    kind: 'activity_submitted',
                    title: (string) $row['title'],
                    body: $eval['feedback'],
                    resourceType: 'activity_attempt',
                    resourceId: $attId,
                    resourcePath: '/activities/attempts/' . $attId,
                );
            }

            return ['outcome' => 'submitted', 'state' => $nextState];
        });

        if ($tx['outcome'] === 'expired') {
            throw new HttpError(409, 'attempt_expired');
        }

        if ($tx['outcome'] === 'submitted') {
            $this->realtime->relayPending(50);
        }
        $attempt = $this->getAttempt($identity, $orgId, $attId);
        if ($tx['outcome'] === 'submitted' && $tx['state'] === 'EVALUATED' && $this->resultListener !== null) {
            $this->resultListener->onAttemptEvaluated($orgId, $attId, $attempt);
        }
        return $attempt;
    }

    /** @param array<string, mixed> $input */
    public function reviewAttempt(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, array $input): array
    {
        $reviewerId = $this->assertAccess($identity, $organizationId, 'activity.review');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');

        $hasRawScore = array_key_exists('rawScore', $input) && is_numeric($input['rawScore']);
        $rawQuestionReviews = is_array($input['questionReviews'] ?? null) ? $input['questionReviews'] : [];
        if (!$hasRawScore && $rawQuestionReviews === []) {
            throw new HttpError(422, 'raw_score_required');
        }
        $explicitRawScore = $hasRawScore ? (float) $input['rawScore'] : null;
        $notes = trim((string) ($input['reviewNotes'] ?? ''));
        $publish = array_key_exists('publish', $input) ? (bool) $input['publish'] : true;

        /** @var array<string, array{pointsEarned?: float, comment?: string, correct?: bool}> $qReviewMap */
        $qReviewMap = [];
        foreach ($rawQuestionReviews as $key => $qr) {
            if (!is_array($qr)) {
                continue;
            }
            $qid = is_string($qr['questionId'] ?? null) && $qr['questionId'] !== ''
                ? $qr['questionId']
                : (is_string($key) ? $key : '');
            if ($qid === '') {
                continue;
            }
            $entry = [];
            if (array_key_exists('pointsEarned', $qr) && is_numeric($qr['pointsEarned'])) {
                $entry['pointsEarned'] = (float) $qr['pointsEarned'];
            }
            if (array_key_exists('comment', $qr) && is_string($qr['comment'])) {
                $entry['comment'] = trim($qr['comment']);
            }
            if (array_key_exists('correct', $qr) && is_bool($qr['correct'])) {
                $entry['correct'] = $qr['correct'];
            }
            $qReviewMap[$qid] = $entry;
        }

        $policy = $this->policy();

        $this->db->transaction(function () use ($identity, $orgId, $attId, $reviewerId, $explicitRawScore, $qReviewMap, $notes, $publish, $policy): void {
            $row = $this->db->createCommand(
                'SELECT a.state, a.participant_person_id::text AS "participantId", a.assignment_id::text AS "assignmentId",
                        a.raw_score AS "prevScore", a.max_score AS "maxScore", a.percentage AS "prevPct", a.passed AS "prevPassed",
                        a.evaluation::text AS "evJson",
                        i.title, i.author_person_id::text AS "authorPersonId",
                        v.passing_score_percent AS "passingScorePercent"
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId],
            )->queryOne();
            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            $participantId = (string) $row['participantId'];
            $assignmentId = is_string($row['assignmentId']) && $row['assignmentId'] !== '' ? $row['assignmentId'] : null;
            $authorPersonId = is_string($row['authorPersonId']) && $row['authorPersonId'] !== '' ? $row['authorPersonId'] : null;

            if (!$policy->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorPersonId)) {
                throw new HttpError(403, 'attempt_forbidden');
            }

            $prevState = (string) $row['state'];
            if (!in_array($prevState, ['SUBMITTED', 'PENDING_REVIEW', 'EVALUATED', 'COMPLETED'], true)) {
                throw new HttpError(409, 'attempt_not_reviewable');
            }

            $eval = ActivityPayload::decodeObject((string) ($row['evJson'] ?? '{}'));
            $max = max(1.0, (float) ($row['maxScore'] ?? $eval['maxScore'] ?? 100));

            $questionOverrides = [];
            if ($qReviewMap !== [] && isset($eval['breakdown']) && is_array($eval['breakdown'])) {
                $sumEarned = 0.0;
                $sumMax = 0.0;
                $updatedBreakdown = [];
                foreach ($eval['breakdown'] as $b) {
                    if (!is_array($b)) {
                        continue;
                    }
                    $qid = (string) ($b['questionId'] ?? '');
                    $qMax = max(0.0, (float) ($b['maxPoints'] ?? 1.0));
                    $sumMax += $qMax;
                    if (isset($qReviewMap[$qid])) {
                        $override = $qReviewMap[$qid];
                        $pts = $override['pointsEarned'] ?? (float) ($b['pointsEarned'] ?? 0.0);
                        if ($pts < 0.0 || $pts > $qMax) {
                            throw new HttpError(422, 'invalid_score_bounds');
                        }
                        $pts = round($pts, 2);
                        $b['pointsEarned'] = $pts;
                        $b['pendingReview'] = false;
                        $b['manuallyReviewed'] = true;
                        $b['correct'] = $override['correct'] ?? ($qMax > 0 ? $pts >= ($qMax - 0.001) : true);
                        $b['partial'] = $pts > 0.0 && $qMax > 0 && $pts < ($qMax - 0.001);
                        if (array_key_exists('comment', $override)) {
                            $b['comment'] = $override['comment'] !== '' ? $override['comment'] : null;
                        }
                        $questionOverrides[$qid] = [
                            'pointsEarned' => $pts,
                            'comment' => $b['comment'] ?? null,
                        ];
                    }
                    $sumEarned += (float) ($b['pointsEarned'] ?? 0.0);
                    $updatedBreakdown[] = $b;
                }
                $eval['breakdown'] = $updatedBreakdown;
                if ($sumMax > 0.0) {
                    $max = $sumMax;
                }
                $rawScore = $explicitRawScore ?? $sumEarned;
            } else {
                $rawScore = $explicitRawScore ?? (float) ($row['prevScore'] ?? 0.0);
            }

            if ($rawScore < 0.0 || $rawScore > $max) {
                throw new HttpError(422, 'invalid_score_bounds');
            }

            $score = round($rawScore, 2);
            $pct = round(($score / $max) * 100.0, 2);
            $passingScore = (float) ($row['passingScorePercent'] ?? 60);
            $passed = $pct >= $passingScore;

            $eval['rawScore'] = $score;
            $eval['maxScore'] = round($max, 2);
            $eval['percentage'] = $pct;
            $eval['passed'] = $passed;
            $eval['requiresManualReview'] = false;
            $eval['feedback'] = $notes !== '' ? $notes : "Reviewed: {$score} / {$max} ({$pct}%).";

            $this->db->createCommand(
                "UPDATE activity.attempts SET
                    state = 'EVALUATED',
                    raw_score = :raw,
                    max_score = :max,
                    percentage = :pct,
                    passed = :passed,
                    evaluation = CAST(:ev AS jsonb),
                    reviewer_person_id = CAST(:rev AS uuid),
                    review_notes = :notes,
                    evaluated_at = now(),
                    results_published_at = CASE WHEN :publish THEN COALESCE(results_published_at, now()) ELSE results_published_at END,
                    results_published_by_person_id = CASE WHEN :publish THEN COALESCE(results_published_by_person_id, CAST(:rev AS uuid)) ELSE results_published_by_person_id END,
                    updated_at = now()
                 WHERE id = CAST(:id AS uuid)",
                [
                    ':raw' => $score,
                    ':max' => round($max, 2),
                    ':pct' => $pct,
                    ':passed' => $passed,
                    ':ev' => json_encode($eval, JSON_THROW_ON_ERROR),
                    ':rev' => $reviewerId,
                    ':notes' => $notes,
                    ':publish' => $publish,
                    ':id' => $attId,
                ],
            )->execute();

            $this->recordTransition($attId, $prevState, 'EVALUATED', $reviewerId, 'manual_review', [
                'previousScore' => $row['prevScore'] !== null ? (float) $row['prevScore'] : null,
                'newScore' => $score,
                'previousPercentage' => $row['prevPct'] !== null ? (float) $row['prevPct'] : null,
                'newPercentage' => $pct,
                'previousPassed' => $row['prevPassed'] !== null ? (bool) $row['prevPassed'] : null,
                'newPassed' => $passed,
                'questionOverrides' => $questionOverrides,
                'notes' => $notes,
                'published' => $publish,
            ]);

            $this->realtime->publish(
                organizationId: $orgId,
                channel: 'user:' . $participantId,
                eventType: 'activity.attempt.evaluated',
                payload: ['attemptId' => $attId, 'percentage' => $pct, 'passed' => $passed, 'published' => $publish],
                actorAccountId: $identity->accountId,
                actorPersonId: $reviewerId,
                targetPersonIds: [$participantId],
                aggregateType: 'activity_attempt',
                aggregateId: $attId,
            );
            $this->notifications->notify(
                organizationId: $orgId,
                personId: $participantId,
                category: 'activity',
                kind: 'activity_evaluated',
                title: (string) $row['title'],
                body: "Reviewed: {$score}/{$max} ({$pct}%)",
                resourceType: 'activity_attempt',
                resourceId: $attId,
                resourcePath: '/activities/attempts/' . $attId,
            );
        });

        $this->realtime->relayPending(50);
        $attempt = $this->getAttempt($identity, $orgId, $attId);
        if ($this->resultListener !== null) {
            $this->resultListener->onAttemptEvaluated($orgId, $attId, $attempt);
            if ($publish) {
                $this->resultListener->onAttemptResultsPublished($orgId, $attId, $attempt, true);
            }
        }
        return $attempt;
    }

    /**
     * Deterministically re-evaluates an attempt on the server, optionally preserving manual question overrides.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function regradeAttempt(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, array $input = []): array
    {
        $reviewerId = $this->assertAccess($identity, $organizationId, 'activity.review');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');
        $resetManual = ($input['resetManual'] ?? false) === true;

        $policy = $this->policy();
        $nextState = 'EVALUATED';

        $this->db->transaction(function () use ($identity, $orgId, $attId, $reviewerId, $resetManual, $policy, &$nextState): void {
            $row = $this->db->createCommand(
                'SELECT a.state, a.participant_person_id::text AS "participantId", a.assignment_id::text AS "assignmentId",
                        a.runtime_config::text AS "cfgJson", a.draft_state::text AS "exJson",
                        a.answers::text AS "ansJson", a.telemetry::text AS "telJson", a.evaluation::text AS "evJson",
                        a.raw_score AS "prevScore", a.percentage AS "prevPct", a.passed AS "prevPassed",
                        i.plugin_code AS "pluginCode", i.author_person_id::text AS "authorPersonId",
                        v.passing_score_percent AS "passingScorePercent"
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId],
            )->queryOne();

            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            $participantId = (string) $row['participantId'];
            $assignmentId = is_string($row['assignmentId']) && $row['assignmentId'] !== '' ? $row['assignmentId'] : null;
            $authorPersonId = is_string($row['authorPersonId']) && $row['authorPersonId'] !== '' ? $row['authorPersonId'] : null;

            if (!$policy->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorPersonId)) {
                throw new HttpError(403, 'attempt_forbidden');
            }

            $prevState = (string) $row['state'];
            if (!in_array($prevState, ['SUBMITTED', 'PENDING_REVIEW', 'EVALUATED', 'COMPLETED'], true)) {
                throw new HttpError(409, 'attempt_not_reviewable');
            }

            $cfg = ActivityPayload::decodeObject((string) ($row['cfgJson'] ?? '{}'));
            $cfg['passingScorePercent'] = (int) ($row['passingScorePercent'] ?? $cfg['passingScorePercent'] ?? 60);
            $exercise = ActivityPayload::decodeObject((string) ($row['exJson'] ?? '{}'));
            $submission = ActivityPayload::decodeObject((string) ($row['ansJson'] ?? '{}'));
            $telemetry = ActivityPayload::decodeObject((string) ($row['telJson'] ?? '{}'));
            $prevEval = ActivityPayload::decodeObject((string) ($row['evJson'] ?? '{}'));

            $plugin = $this->plugins->get((string) $row['pluginCode']);
            $eval = $plugin->evaluate($cfg, $exercise, $submission, $telemetry);

            if (!$resetManual && isset($prevEval['breakdown'], $eval['breakdown']) && is_array($prevEval['breakdown']) && is_array($eval['breakdown'])) {
                $manualByQid = [];
                foreach ($prevEval['breakdown'] as $pb) {
                    if (is_array($pb) && !empty($pb['manuallyReviewed']) && isset($pb['questionId'])) {
                        $manualByQid[(string) $pb['questionId']] = $pb;
                    }
                }
                if ($manualByQid !== []) {
                    $rawSum = 0.0;
                    $maxSum = 0.0;
                    $stillPending = false;
                    $merged = [];
                    foreach ($eval['breakdown'] as $nb) {
                        if (!is_array($nb)) {
                            continue;
                        }
                        $qid = (string) ($nb['questionId'] ?? '');
                        if (isset($manualByQid[$qid])) {
                            $nb['pointsEarned'] = (float) ($manualByQid[$qid]['pointsEarned'] ?? 0.0);
                            $nb['pendingReview'] = false;
                            $nb['manuallyReviewed'] = true;
                            $nb['correct'] = $manualByQid[$qid]['correct'] ?? null;
                            $nb['comment'] = $manualByQid[$qid]['comment'] ?? null;
                        }
                        if (!empty($nb['pendingReview'])) {
                            $stillPending = true;
                        }
                        $rawSum += (float) ($nb['pointsEarned'] ?? 0.0);
                        $maxSum += (float) ($nb['maxPoints'] ?? 0.0);
                        $merged[] = $nb;
                    }
                    $eval['breakdown'] = $merged;
                    $eval['rawScore'] = round($rawSum, 2);
                    $eval['maxScore'] = round($maxSum, 2);
                    $eval['percentage'] = $maxSum > 0 ? round(($rawSum / $maxSum) * 100.0, 2) : 0.0;
                    $eval['requiresManualReview'] = $stillPending;
                    $eval['passed'] = !$stillPending && $eval['percentage'] >= (float) $cfg['passingScorePercent'];
                }
            }

            $nextState = $eval['requiresManualReview'] ? 'PENDING_REVIEW' : 'EVALUATED';

            $this->db->createCommand(
                'UPDATE activity.attempts SET
                    state = :state,
                    raw_score = :raw,
                    max_score = :max,
                    percentage = :pct,
                    passed = :passed,
                    evaluation = CAST(:ev AS jsonb),
                    evaluated_at = CASE WHEN :isEvaluated THEN now() ELSE evaluated_at END,
                    updated_at = now()
                 WHERE id = CAST(:id AS uuid)',
                [
                    ':state' => $nextState,
                    ':raw' => $eval['rawScore'],
                    ':max' => $eval['maxScore'],
                    ':pct' => $eval['percentage'],
                    ':passed' => $eval['passed'],
                    ':ev' => json_encode($eval, JSON_THROW_ON_ERROR),
                    ':isEvaluated' => !$eval['requiresManualReview'],
                    ':id' => $attId,
                ],
            )->execute();

            $this->recordTransition($attId, $prevState, $nextState, $reviewerId, 'regrade', [
                'previousScore' => $row['prevScore'] !== null ? (float) $row['prevScore'] : null,
                'newScore' => $eval['rawScore'],
                'previousPercentage' => $row['prevPct'] !== null ? (float) $row['prevPct'] : null,
                'newPercentage' => $eval['percentage'],
                'previousPassed' => $row['prevPassed'] !== null ? (bool) $row['prevPassed'] : null,
                'newPassed' => $eval['passed'],
                'resetManual' => $resetManual,
            ]);
        });

        $attempt = $this->getAttempt($identity, $orgId, $attId);
        if ($nextState === 'EVALUATED' && $this->resultListener !== null) {
            $this->resultListener->onAttemptEvaluated($orgId, $attId, $attempt);
        }
        return $attempt;
    }

    /**
     * Publishes or unpublishes evaluated attempt results to the participant and guardians.
     *
     * @return array<string, mixed>
     */
    public function publishAttemptResults(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, bool $publish = true): array
    {
        $actorId = $this->assertAccess($identity, $organizationId, 'activity.review');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');
        $policy = $this->policy();

        $this->db->transaction(function () use ($identity, $orgId, $attId, $actorId, $publish, $policy): void {
            $row = $this->db->createCommand(
                'SELECT a.state, a.participant_person_id::text AS "participantId", a.assignment_id::text AS "assignmentId",
                        a.raw_score AS "rawScore", a.percentage, a.passed,
                        i.author_person_id::text AS "authorPersonId", i.title
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId],
            )->queryOne();

            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            $participantId = (string) $row['participantId'];
            $assignmentId = is_string($row['assignmentId']) && $row['assignmentId'] !== '' ? $row['assignmentId'] : null;
            $authorPersonId = is_string($row['authorPersonId']) && $row['authorPersonId'] !== '' ? $row['authorPersonId'] : null;

            if (!$policy->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorPersonId)) {
                throw new HttpError(403, 'attempt_forbidden');
            }
            $state = (string) $row['state'];
            if (!in_array($state, ['EVALUATED', 'COMPLETED'], true)) {
                throw new HttpError(409, 'attempt_not_evaluated');
            }

            $this->db->createCommand(
                'UPDATE activity.attempts SET
                    results_published_at = CASE WHEN :publish THEN now() ELSE NULL END,
                    results_published_by_person_id = CASE WHEN :publish THEN CAST(:actor AS uuid) ELSE NULL END,
                    updated_at = now()
                 WHERE id = CAST(:id AS uuid)',
                [':publish' => $publish, ':actor' => $actorId, ':id' => $attId],
            )->execute();

            $this->recordTransition(
                $attId,
                $state,
                $state,
                $actorId,
                $publish ? 'results_published' : 'results_unpublished',
                ['published' => $publish, 'percentage' => $row['percentage'] !== null ? (float) $row['percentage'] : null],
            );

            if ($publish) {
                $this->realtime->publish(
                    organizationId: $orgId,
                    channel: 'user:' . $participantId,
                    eventType: 'activity.attempt.results_published',
                    payload: ['attemptId' => $attId, 'percentage' => $row['percentage'], 'passed' => $row['passed']],
                    actorAccountId: $identity->accountId,
                    actorPersonId: $actorId,
                    targetPersonIds: [$participantId],
                    aggregateType: 'activity_attempt',
                    aggregateId: $attId,
                );
            }
        });

        $this->realtime->relayPending(50);
        $attempt = $this->getAttempt($identity, $orgId, $attId);
        if ($this->resultListener !== null) {
            $this->resultListener->onAttemptResultsPublished($orgId, $attId, $attempt, $publish);
        }
        return $attempt;
    }

    /**
     * Invalidates (revokes) an attempt from teacher/reviewer context.
     *
     * @return array<string, mixed>
     */
    public function invalidateAttempt(AuthenticatedIdentity $identity, string $organizationId, string $attemptId, string $reason = ''): array
    {
        $actorPersonId = $this->assertAccess($identity, $organizationId, 'activity.review');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');
        $policy = $this->policy();

        $this->db->transaction(function () use ($identity, $orgId, $attId, $actorPersonId, $reason, $policy): void {
            $row = $this->db->createCommand(
                'SELECT a.state, a.participant_person_id::text AS "participantId", a.assignment_id::text AS "assignmentId",
                        i.author_person_id::text AS "authorPersonId"
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                 FOR UPDATE OF a',
                [':id' => $attId, ':org' => $orgId],
            )->queryOne();
            if (!is_array($row)) {
                throw new HttpError(404, 'attempt_not_found');
            }
            $participantId = (string) $row['participantId'];
            $assignmentId = is_string($row['assignmentId']) && $row['assignmentId'] !== '' ? $row['assignmentId'] : null;
            $authorPersonId = is_string($row['authorPersonId']) && $row['authorPersonId'] !== '' ? $row['authorPersonId'] : null;

            if (!$policy->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorPersonId)) {
                throw new HttpError(403, 'attempt_forbidden');
            }

            $prevState = (string) $row['state'];
            if (!in_array($prevState, ['CANCELLED', 'INVALIDATED'], true)) {
                $this->db->createCommand(
                    "UPDATE activity.attempts
                     SET state = 'INVALIDATED',
                         review_notes = CASE WHEN :notes <> '' THEN :notes ELSE review_notes END,
                         updated_at = now()
                     WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)",
                    [':notes' => trim($reason), ':id' => $attId, ':org' => $orgId],
                )->execute();

                $this->recordTransition(
                    $attId,
                    $prevState,
                    'INVALIDATED',
                    $actorPersonId,
                    'attempt_invalidated',
                    ['reason' => trim($reason)],
                );
            }
        });

        return $this->getAttempt($identity, $orgId, $attId);
    }

    /**
     * Lists attempts for an activity (for teachers/authors/reviewers), including manual review queue filtering.
     *
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>}
     */
    public function listActivityAttempts(AuthenticatedIdentity $identity, string $organizationId, string $activityId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.review');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');

        $activity = $this->loadActivityRow($orgId, $aid);
        if (!is_array($activity)) {
            throw new HttpError(404, 'activity_not_found');
        }
        $activity['config'] = ActivityPayload::decodeObject((string) ($activity['configJson'] ?? '{}'));
        $isAuthorOrAdmin = ((string) ($activity['authorPersonId'] ?? '') === $personId) || $this->isOrgAdmin($identity->accountId);
        if (!$isAuthorOrAdmin && !$this->policy()->canAccessActivity($identity, $orgId, $activity)) {
            throw new HttpError(403, 'activity_forbidden');
        }

        $where = 'a.organization_id = CAST(:org AS uuid) AND a.activity_id = CAST(:aid AS uuid)';
        $params = [':org' => $orgId, ':aid' => $aid];
        $rawState = $query['state'] ?? null;
        if (is_string($rawState) && $rawState !== '') {
            $where .= ' AND a.state = :state';
            $params[':state'] = $rawState;
        }

        $rows = $this->db->createCommand(
            "SELECT
                a.id::text, a.activity_id::text AS \"activityId\", a.version_id::text AS \"versionId\",
                v.version_number AS \"versionNumber\",
                a.assignment_id::text AS \"assignmentId\",
                a.participant_person_id::text AS \"participantPersonId\",
                COALESCE(NULLIF(p.display_name, ''), NULLIF(trim(COALESCE(p.given_name, '') || ' ' || COALESCE(p.family_name, '')), ''), 'Participant') AS \"participantName\",
                a.state, a.raw_score AS \"rawScore\", a.max_score AS \"maxScore\",
                a.percentage, a.passed,
                a.started_at::text AS \"startedAt\", a.submitted_at::text AS \"submittedAt\",
                a.evaluated_at::text AS \"evaluatedAt\", a.results_published_at::text AS \"resultsPublishedAt\",
                a.review_notes AS \"reviewNotes\",
                COALESCE((a.evaluation->>'requiresManualReview')::boolean, false) AS \"requiresManualReview\"
             FROM activity.attempts a
             JOIN activity.versions v ON v.id = a.version_id
             LEFT JOIN core.persons p ON p.id = a.participant_person_id
             WHERE {$where}
             ORDER BY COALESCE(a.submitted_at, a.started_at, a.created_at) DESC, a.id DESC
             LIMIT 200",
            $params,
        )->queryAll();

        $policy = $this->policy();
        $authorId = is_string($activity['authorPersonId'] ?? null) ? $activity['authorPersonId'] : null;
        $visible = [];
        foreach ($rows as $r) {
            $participantId = (string) $r['participantPersonId'];
            $assignmentId = is_string($r['assignmentId']) && $r['assignmentId'] !== '' ? $r['assignmentId'] : null;
            if (!$isAuthorOrAdmin && !$policy->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorId)) {
                continue;
            }
            $r['rawScore'] = $r['rawScore'] !== null ? (float) $r['rawScore'] : null;
            $r['maxScore'] = $r['maxScore'] !== null ? (float) $r['maxScore'] : null;
            $r['percentage'] = $r['percentage'] !== null ? (float) $r['percentage'] : null;
            $r['passed'] = $r['passed'] !== null ? (bool) $r['passed'] : null;
            $r['requiresManualReview'] = (bool) ($r['requiresManualReview'] ?? false);
            $visible[] = ActivityPayload::assoc($r);
        }

        return ['items' => $visible];
    }

    /**
     * Assigns a published Universal Activity to a group, student, person, homework, lesson, chat room or organization.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function assignActivity(AuthenticatedIdentity $identity, string $organizationId, string $activityId, array $input): array
    {
        $assignerId = $this->assertAccess($identity, $organizationId, 'activity.assign');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $aid = RealtimeService::assertUuid($activityId, 'activityId');
        $activity = $this->getActivity($identity, $orgId, $aid);
        if (($activity['status'] ?? '') !== 'PUBLISHED') {
            throw new HttpError(409, 'activity_not_published');
        }
        $rawVid = $activity['activeVersionId'] ?? '';
        $versionId = is_string($rawVid) ? $rawVid : '';
        if ($versionId === '') {
            throw new HttpError(409, 'activity_has_no_version');
        }

        $rawType = $input['targetType'] ?? 'person';
        $targetType = is_string($rawType) ? $rawType : 'person';
        if (!in_array($targetType, ['group', 'person', 'student', 'lesson', 'homework', 'room', 'organization'], true)) {
            throw new HttpError(422, 'invalid_target_type');
        }
        $rawTarget = $input['targetId'] ?? null;
        $targetId = is_string($rawTarget) && $rawTarget !== ''
            ? RealtimeService::assertUuid($rawTarget, 'targetId')
            : $orgId;

        $policy = $this->policy();
        if (!$policy->canAssignTarget($identity, $orgId, $targetType, $targetId)) {
            throw new HttpError(403, 'assignment_target_forbidden');
        }

        $opensAt = is_string($input['opensAt'] ?? null) && $input['opensAt'] !== '' ? $input['opensAt'] : null;
        $dueAt = is_string($input['dueAt'] ?? null) && $input['dueAt'] !== '' ? $input['dueAt'] : null;
        $allowLate = ($input['allowLate'] ?? false) === true;

        $rawPlugin = $activity['pluginCode'] ?? null;
        $pluginCode = is_string($rawPlugin) && $rawPlugin !== '' ? $rawPlugin : 'platform-assessment';
        $plugin = $this->plugins->get($pluginCode);
        $overrideCfg = $this->filterAssignmentOverrideConfig($plugin, ActivityPayload::assoc($input['configOverride'] ?? null));

        $assignmentId = (string) $this->db->createCommand(
            "INSERT INTO activity.assignments (
                organization_id, activity_id, version_id, assigned_by_person_id,
                target_type, target_id, opens_at, due_at, allow_late, config_override, status
             ) VALUES (
                CAST(:org AS uuid), CAST(:aid AS uuid), CAST(:vid AS uuid), CAST(:by AS uuid),
                :ttype, CAST(:tid AS uuid), CAST(:opens AS timestamptz), CAST(:due AS timestamptz), :late, CAST(:cfg AS jsonb), 'ACTIVE'
             ) RETURNING id::text",
            [
                ':org' => $orgId,
                ':aid' => $aid,
                ':vid' => $versionId,
                ':by' => $assignerId,
                ':ttype' => $targetType,
                ':tid' => $targetId,
                ':opens' => $opensAt,
                ':due' => $dueAt,
                ':late' => $allowLate,
                ':cfg' => ActivityPayload::encodeObject($overrideCfg),
            ],
        )->queryScalar();

        $rawActTitle = $activity['title'] ?? null;
        $title = is_string($rawActTitle) ? $rawActTitle : 'Activity';
        $recipientPersonIds = $policy->resolveAssignmentRecipients($orgId, $targetType, $targetId, $assignerId);

        foreach ($recipientPersonIds as $pid) {
            $this->notifications->notify(
                organizationId: $orgId,
                personId: $pid,
                category: ($activity['pluginCode'] ?? '') === 'platform-assessment' ? 'assessment' : 'activity',
                kind: 'activity_assigned',
                title: $title,
                body: 'New activity assigned to you.',
                resourceType: 'activity_assignment',
                resourceId: $assignmentId,
                resourcePath: '/activities/' . $aid . '/run?assignmentId=' . $assignmentId,
            );
            $this->realtime->publish(
                organizationId: $orgId,
                channel: 'user:' . $pid,
                eventType: 'activity.assigned',
                payload: ['assignmentId' => $assignmentId, 'activityId' => $aid, 'title' => $title, 'dueAt' => $dueAt],
                actorAccountId: $identity->accountId,
                actorPersonId: $assignerId,
                targetPersonIds: [$pid],
                aggregateType: 'activity_assignment',
                aggregateId: $assignmentId,
            );
        }
        $this->realtime->relayPending(50);

        return [
            'id' => $assignmentId,
            'activityId' => $aid,
            'versionId' => $versionId,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'opensAt' => $opensAt,
            'dueAt' => $dueAt,
            'allowLate' => $allowLate,
            'notifiedCount' => count($recipientPersonIds),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAssignments(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rawTarget = $query['targetId'] ?? null;
        $targetFilter = is_string($rawTarget) && $rawTarget !== '' ? RealtimeService::assertUuid($rawTarget, 'targetId') : null;
        $rawActivity = $query['activityId'] ?? null;
        $activityFilter = is_string($rawActivity) && $rawActivity !== '' ? RealtimeService::assertUuid($rawActivity, 'activityId') : null;

        $where = 'a.organization_id = CAST(:org AS uuid) AND a.revoked_at IS NULL';
        $params = [':org' => $orgId, ':person' => $personId];
        if ($targetFilter !== null) {
            $where .= ' AND a.target_id = CAST(:tid AS uuid)';
            $params[':tid'] = $targetFilter;
        }
        if ($activityFilter !== null) {
            $where .= ' AND a.activity_id = CAST(:aid AS uuid)';
            $params[':aid'] = $activityFilter;
        }

        $rows = $this->db->createCommand(
            "SELECT
                a.id::text, a.activity_id::text AS \"activityId\", a.version_id::text AS \"versionId\",
                a.assigned_by_person_id::text AS \"assignedByPersonId\",
                a.target_type AS \"targetType\", a.target_id::text AS \"targetId\",
                a.opens_at::text AS \"opensAt\", a.due_at::text AS \"dueAt\",
                a.allow_late AS \"allowLate\", a.status, a.created_at::text AS \"createdAt\",
                i.title, i.plugin_code AS \"pluginCode\",
                v.version_number AS \"versionNumber\",
                v.time_limit_seconds AS \"timeLimitSeconds\",
                v.max_attempts AS \"maxAttempts\",
                v.passing_score_percent AS \"passingScorePercent\",
                (SELECT count(*)::int FROM activity.attempts att WHERE att.assignment_id = a.id AND att.participant_person_id = CAST(:person AS uuid) AND att.state NOT IN ('CANCELLED', 'INVALIDATED')) AS \"myAttemptsCount\",
                (SELECT att.id::text FROM activity.attempts att WHERE att.assignment_id = a.id AND att.participant_person_id = CAST(:person AS uuid) AND att.state = 'IN_PROGRESS' AND (att.expires_at IS NULL OR att.expires_at >= now()) ORDER BY att.created_at DESC LIMIT 1) AS \"activeAttemptId\",
                (SELECT MAX(att.percentage) FROM activity.attempts att WHERE att.assignment_id = a.id AND att.participant_person_id = CAST(:person AS uuid) AND att.state IN ('EVALUATED', 'COMPLETED', 'EXPIRED')) AS \"myBestPercentage\"
             FROM activity.assignments a
             JOIN activity.items i ON i.id = a.activity_id
             JOIN activity.versions v ON v.id = a.version_id
             WHERE {$where}
             ORDER BY a.created_at DESC
             LIMIT 300",
            $params,
        )->queryAll();

        $policy = $this->policy();
        $visible = [];
        foreach ($rows as $row) {
            $assocRow = ActivityPayload::assoc($row);
            if (!$policy->canViewAssignment($identity, $orgId, $assocRow)) {
                continue;
            }
            $visible[] = $assocRow;
            if (count($visible) >= 100) {
                break;
            }
        }

        return ['items' => $visible];
    }

    /** @return array<string, mixed> */
    public function getAttempt(AuthenticatedIdentity $identity, string $organizationId, string $attemptId): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $attId = RealtimeService::assertUuid($attemptId, 'attemptId');

        // Auto-expire overdue IN_PROGRESS attempt in a committed transaction before reading
        $this->expireAttemptIfOverdue($orgId, $attId);

        $row = $this->db->createCommand(
            'SELECT
                a.id::text, a.activity_id::text AS "activityId", a.version_id::text AS "versionId",
                a.assignment_id::text AS "assignmentId", a.participant_person_id::text AS "participantPersonId",
                a.state, a.revision, a.seed, a.runtime_config::text AS "cfgJson",
                a.draft_state::text AS "exJson", a.answers::text AS "ansJson",
                a.telemetry::text AS "telJson", a.evaluation::text AS "evJson",
                a.raw_score AS "rawScore", a.max_score AS "maxScore", a.percentage, a.passed,
                a.started_at::text AS "startedAt", a.expires_at::text AS "expiresAt",
                a.submitted_at::text AS "submittedAt", a.evaluated_at::text AS "evaluatedAt",
                a.results_published_at::text AS "resultsPublishedAt",
                a.review_notes AS "reviewNotes",
                i.plugin_code AS "pluginCode", i.title AS "activityTitle",
                i.author_person_id::text AS "authorPersonId",
                v.passing_score_percent AS "passingScorePercent",
                v.auto_publish_results AS "autoPublishResults",
                v.reveal_answers_policy AS "versionRevealPolicy",
                v.config::text AS "versionConfigJson",
                asg.due_at::text AS "assignmentDueAt"
             FROM activity.attempts a
             JOIN activity.items i ON i.id = a.activity_id
             JOIN activity.versions v ON v.id = a.version_id
             LEFT JOIN activity.assignments asg ON asg.id = a.assignment_id
             WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)',
            [':id' => $attId, ':org' => $orgId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'attempt_not_found');
        }

        $participantId = (string) $row['participantPersonId'];
        $assignmentId = is_string($row['assignmentId']) && $row['assignmentId'] !== '' ? $row['assignmentId'] : null;
        $authorPersonId = is_string($row['authorPersonId']) && $row['authorPersonId'] !== '' ? $row['authorPersonId'] : null;

        if (!$this->policy()->canAccessParticipantAttempt($identity, $orgId, $participantId, $assignmentId, $authorPersonId)) {
            throw new HttpError(403, 'attempt_forbidden');
        }

        $isAuthorOrReviewer = ($authorPersonId === $personId)
            || $this->isOrgAdmin($identity->accountId)
            || ($participantId !== $personId && $this->permissions->hasPermission($identity->accountId, 'activity.review'));

        $versionCfg = ActivityPayload::decodeObject((string) ($row['versionConfigJson'] ?? '{}'));
        $revealPolicy = $this->normalizeRevealPolicy($row['versionRevealPolicy'] ?? $versionCfg['revealAnswersPolicy'] ?? 'after_grading');
        $state = (string) $row['state'];
        $assignmentDueAt = is_string($row['assignmentDueAt']) && $row['assignmentDueAt'] !== '' ? $row['assignmentDueAt'] : null;
        $resultsPublishedAt = is_string($row['resultsPublishedAt'] ?? null) && $row['resultsPublishedAt'] !== '' ? $row['resultsPublishedAt'] : null;

        $canRevealAnswers = $this->shouldRevealAnswers($isAuthorOrReviewer, $revealPolicy, $state, $assignmentDueAt, $resultsPublishedAt);

        $exercise = ActivityPayload::decodeObject((string) ($row['exJson'] ?? '{}'));
        $evaluation = ActivityPayload::decodeObject((string) ($row['evJson'] ?? '{}'));

        if (!$isAuthorOrReviewer && $resultsPublishedAt === null && in_array($state, ['EVALUATED', 'COMPLETED'], true)) {
            $row['rawScore'] = null;
            $row['maxScore'] = null;
            $row['percentage'] = null;
            $row['passed'] = null;
            $row['reviewNotes'] = null;
            $evaluation = [
                'resultsPublished' => false,
                'requiresManualReview' => false,
                'feedback' => 'Results are pending publication by your teacher.',
            ];
        }

        if (!$canRevealAnswers) {
            unset($exercise['_answerKey'], $exercise['_answerKeys'], $exercise['_explanations']);
            if (isset($exercise['questions']) && is_array($exercise['questions'])) {
                $exercise['questions'] = array_map(static function (mixed $q): mixed {
                    if (!is_array($q)) {
                        return $q;
                    }
                    unset($q['answerKey'], $q['answer_key'], $q['explanation']);
                    return $q;
                }, $exercise['questions']);
            }
            if (isset($evaluation['breakdown']) && is_array($evaluation['breakdown'])) {
                $evaluation['breakdown'] = array_map(static function (mixed $b): mixed {
                    if (!is_array($b)) {
                        return $b;
                    }
                    unset($b['expected'], $b['correctAnswer'], $b['answerKey'], $b['answer_key'], $b['explanation']);
                    return $b;
                }, $evaluation['breakdown']);
            }
        }

        $historyRows = $this->db->createCommand(
            'SELECT e.id::text, e.from_state AS "fromState", e.to_state AS "toState",
                    e.actor_person_id::text AS "actorPersonId", e.reason,
                    e.metadata::text AS "metaJson", e.created_at::text AS "createdAt"
             FROM activity.attempt_events e
             WHERE e.attempt_id = CAST(:aid AS uuid)
             ORDER BY e.created_at ASC, e.id ASC
             LIMIT 100',
            [':aid' => $attId],
        )->queryAll();

        $history = [];
        foreach ($historyRows as $hr) {
            $meta = ActivityPayload::decodeObject((string) ($hr['metaJson'] ?? '{}'));
            if (!$isAuthorOrReviewer && $resultsPublishedAt === null) {
                unset($meta['newScore'], $meta['previousScore'], $meta['newPercentage'], $meta['previousPercentage'], $meta['questionOverrides']);
            }
            $history[] = [
                'id' => (string) $hr['id'],
                'fromState' => $hr['fromState'],
                'toState' => (string) $hr['toState'],
                'actorPersonId' => $hr['actorPersonId'],
                'reason' => (string) $hr['reason'],
                'metadata' => $meta,
                'createdAt' => (string) $hr['createdAt'],
            ];
        }

        $row['config'] = json_decode((string) ($row['cfgJson'] ?? '{}'), true) ?: [];
        $row['exercise'] = $exercise;
        $row['answers'] = json_decode((string) ($row['ansJson'] ?? '{}'), true) ?: [];
        $row['telemetry'] = json_decode((string) ($row['telJson'] ?? '{}'), true) ?: [];
        $row['evaluation'] = $evaluation;
        $row['revealAnswersPolicy'] = $revealPolicy;
        $row['canRevealAnswers'] = $canRevealAnswers;
        $row['resultsPublished'] = $resultsPublishedAt !== null;
        $row['resultHistory'] = $history;
        $nowTs = time();
        $row['serverNow'] = gmdate(DATE_ATOM, $nowTs);
        $expiresAtStr = is_string($row['expiresAt'] ?? null) && $row['expiresAt'] !== '' ? $row['expiresAt'] : null;
        if ($expiresAtStr !== null && in_array($state, ['CREATED', 'IN_PROGRESS'], true)) {
            $expTs = strtotime($expiresAtStr);
            $row['remainingSeconds'] = $expTs !== false ? max(0, $expTs - $nowTs) : null;
        } else {
            $row['remainingSeconds'] = $expiresAtStr !== null ? 0 : null;
        }
        unset(
            $row['cfgJson'],
            $row['exJson'],
            $row['ansJson'],
            $row['telJson'],
            $row['evJson'],
            $row['authorPersonId'],
            $row['versionRevealPolicy'],
            $row['versionConfigJson'],
            $row['assignmentDueAt'],
        );

        return $row;
    }

    /** @param array<string, mixed> $query */
    public function listQuestionBank(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.create');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $includeArchived = ($query['archived'] ?? '') === '1';
        $isAdmin = $this->isOrgAdmin($identity->accountId);

        $where = 'organization_id = CAST(:org AS uuid)' . ($includeArchived ? ' AND archived_at IS NOT NULL' : ' AND archived_at IS NULL');
        $params = [':org' => $orgId];
        if (!$isAdmin) {
            $where .= ' AND author_person_id = CAST(:person AS uuid)';
            $params[':person'] = $personId;
        }
        if (is_string($query['category'] ?? null) && $query['category'] !== '') {
            $where .= ' AND category = :cat';
            $params[':cat'] = $query['category'];
        }
        if (isset($query['difficulty']) && is_numeric($query['difficulty'])) {
            $where .= ' AND difficulty = :diff';
            $params[':diff'] = (int) $query['difficulty'];
        }
        if (is_string($query['type'] ?? null) && $query['type'] !== '') {
            $where .= ' AND question_type = :qtype';
            $params[':qtype'] = $query['type'];
        }
        $rawQ = $query['q'] ?? $query['search'] ?? null;
        if (is_string($rawQ) && trim($rawQ) !== '') {
            $where .= " AND (content->>'prompt' ILIKE :q OR explanation ILIKE :q)";
            $params[':q'] = '%' . trim($rawQ) . '%';
        }

        $rows = $this->db->createCommand(
            "SELECT id::text, category, tags::text AS \"tagsJson\", difficulty, question_type AS \"questionType\",
                    content::text AS \"contentJson\", answer_key::text AS \"answerKeyJson\", explanation,
                    default_points AS \"defaultPoints\", archived_at::text AS \"archivedAt\", updated_at::text AS \"updatedAt\"
             FROM activity.question_bank WHERE {$where} ORDER BY updated_at DESC LIMIT 200",
            $params,
        )->queryAll();

        foreach ($rows as &$r) {
            $r['tags'] = json_decode((string) ($r['tagsJson'] ?? '[]'), true) ?: [];
            $r['content'] = json_decode((string) ($r['contentJson'] ?? '{}'), true) ?: [];
            $r['answerKey'] = json_decode((string) ($r['answerKeyJson'] ?? 'null'), true);
            unset($r['tagsJson'], $r['contentJson'], $r['answerKeyJson']);
        }
        unset($r);

        return ['items' => $rows];
    }

    /** @param array<string, mixed> $input */
    public function createQuestionBankItem(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.create');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $qtype = (string) ($input['questionType'] ?? 'single_choice');
        $allowed = ['single_choice', 'multi_choice', 'true_false', 'short_text', 'numeric', 'matching', 'ordering', 'long_text'];
        if (!in_array($qtype, $allowed, true)) {
            throw new HttpError(422, 'invalid_question_type');
        }
        $category = mb_substr(trim((string) ($input['category'] ?? 'general')) ?: 'general', 0, 100);
        $difficulty = max(1, min(3, (int) ($input['difficulty'] ?? 1)));
        $tags = ActivityPayload::stringList($input['tags'] ?? null);
        $content = is_array($input['content'] ?? null) ? $input['content'] : ['prompt' => (string) ($input['prompt'] ?? '')];
        $answerKey = $input['answerKey'] ?? null;
        $explanation = (string) ($input['explanation'] ?? '');
        $points = max(1, min(100, (int) ($input['defaultPoints'] ?? 1)));

        $id = (string) $this->db->createCommand(
            'INSERT INTO activity.question_bank (organization_id, author_person_id, category, tags, difficulty, question_type, content, answer_key, explanation, default_points)
             VALUES (CAST(:org AS uuid), CAST(:person AS uuid), :cat, CAST(:tags AS jsonb), :diff, :qt, CAST(:cnt AS jsonb), CAST(:ak AS jsonb), :exp, :pts)
             RETURNING id::text',
            [
                ':org' => $orgId,
                ':person' => $personId,
                ':cat' => $category,
                ':tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                ':diff' => $difficulty,
                ':qt' => $qtype,
                ':cnt' => $content === [] ? '{}' : json_encode($content, JSON_THROW_ON_ERROR),
                ':ak' => json_encode($answerKey, JSON_THROW_ON_ERROR),
                ':exp' => $explanation,
                ':pts' => $points,
            ],
        )->queryScalar();

        return ['id' => $id, 'questionType' => $qtype, 'category' => $category, 'difficulty' => $difficulty, 'content' => $content, 'answerKey' => $answerKey, 'defaultPoints' => $points];
    }

    public function archiveQuestionBankItem(AuthenticatedIdentity $identity, string $organizationId, string $id, bool $restore = false): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.create');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $qid = RealtimeService::assertUuid($id, 'id');
        $isAdmin = $this->isOrgAdmin($identity->accountId);

        $where = 'id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)';
        $params = [':restore' => $restore, ':id' => $qid, ':org' => $orgId];
        if (!$isAdmin) {
            $where .= ' AND author_person_id = CAST(:person AS uuid)';
            $params[':person'] = $personId;
        }

        $affected = $this->db->createCommand(
            "UPDATE activity.question_bank SET archived_at = CASE WHEN :restore THEN NULL ELSE now() END, updated_at = now()
             WHERE {$where}",
            $params,
        )->execute();
        if ($affected === 0) {
            throw new HttpError(404, 'question_bank_item_not_found');
        }
        return ['id' => $qid, 'archived' => !$restore];
    }

    /** @param array<string, mixed> $input */
    public function importQuestionBank(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $imported = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $imported[] = $this->createQuestionBankItem($identity, $organizationId, ActivityPayload::assoc($item));
            }
        }
        return ['importedCount' => count($imported), 'items' => $imported];
    }

    /** @return array<string, mixed> */
    public function analytics(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rawPersonId = $query['personId'] ?? null;
        $targetPerson = is_string($rawPersonId) && $rawPersonId !== ''
            ? RealtimeService::assertUuid($rawPersonId, 'personId') : $personId;

        if (!$this->policy()->canViewParticipantAnalytics($identity, $orgId, $targetPerson)) {
            throw new HttpError(403, 'access_denied');
        }

        $rawPlugin = is_string($query['pluginCode'] ?? null) && $query['pluginCode'] !== '' ? $query['pluginCode'] : null;
        $where = 'organization_id = CAST(:org AS uuid) AND participant_person_id = CAST(:person AS uuid)';
        $params = [':org' => $orgId, ':person' => $targetPerson];
        $whereJoin = 'a.organization_id = CAST(:org AS uuid) AND a.participant_person_id = CAST(:person AS uuid)';
        if ($rawPlugin !== null) {
            $where .= ' AND activity_id IN (SELECT id FROM activity.items WHERE organization_id = CAST(:org AS uuid) AND plugin_code = :pcode)';
            $whereJoin .= ' AND i.plugin_code = :pcode';
            $params[':pcode'] = $rawPlugin;
        }

        $summary = $this->db->createCommand(
            "SELECT
                count(*)::int AS \"totalAttempts\",
                count(*) FILTER (WHERE state IN ('EVALUATED', 'COMPLETED'))::int AS \"completedAttempts\",
                count(*) FILTER (WHERE passed = true)::int AS \"passedAttempts\",
                ROUND(AVG(percentage) FILTER (WHERE state IN ('EVALUATED', 'COMPLETED')), 2) AS \"averagePercentage\",
                MAX(percentage) FILTER (WHERE state IN ('EVALUATED', 'COMPLETED')) AS \"bestPercentage\"
             FROM activity.attempts
             WHERE {$where}",
            $params,
        )->queryOne();

        $recent = $this->db->createCommand(
            "SELECT
                a.id::text, a.activity_id::text AS \"activityId\", i.plugin_code AS \"pluginCode\",
                i.title, a.state, a.raw_score AS \"rawScore\", a.max_score AS \"maxScore\",
                a.percentage, a.passed, a.evaluation::text AS \"evJson\",
                COALESCE(a.evaluated_at, a.submitted_at, a.created_at)::text AS \"completedAt\"
             FROM activity.attempts a
             JOIN activity.items i ON i.id = a.activity_id
             WHERE {$whereJoin}
             ORDER BY a.created_at DESC LIMIT 30",
            $params,
        )->queryAll();

        foreach ($recent as &$r) {
            $ev = ActivityPayload::decodeObject((string) ($r['evJson'] ?? '{}'));
            $metrics = is_array($ev['metrics'] ?? null) ? ActivityPayload::assoc($ev['metrics']) : [];
            unset($metrics['answerKey'], $metrics['answer_key'], $metrics['explanation']);
            $r['metrics'] = $metrics;
            unset($r['evJson']);
        }
        unset($r);

        return ['summary' => $summary ?: [], 'recentAttempts' => $recent];
    }

    /** @return array<string, mixed>|null */
    private function loadActivityRow(string $orgId, string $aid): ?array
    {
        $row = $this->db->createCommand(
            'SELECT
                i.id::text, i.plugin_code AS "pluginCode", p.title AS "pluginTitle", p.category,
                i.author_person_id::text AS "authorPersonId", i.title, i.description, i.status, i.visibility,
                i.tags::text AS "tagsJson", i.active_version_id::text AS "activeVersionId",
                i.created_at::text AS "createdAt", i.updated_at::text AS "updatedAt",
                v.version_number AS "versionNumber", v.instructions, v.config::text AS "configJson",
                v.content::text AS "contentJson", v.time_limit_seconds AS "timeLimitSeconds",
                v.max_attempts AS "maxAttempts", v.passing_score_percent AS "passingScorePercent",
                v.grading_policy AS "gradingPolicy", v.reveal_answers_policy AS "revealAnswersPolicy"
             FROM activity.items i
             JOIN activity.plugins p ON p.code = i.plugin_code
             LEFT JOIN activity.versions v ON v.id = i.active_version_id
             WHERE i.id = CAST(:id AS uuid) AND i.organization_id = CAST(:org AS uuid)',
            [':id' => $aid, ':org' => $orgId],
        )->queryOne();

        return is_array($row) ? $row : null;
    }

    private function expireAttemptIfOverdue(string $orgId, string $attemptId): void
    {
        $this->db->transaction(function () use ($orgId, $attemptId): void {
            $row = $this->db->createCommand(
                "SELECT a.state, a.expires_at, a.participant_person_id::text AS \"participantPersonId\",
                        a.runtime_config::text AS \"cfgJson\", a.draft_state::text AS \"exJson\",
                        a.answers::text AS \"ansJson\", a.telemetry::text AS \"telJson\",
                        i.plugin_code AS \"pluginCode\", v.passing_score_percent AS \"passingScorePercent\"
                 FROM activity.attempts a
                 LEFT JOIN activity.items i ON i.id = a.activity_id
                 LEFT JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
                   AND a.state = 'IN_PROGRESS' AND a.expires_at IS NOT NULL AND a.expires_at < now()
                 FOR UPDATE OF a",
                [':id' => $attemptId, ':org' => $orgId],
            )->queryOne();
            if (!is_array($row) || (string) ($row['state'] ?? '') !== 'IN_PROGRESS' || $row['expires_at'] === null || strtotime((string) $row['expires_at']) >= time()) {
                return;
            }
            $this->finalizeExpiredAttemptRow($attemptId, (string) $row['participantPersonId'], $row);
        });
    }

    private function expireOverdueAttemptsForParticipant(string $orgId, string $personId, string $activityId): void
    {
        $this->db->transaction(function () use ($orgId, $personId, $activityId): void {
            $rows = $this->db->createCommand(
                "SELECT a.id::text AS id,
                        a.runtime_config::text AS \"cfgJson\", a.draft_state::text AS \"exJson\",
                        a.answers::text AS \"ansJson\", a.telemetry::text AS \"telJson\",
                        i.plugin_code AS \"pluginCode\", v.passing_score_percent AS \"passingScorePercent\"
                 FROM activity.attempts a
                 LEFT JOIN activity.items i ON i.id = a.activity_id
                 LEFT JOIN activity.versions v ON v.id = a.version_id
                 WHERE a.organization_id = CAST(:org AS uuid)
                   AND a.participant_person_id = CAST(:person AS uuid)
                   AND a.activity_id = CAST(:aid AS uuid)
                   AND a.state = 'IN_PROGRESS'
                   AND a.expires_at IS NOT NULL
                   AND a.expires_at < now()
                 FOR UPDATE OF a",
                [':org' => $orgId, ':person' => $personId, ':aid' => $activityId],
            )->queryAll();

            foreach ($rows as $row) {
                $attId = $row['id'] ?? null;
                if (!is_string($attId) || $attId === '') {
                    continue;
                }
                $this->finalizeExpiredAttemptRow($attId, $personId, ActivityPayload::assoc($row));
            }
        });
    }

    /**
     * Finalizes an overdue IN_PROGRESS attempt by evaluating ONLY previously saved DB answers
     * and transitioning it to EXPIRED.
     *
     * @param array<string, mixed> $row
     */
    private function finalizeExpiredAttemptRow(string $attemptId, string $actorPersonId, array $row): void
    {
        $pluginCode = isset($row['pluginCode']) && is_string($row['pluginCode']) ? $row['pluginCode'] : '';
        $plugin = null;
        if ($pluginCode !== '') {
            try {
                $plugin = $this->plugins->get($pluginCode);
            } catch (\Throwable) {
                $plugin = null;
            }
        }
        if ($plugin !== null && isset($row['exJson'])) {
            $cfg = ActivityPayload::decodeObject((string) ($row['cfgJson'] ?? '{}'));
            $cfg['passingScorePercent'] = (int) ($row['passingScorePercent'] ?? $cfg['passingScorePercent'] ?? 60);
            $exercise = ActivityPayload::decodeObject((string) ($row['exJson'] ?? '{}'));
            $savedAnswers = ActivityPayload::decodeObject((string) ($row['ansJson'] ?? '{}'));
            $telemetry = ActivityPayload::decodeObject((string) ($row['telJson'] ?? '{}'));
            $submission = isset($savedAnswers['answers']) && is_array($savedAnswers['answers'])
                ? $savedAnswers
                : ['answers' => $savedAnswers];

            $eval = $plugin->evaluate($cfg, $exercise, $submission, $telemetry);
            $this->db->createCommand(
                "UPDATE activity.attempts SET
                    state = 'EXPIRED',
                    revision = revision + 1,
                    evaluation = CAST(:ev AS jsonb),
                    raw_score = :raw,
                    max_score = :max,
                    percentage = :pct,
                    passed = :passed,
                    submitted_at = COALESCE(submitted_at, now()),
                    evaluated_at = CASE WHEN :isEvaluated THEN now() ELSE NULL END,
                    updated_at = now()
                 WHERE id = CAST(:id AS uuid)",
                [
                    ':ev' => json_encode($eval, JSON_THROW_ON_ERROR),
                    ':raw' => $eval['rawScore'],
                    ':max' => $eval['maxScore'],
                    ':pct' => $eval['percentage'],
                    ':passed' => $eval['passed'],
                    ':isEvaluated' => !$eval['requiresManualReview'],
                    ':id' => $attemptId,
                ],
            )->execute();
        } else {
            $this->db->createCommand(
                "UPDATE activity.attempts SET state = 'EXPIRED', updated_at = now() WHERE id = CAST(:id AS uuid)",
                [':id' => $attemptId],
            )->execute();
        }

        $this->recordTransition(
            $attemptId,
            'IN_PROGRESS',
            'EXPIRED',
            $actorPersonId,
            'deadline_exceeded',
        );
    }

    /** @return array<string, mixed> */
    public function bootstrapTypingActivity(AuthenticatedIdentity $identity, string $organizationId): array
    {
        return $this->bootstrapPluginActivity($identity, $organizationId, 'platform-typing');
    }

    /**
     * Ensures a published org-visible activity item & v1 exist for a canonical plugin code.
     *
     * @return array<string, mixed>
     */
    public function bootstrapPluginActivity(AuthenticatedIdentity $identity, string $organizationId, string $pluginCode): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'activity.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        if (!$this->plugins->has($pluginCode)) {
            throw new HttpError(404, 'plugin_not_found');
        }
        $plugin = $this->plugins->get($pluginCode);

        $existingId = $this->db->createCommand(
            "SELECT id::text
             FROM activity.items
             WHERE organization_id = CAST(:org AS uuid)
               AND plugin_code = :code
               AND status = 'PUBLISHED'
               AND visibility IN ('public_catalog', 'organization')
             ORDER BY created_at ASC
             LIMIT 1",
            [':org' => $orgId, ':code' => $pluginCode],
        )->queryScalar();

        if (is_string($existingId) && $existingId !== '') {
            return $this->getActivity($identity, $orgId, $existingId);
        }

        $defaultCfg = $plugin->defaultConfig();
        if (!isset($defaultCfg['revealAnswersPolicy'])) {
            $defaultCfg['revealAnswersPolicy'] = 'immediate';
        }
        $title = $pluginCode === 'platform-typing' ? 'Typing Arena' : $plugin->title();
        $description = $pluginCode === 'platform-typing'
            ? 'Universal Monkeytype-grade touch typing trainer'
            : ('Canonical ' . $plugin->title() . ' activity');
        $tags = json_encode([$plugin->category(), $pluginCode], JSON_THROW_ON_ERROR);
        $timeLimit = isset($defaultCfg['timeLimitSeconds']) && is_int($defaultCfg['timeLimitSeconds'])
            ? $defaultCfg['timeLimitSeconds']
            : null;
        $passing = isset($defaultCfg['minAccuracy']) && is_int($defaultCfg['minAccuracy'])
            ? $defaultCfg['minAccuracy']
            : 65;

        $createdId = '';
        $this->db->transaction(function () use (
            &$createdId,
            $identity,
            $orgId,
            $personId,
            $pluginCode,
            $title,
            $description,
            $tags,
            $defaultCfg,
            $timeLimit,
            $passing,
        ): void {
            $createdId = (string) $this->db->createCommand(
                "INSERT INTO activity.items (
                    organization_id, plugin_code, author_person_id, author_account_id,
                    title, description, status, visibility, tags
                 ) VALUES (
                    CAST(:org AS uuid), :code, CAST(:person AS uuid), CAST(:account AS uuid),
                    :title, :descr, 'PUBLISHED', 'public_catalog', CAST(:tags AS jsonb)
                 ) RETURNING id::text",
                [
                    ':org' => $orgId,
                    ':code' => $pluginCode,
                    ':person' => $personId,
                    ':account' => $identity->accountId,
                    ':title' => $title,
                    ':descr' => $description,
                    ':tags' => $tags,
                ],
            )->queryScalar();

            $verId = (string) $this->db->createCommand(
                "INSERT INTO activity.versions (
                    activity_id, version_number, title, instructions, config, content,
                    time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, auto_publish_results, created_by_person_id
                 ) VALUES (
                    CAST(:aid AS uuid), 1, :title, '', CAST(:cfg AS jsonb), '{}'::jsonb,
                    :timeLimit, 0, :passing, 'highest', 'immediate', true, CAST(:person AS uuid)
                 ) RETURNING id::text",
                [
                    ':aid' => $createdId,
                    ':title' => $title,
                    ':cfg' => json_encode($defaultCfg, JSON_THROW_ON_ERROR),
                    ':timeLimit' => $timeLimit,
                    ':passing' => $passing,
                    ':person' => $personId,
                ],
            )->queryScalar();

            $this->db->createCommand(
                'UPDATE activity.items SET active_version_id = CAST(:vid AS uuid) WHERE id = CAST(:aid AS uuid)',
                [':vid' => $verId, ':aid' => $createdId],
            )->execute();
        });

        return $this->getActivity($identity, $orgId, $createdId);
    }

    /**
     * @param array<string, mixed> $clientCfg
     * @return array<string, mixed>
     */
    private function filterParticipantConfig(ActivityPluginInterface $plugin, array $clientCfg): array
    {
        $allowedKeys = $plugin->safeParticipantConfigKeys();
        if ($allowedKeys === [] || $clientCfg === []) {
            return [];
        }
        $filtered = array_intersect_key($clientCfg, array_flip($allowedKeys));
        $schema = $plugin->configSchema();
        $out = [];
        foreach ($filtered as $k => $v) {
            $rule = $schema[$k] ?? [];
            $type = $rule['type'] ?? null;
            if ($type === 'enum' && isset($rule['values']) && is_array($rule['values'])) {
                if (!in_array($v, $rule['values'], true)) {
                    continue;
                }
                $out[$k] = $v;
            } elseif ($type === 'integer') {
                if (!is_numeric($v)) {
                    continue;
                }
                $intVal = (int) $v;
                if (isset($rule['min']) && $intVal < (int) $rule['min']) {
                    $intVal = (int) $rule['min'];
                }
                if (isset($rule['max']) && $intVal > (int) $rule['max']) {
                    $intVal = (int) $rule['max'];
                }
                $out[$k] = $intVal;
            } elseif ($type === 'boolean') {
                $out[$k] = (bool) $v;
            } elseif ($type === 'string') {
                if (!is_string($v)) {
                    continue;
                }
                $maxLen = isset($rule['maxLength']) ? (int) $rule['maxLength'] : 2000;
                $out[$k] = mb_substr(trim($v), 0, $maxLen, 'UTF-8');
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $overrideCfg
     * @return array<string, mixed>
     */
    private function filterAssignmentOverrideConfig(ActivityPluginInterface $plugin, array $overrideCfg): array
    {
        if ($overrideCfg === []) {
            return [];
        }
        $schemaKeys = array_keys($plugin->configSchema());
        $blocked = ['questions', 'answerKey', 'answer_key', 'explanations', '_answerKey', '_answerKeys', 'openPractice', 'examOnly'];
        $allowed = array_diff($schemaKeys, $blocked);
        return array_intersect_key($overrideCfg, array_flip($allowed));
    }

    private function normalizeRevealPolicy(mixed $policy): string
    {
        return is_string($policy) && in_array($policy, ['never', 'immediate', 'after_grading', 'deadline'], true)
            ? $policy
            : 'after_grading';
    }

    private function shouldRevealAnswers(
        bool $isAuthorOrReviewer,
        string $revealPolicy,
        string $state,
        ?string $assignmentDueAt,
        ?string $resultsPublishedAt = null,
    ): bool {
        if ($isAuthorOrReviewer) {
            return true;
        }
        return match ($revealPolicy) {
            'never' => false,
            'immediate' => in_array($state, ['SUBMITTED', 'PENDING_REVIEW', 'EVALUATED', 'COMPLETED', 'EXPIRED'], true),
            'after_grading' => in_array($state, ['EXPIRED'], true)
                || (in_array($state, ['EVALUATED', 'COMPLETED'], true) && $resultsPublishedAt !== null),
            'deadline' => (in_array($state, ['EXPIRED'], true)
                || (in_array($state, ['EVALUATED', 'COMPLETED'], true) && $resultsPublishedAt !== null))
                && ($assignmentDueAt === null || strtotime($assignmentDueAt) <= time()),
            default => false,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function recordTransition(
        string $attemptId,
        ?string $from,
        string $to,
        string $actorPersonId,
        string $reason,
        array $metadata = [],
    ): void {
        $this->db->createCommand(
            'INSERT INTO activity.attempt_events (attempt_id, from_state, to_state, actor_person_id, reason, metadata)
             VALUES (CAST(:aid AS uuid), :from, :to, CAST(:actor AS uuid), :reason, CAST(:meta AS jsonb))',
            [
                ':aid' => $attemptId,
                ':from' => $from,
                ':to' => $to,
                ':actor' => $actorPersonId,
                ':reason' => $reason,
                ':meta' => $metadata === [] ? '{}' : json_encode($metadata, JSON_THROW_ON_ERROR),
            ],
        )->execute();
    }

    private function policy(): ActivityDomainPolicyInterface
    {
        return $this->domainPolicy ?? new DefaultActivityDomainPolicy($this->db, $this->permissions);
    }

    private function isOrgAdmin(string $accountId): bool
    {
        if ($this->permissions->hasPermission($accountId, 'organizations.manage')) {
            return true;
        }
        foreach ($this->permissions->getRoles($accountId) as $role) {
            if (in_array($role->code, ['core.admin', 'core.superadmin'], true)) {
                return true;
            }
        }
        return false;
    }

    private function assertAccess(AuthenticatedIdentity $identity, string $organizationId, string $permission): string
    {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        if (!$this->organizations->activeMember($personId, $orgId)) {
            throw new HttpError(403, 'organization_access_denied');
        }
        if (!$this->permissions->hasPermission($identity->accountId, $permission)) {
            throw new HttpError(403, 'access_denied');
        }
        return $personId;
    }
}
