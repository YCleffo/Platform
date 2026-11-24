<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Read-only projections with an explicit family audience. No documents, answers, peers or service comments. */
final readonly class ParentReadService
{
    public function __construct(
        private ConnectionInterface $db,
        private EducationAccess $access,
        private PermissionService $permissions,
        private CurriculumStore $curriculum,
        private EducationStore $education,
        private ClockInterface $clock,
        private AchievementService $achievements,
    ) {}

    public function context(AuthenticatedIdentity $identity): array
    {
        $person = $this->access->assertAvailable($identity);
        $active = $this->db->createCommand("SELECT id FROM core.accounts WHERE id=CAST(:account AS uuid) AND person_id=CAST(:person AS uuid)
            AND status='active' AND disabled_at IS NULL AND archived_at IS NULL", [':account' => $identity->accountId,':person' => $person])->queryOne();
        if (!is_array($active)) {
            throw new EducationError(403, 'access_denied');
        }
        if (!$this->permissions->hasPermission($identity->accountId, 'education.parent.use')) {
            throw new EducationError(403, 'access_denied');
        }
        $rows = $this->all("SELECT sg.student_id::text AS id,sg.organization_id::text AS \"organizationId\"
            FROM education.student_guardians sg JOIN education.parent_accounts pa ON pa.organization_id=sg.organization_id
            AND pa.guardian_person_id=sg.guardian_person_id WHERE sg.guardian_person_id=CAST(:person AS uuid)
            AND pa.account_id=CAST(:account AS uuid) AND pa.revoked_at IS NULL AND sg.status='confirmed'
            ORDER BY sg.organization_id,sg.created_at,sg.student_id", [':person' => $person,':account' => $identity->accountId]);
        $children = [];
        foreach ($rows as $row) {
            try {
                $scope = $this->access->parentScope($identity, $row['organizationId'], $row['id']);
                $children[] = $this->child($scope);
            } catch (EducationError $error) {
                if (!in_array($error->status, [403,404], true)) {
                    throw $error;
                }
            }
        }
        // Lifecycle notices remain useful after revoke; no child identifiers, names or data are exposed here.
        $notices = $this->all("SELECT id::text,kind,created_at::text AS \"createdAt\",read_at::text AS \"readAt\"
            FROM education.notifications WHERE person_id=CAST(:person AS uuid) AND kind IN
            ('parent_family_revoked','parent_family_invitation_revoked','parent_family_confirmed','parent_family_invited')
            ORDER BY created_at DESC,id DESC LIMIT 20", [':person' => $person]);
        return ['personId' => $person,'displayName' => $this->access->personName($person),'locale' => $identity->locale ?? 'ru-RU',
            'timezone' => $identity->timezone ?? 'UTC','children' => $children,'notices' => $notices];
    }

    public function read(string $operation, ParentScope $scope, array $query = []): array
    {
        // Recheck persisted relationships and live RBAC even if an application caller retained an old scope.
        $scope = $this->access->parentScope(new AuthenticatedIdentity(
            $scope->accountId,
            $scope->personId,
            '',
            [],
            [],
            $scope->locale,
            $scope->timezone,
        ), $scope->organizationId, $scope->studentId);
        $offset = Input::page($query['offset'] ?? null);
        return match ($operation) {
            'child' => $this->child($scope),'home' => $this->home($scope),
            'schedule' => $this->schedule($scope, $query),'diary' => $this->diary($scope, $query, $offset),
            'homework' => $this->homework($scope, $offset),'progress' => $this->progress($scope, $query),
            'notifications' => $this->notifications($scope, $offset),
            'contacts' => $this->contacts($scope),
            'threads' => $this->threads($scope, $offset),
            default => throw new EducationError(404, 'not_found'),
        };
    }

    public function readNotification(ParentScope $scope, string $id): array
    {
        $this->read('child', $scope);
        Input::uuid($id);
        $notice = $this->db->createCommand('SELECT kind,resource_id::text AS "resourceId" FROM education.notifications
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid)
            AND parent_student_id=CAST(:student AS uuid)', [...self::params($scope),':person' => $scope->personId,':id' => $id])->queryOne();
        if (!is_array($notice) || !$this->noticeAllowed($scope, $notice)) {
            throw new EducationError(404, 'not_found');
        }
        $changed = $this->db->createCommand('UPDATE education.notifications SET read_at=COALESCE(read_at,now())
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid)
            AND parent_student_id=CAST(:student AS uuid)', [...self::params($scope),':person' => $scope->personId,':id' => $id])->execute();
        if ($changed !== 1) {
            throw new EducationError(404, 'not_found');
        }return ['read' => true];
    }

    private function child(ParentScope $scope): array
    {
        $organization = $this->db->createCommand('SELECT name FROM core.organizations WHERE id=CAST(:id AS uuid)', [':id' => $scope->organizationId])->queryScalar();
        $groups = $this->all('SELECT g.id::text,g.name FROM education.groups g JOIN education.group_students gs ON gs.group_id=g.id
            WHERE gs.organization_id=CAST(:org AS uuid) AND g.organization_id=CAST(:org AS uuid) AND gs.student_id=CAST(:student AS uuid)
            ORDER BY g.created_at,g.id', self::params($scope));
        $groups = array_values(array_filter($groups, fn(array $g): bool => $this->allowed($scope, 'group', $g['id'])));
        return ['id' => $scope->studentId,'personId' => $scope->studentPersonId,'name' => $this->access->personName($scope->studentPersonId),
            'organizationId' => $scope->organizationId,'organizationName' => $organization,'groups' => $groups];
    }

    private function schedule(ParentScope $scope, array $query): array
    {
        [$from,$to,$start,$end] = $this->range($scope, $query);
        $rows = $this->lessons($scope, $start, $end, 0, 101, false);
        return ['from' => $from,'to' => $to,'timezone' => $scope->timezone,
            'items' => $this->visibleLessons($scope, array_slice($rows, 0, 100)),'truncated' => count($rows) > 100];
    }

    private function diary(ParentScope $scope, array $query, int $offset): array
    {
        [,,$start,$end] = $this->range($scope, $query);
        $page = self::page($this->lessons($scope, $start, $end, $offset, 51, true), $offset);
        $page['items'] = $this->visibleLessons($scope, $page['items']);
        return $page;
    }

    private function lessons(ParentScope $scope, string $start, string $end, int $offset, int $limit, bool $descending, bool $completedOnly = false): array
    {
        $direction = $descending ? 'DESC' : 'ASC';
        $statusFilter = $completedOnly ? " AND l.status='completed'" : '';
        $rows = $this->all(
            "SELECT l.id::text,l.group_id::text AS \"groupId\",g.name AS \"groupName\",l.title,
            l.starts_at::text AS \"startsAt\",l.ends_at::text AS \"endsAt\",l.location,l.status,
            COALESCE(a.status,'pending') AS attendance,CASE WHEN l.status='completed' THEN COALESCE(r.status,'pending') ELSE 'pending' END AS \"resultStatus\",
            CASE WHEN l.status='completed' THEN r.comment ELSE NULL END AS \"resultComment\",
            CASE WHEN l.status='completed' THEN report.topic ELSE NULL END AS topic,
            CASE WHEN l.status='completed' THEN report.summary ELSE NULL END AS summary
            FROM education.lessons l JOIN education.groups g ON g.id=l.group_id
            LEFT JOIN education.attendance a ON a.lesson_id=l.id AND a.student_id=CAST(:student AS uuid) AND a.organization_id=l.organization_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=l.id AND r.student_id=CAST(:student AS uuid) AND r.organization_id=l.organization_id
            LEFT JOIN education.lesson_reports report ON report.lesson_id=l.id AND report.organization_id=l.organization_id
            WHERE l.organization_id=CAST(:org AS uuid) AND l.starts_at>=CAST(:start AS timestamptz) AND l.starts_at<CAST(:end AS timestamptz)
            AND (a.student_id IS NOT NULL OR EXISTS(SELECT 1 FROM education.group_students gs WHERE gs.group_id=l.group_id
                AND gs.organization_id=l.organization_id AND gs.student_id=CAST(:student AS uuid)))
            $statusFilter ORDER BY l.starts_at $direction,l.id $direction LIMIT :limit OFFSET :offset",
            [...self::params($scope),':start' => $start,':end' => $end,':offset' => $offset,':limit' => $limit],
        );
        return $rows;
    }

    private function visibleLessons(ParentScope $scope, array $rows): array
    {
        return array_values(array_filter($rows, fn(array $row): bool => $this->allowed($scope, 'lesson', $row['id']) && $this->allowed($scope, 'group', $row['groupId'])));
    }

    private function homework(ParentScope $scope, int $offset): array
    {
        $rows = $this->all(
            "SELECT h.id::text,h.title,h.description,h.instructions,h.type,h.due_at::text AS \"dueAt\",h.status,
            COALESCE(s.state,'IN_PROGRESS') AS \"submissionState\",v.submitted_at::text AS \"submittedAt\",
            review.decision AS \"reviewDecision\",review.result_status AS \"reviewResultStatus\",review.comment AS \"reviewComment\",
            review.created_at::text AS \"reviewCreatedAt\"
            FROM education.homework_recipients recipient
            JOIN education.homework_assignments h ON h.id=recipient.assignment_id AND h.organization_id=recipient.organization_id
            LEFT JOIN education.homework_submissions s ON s.recipient_id=recipient.id AND s.organization_id=recipient.organization_id
            LEFT JOIN LATERAL(SELECT sv.id,sv.submitted_at FROM education.homework_submission_versions sv
                WHERE sv.submission_id=s.id AND sv.organization_id=s.organization_id ORDER BY sv.sequence DESC LIMIT 1) v ON true
            LEFT JOIN education.homework_reviews review ON review.submission_version_id=v.id AND review.organization_id=h.organization_id
            WHERE recipient.organization_id=CAST(:org AS uuid) AND recipient.student_id=CAST(:student AS uuid)
            AND recipient.person_id=CAST(:child AS uuid) AND h.status IN ('PUBLISHED','CLOSED','ARCHIVED')
            ORDER BY CASE WHEN h.status='ARCHIVED' THEN 2 WHEN s.state='REVIEWED' THEN 1 ELSE 0 END,
                h.due_at ASC NULLS LAST,h.created_at DESC,h.id LIMIT 51 OFFSET :offset",
            [...self::params($scope),':child' => $scope->studentPersonId,':offset' => $offset],
        );
        $page = self::page($rows, $offset);
        $items = [];
        foreach ($page['items'] as $row) {
            if (!$this->allowed($scope, 'homework', $row['id'])) {
                continue;
            }
            $row['review'] = $row['reviewDecision'] === null ? null : ['decision' => $row['reviewDecision'],'resultStatus' => $row['reviewResultStatus'],
                'comment' => $row['reviewComment'],'createdAt' => $row['reviewCreatedAt']];
            unset($row['reviewDecision'],$row['reviewResultStatus'],$row['reviewComment'],$row['reviewCreatedAt']);
            $items[] = $row;
        }
        return [...$page,'items' => $items];
    }

    private function attendance(ParentScope $scope, array $query): array
    {
        [,,$start,$end] = $this->range($scope, $query);
        $rows = $this->all("SELECT a.status,l.id::text,l.group_id::text AS \"groupId\" FROM education.attendance a
            JOIN education.lessons l ON l.id=a.lesson_id AND l.organization_id=a.organization_id
            WHERE a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)
            AND l.status='completed' AND l.starts_at>=CAST(:start AS timestamptz) AND l.starts_at<CAST(:end AS timestamptz)
            ", [...self::params($scope),':start' => $start,':end' => $end]);
        $counts = ['present' => 0,'absent' => 0,'pending' => 0,'total' => 0];
        foreach ($rows as $row) {
            if (!$this->allowed($scope, 'lesson', $row['id']) || !$this->allowed($scope, 'group', $row['groupId'])) {
                continue;
            }$counts[$row['status']]++;
            $counts['total']++;
        }
        return $counts;
    }

    private function progress(ParentScope $scope, array $query): array
    {
        // Reuse Curriculum's existing immutable assignment snapshots and actual completed lesson results.
        // This projection scope carries the PARENT actor, never an account belonging to the child.
        $projection = new Scope($scope->organizationId, $scope->accountId, $scope->personId, $scope->locale, $scope->timezone, $scope->studentId);
        $programs = $this->curriculum->progress($projection, null, $scope->studentId)['programs'];
        $programs = array_values(array_filter($programs, fn(array $p): bool => $this->allowed($scope, 'group', $p['groupId'])
            && $this->allowed($scope, 'program', $p['programId'])));
        $counts = ['total' => 0,'submitted' => 0,'reviewed' => 0,'returned' => 0];
        $facts = $this->all("SELECT h.id::text,COALESCE(s.state,'IN_PROGRESS') AS state FROM education.homework_recipients r
            JOIN education.homework_assignments h ON h.id=r.assignment_id AND h.organization_id=r.organization_id
            LEFT JOIN education.homework_submissions s ON s.recipient_id=r.id
            WHERE r.organization_id=CAST(:org AS uuid) AND r.student_id=CAST(:student AS uuid)
            AND h.status IN ('PUBLISHED','CLOSED','ARCHIVED')", self::params($scope));
        foreach ($facts as $fact) {
            if (!$this->allowed($scope, 'homework', $fact['id'])) {
                continue;
            }$counts['total']++;
            if (in_array($fact['state'], ['SUBMITTED','RESUBMITTED','REVIEWED','RETURNED'], true)) {
                $counts['submitted']++;
            }
            if ($fact['state'] === 'REVIEWED') {
                $counts['reviewed']++;
            }if ($fact['state'] === 'RETURNED') {
                $counts['returned']++;
            }
        }
        $activities = array_values(array_filter($this->education->studentAttempts($projection, 0), fn(array $a): bool => $a['status'] === 'finished' && ($a['revokedAt'] ?? null) === null && $this->allowed($scope, 'attempt', $a['id'])));
        [$from,$to,$start,$end] = $this->range($scope, $query);
        $games = $this->achievements->progressForStudent(
            $scope->organizationId,
            $scope->studentId,
            $start,
            $end,
            null,
            fn(string $type, string $id): bool => $this->allowed($scope, $type, $id),
        );
        return ['programs' => $programs,'attendance' => $this->attendance($scope, $query),'homework' => $counts,
            'typing' => array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['attemptId','wpm','accuracy','createdAt','language','durationSeconds','keystrokes','correctChars','errors'])), array_slice($games['typing']['history'], 0, 50)),'activities' => array_slice($activities, 0, 50),
            'achievements' => $games['achievements'],'activityProgress' => $games];
    }

    private function home(ParentScope $scope): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone));
        $range = ['from' => $now->format('Y-m-d'),'to' => $now->modify('+30 days')->format('Y-m-d')];
        $schedule = $this->schedule($scope, $range)['items'];
        $next = null;
        foreach ($schedule as $lesson) {
            if ($lesson['status'] === 'scheduled' && new DateTimeImmutable($lesson['endsAt']) > $now) {
                $next = $lesson;
                break;
            }
        }
        $month = ['from' => $now->modify('first day of this month')->format('Y-m-d'),'to' => $now->modify('last day of this month')->format('Y-m-d')];
        $diary = $this->visibleLessons($scope, $this->lessons($scope, '1970-01-01T00:00:00Z', $now->modify('+1 day')->format(DATE_ATOM), 0, 51, true, true));
        $results = array_values(array_filter($diary, static fn(array $l): bool => $l['status'] === 'completed' && $l['resultStatus'] !== 'pending'));
        $notifications = $this->notifications($scope, 0);
        return ['child' => $this->child($scope),'nextLesson' => $next,'results' => array_slice($results, 0, 5),
            'homework' => array_slice($this->homework($scope, 0)['items'], 0, 5),'attendance' => $this->attendance($scope, $month),
            'unreadCount' => $notifications['unreadCount']];
    }

    private function notifications(ParentScope $scope, int $offset): array
    {
        $params = [...self::params($scope),':person' => $scope->personId];
        $where = 'organization_id=CAST(:org AS uuid) AND parent_student_id=CAST(:student AS uuid) AND person_id=CAST(:person AS uuid)';
        $items = $this->all('SELECT id::text,NULL::text AS "threadId",kind,resource_id::text AS "resourceId",resource_path AS "resourcePath",
            subject,body,created_at::text AS "createdAt",read_at::text AS "readAt" FROM education.notifications WHERE ' . $where .
            ' ORDER BY created_at DESC,id DESC LIMIT 51 OFFSET :offset', [...$params,':offset' => $offset]);
        $visible = array_values(array_filter($items, fn(array $item): bool => $this->noticeAllowed($scope, $item)));
        $pending = $this->db->createCommand('SELECT kind,resource_id::text AS "resourceId" FROM education.notifications WHERE ' . $where . ' AND read_at IS NULL', $params)->queryAll();
        $unread = count(array_filter($pending, fn(array $item): bool => $this->noticeAllowed($scope, $item)));
        $items = $visible;
        return [...self::page($items, $offset),'unreadCount' => $unread];
    }

    private function noticeAllowed(ParentScope $scope, array $notice): bool
    {
        $type = match ($notice['kind']) {
            'parent_activity_finished' => 'attempt','parent_achievement_unlocked' => 'achievement',default => null,
        };
        return $type === null || $this->allowed($scope, $type, $notice['resourceId']);
    }

    /** @return array{string, string, string, string} */
    private function range(ParentScope $scope, array $query): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone));
        $from = Input::date($query['from'] ?? $now->modify('first day of this month')->format('Y-m-d'));
        $to = Input::date($query['to'] ?? $now->modify('last day of this month')->format('Y-m-d'));
        [$start,$end] = Input::range($from, $to, $scope->timezone);
        return[$from,$to,$start,$end];
    }
    private function allowed(ParentScope $scope, string $type, string $id): bool
    {
        return $this->access->resourceAllowed($scope->personId, $type, $id) && $this->access->resourceAllowed($scope->studentPersonId, $type, $id);
    }
    private static function params(ParentScope $scope): array
    {
        return[':org' => $scope->organizationId,':student' => $scope->studentId];
    }
    private static function page(array $items, int $offset): array
    {
        return['items' => array_slice($items, 0, 50),'limit' => 50,'offset' => $offset,'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }
    private function all(string $sql, array $params): array
    {
        $items = $this->db->createCommand($sql, $params)->queryAll();
        foreach ($items as &$row) {
            foreach ($row as $key => $value) {
                if (str_ends_with($key, 'At') && is_string($value)) {
                    $row[$key] = ParentFamily::instant($value);
                }
            }
        }
        unset($row);
        return $items;
    }

    public function contacts(ParentScope $scope): array
    {
        $teachers = $this->all(
            'SELECT DISTINCT t.person_id::text AS "personId", \'teacher\' AS role,
                g.id::text AS "contextId", g.name AS "contextName"
            FROM education.group_students gs
            JOIN education.groups g ON g.id=gs.group_id AND g.organization_id=gs.organization_id
            JOIN education.teachers t ON t.person_id=g.owner_person_id AND t.organization_id=g.organization_id
            WHERE gs.organization_id=CAST(:org AS uuid) AND gs.student_id=CAST(:student AS uuid)
            AND g.archived_at IS NULL ORDER BY "personId"',
            self::params($scope),
        );
        $items = [];
        /** @var array<string, mixed> $row */
        foreach ($teachers as $row) {
            $personId = is_scalar($row['personId'] ?? null) ? (string) $row['personId'] : '';
            $name = $this->access->personName($personId);
            if ($name === null) {
                continue;
            }
            $items[] = [
                'id' => $personId,
                'personId' => $personId,
                'name' => $name,
                'role' => 'teacher',
                'contextId' => is_scalar($row['contextId'] ?? null) ? (string) $row['contextId'] : '',
                'contextName' => is_scalar($row['contextName'] ?? null) ? (string) $row['contextName'] : '',
            ];
        }
        return ['items' => $items];
    }

    public function threads(ParentScope $scope, int $offset): array
    {
        $this->read('child', $scope);
        $items = $this->all(
            'SELECT t.id::text,t.subject,t.created_at::text AS "createdAt",
            COALESCE(t.last_activity_at, t.created_at)::text AS "lastActivityAt",
            t.context_type AS "contextType", t.context_id::text AS "contextId",
            (SELECT peer.person_id::text FROM education.thread_members peer
                WHERE peer.thread_id=t.id AND peer.person_id<>CAST(:person AS uuid) LIMIT 1) AS "peerPersonId",
            (SELECT m.body FROM education.messages m
                WHERE m.thread_id=t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS "lastMessageBody",
            (SELECT m.created_at::text FROM education.messages m
                WHERE m.thread_id=t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS "lastMessageAt",
            (SELECT m.sender_person_id::text FROM education.messages m
                WHERE m.thread_id=t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS "lastMessageSenderId",
            (SELECT count(*) FROM education.messages m WHERE m.thread_id=t.id AND m.sender_person_id<>CAST(:person AS uuid)
                AND (tm.last_read_at IS NULL OR (m.created_at,m.id)>(tm.last_read_at,COALESCE(tm.last_read_message_id,\'ffffffff-ffff-ffff-ffff-ffffffffffff\'::uuid)))) AS "unreadCount"
            FROM education.threads t JOIN education.thread_members tm ON tm.thread_id=t.id
            WHERE t.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid)
            AND t.context_id=CAST(:student AS uuid)
            ORDER BY COALESCE(t.last_activity_at, t.created_at) DESC,t.id DESC LIMIT 51 OFFSET :offset',
            [...self::params($scope), ':person' => $scope->personId, ':offset' => $offset],
        );
        /** @var array<string, mixed> $row */
        foreach ($items as &$row) {
            $peerId = is_scalar($row['peerPersonId'] ?? null) ? (string) $row['peerPersonId'] : null;
            $row['peerName'] = $peerId !== null ? $this->access->personName($peerId) : null;
            $row['peerRole'] = 'teacher';
        }
        unset($row);
        return self::page($items, $offset);
    }

    public function thread(ParentScope $scope, string $id): array
    {
        $this->read('child', $scope);
        Input::uuid($id);
        $thread = $this->db->createCommand(
            'SELECT t.id::text,t.subject,t.created_at::text AS "createdAt",
            COALESCE(t.last_activity_at, t.created_at)::text AS "lastActivityAt",
            t.context_type AS "contextType", t.context_id::text AS "contextId",
            (SELECT peer.person_id::text FROM education.thread_members peer
                WHERE peer.thread_id=t.id AND peer.person_id<>CAST(:person AS uuid) LIMIT 1) AS "peerPersonId"
            FROM education.threads t JOIN education.thread_members tm ON tm.thread_id=t.id
            WHERE t.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid)
            AND t.id=CAST(:id AS uuid) AND t.context_id=CAST(:student AS uuid)',
            [...self::params($scope), ':person' => $scope->personId, ':id' => $id],
        )->queryOne();
        if (!is_array($thread)) {
            throw new EducationError(404, 'not_found');
        }
        $peerId = is_scalar($thread['peerPersonId'] ?? null) ? (string) $thread['peerPersonId'] : null;
        $thread['peerName'] = $peerId !== null ? $this->access->personName($peerId) : null;
        $thread['peerRole'] = 'teacher';
        return $thread;
    }

    public function messages(ParentScope $scope, string $threadId, int $offset): array
    {
        $this->thread($scope, $threadId);
        $items = $this->all(
            'SELECT m.id::text,m.sender_person_id::text AS "senderPersonId",m.body,m.created_at::text AS "createdAt",
                    COALESCE(m.kind, \'text\') AS "kind",
                    m.media_asset_id::text AS "mediaAssetId",
                    m.reply_to_id::text AS "replyToId",
                    m.metadata::text AS "metadataJson",
                    ma.kind AS "mediaKind", ma.original_name AS "mediaName", COALESCE(ma.verified_mime, ma.original_mime) AS "mediaMime",
                    ma.size_bytes AS "mediaSize", ma.status AS "mediaStatus",
                    rm.body AS "replyBody", rm.sender_person_id::text AS "replySenderPersonId"
            FROM education.messages m JOIN education.thread_members tm ON tm.thread_id=m.thread_id
            LEFT JOIN media.assets ma ON ma.id = m.media_asset_id
            LEFT JOIN education.messages rm ON rm.id = m.reply_to_id
            WHERE m.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid) AND m.thread_id=CAST(:id AS uuid)
            ORDER BY m.created_at DESC,m.id DESC LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':person' => $scope->personId, ':id' => $threadId, ':offset' => $offset],
        );

        $reactionsRows = $this->all(
            'SELECT mr.message_id::text AS "messageId", mr.emoji, count(*)::int AS count,
                    bool_or(mr.person_id=CAST(:person AS uuid)) AS reacted
             FROM education.message_reactions mr
             JOIN education.messages m ON m.id = mr.message_id
             WHERE m.organization_id=CAST(:org AS uuid) AND m.thread_id=CAST(:id AS uuid)
             GROUP BY mr.message_id, mr.emoji
             ORDER BY count DESC, mr.emoji',
            [':org' => $scope->organizationId, ':person' => $scope->personId, ':id' => $threadId],
        );

        $reactionsByMessage = [];
        /** @var array<string, mixed> $rr */
        foreach ($reactionsRows as $rr) {
            $msgKey = is_scalar($rr['messageId'] ?? null) ? (string) $rr['messageId'] : '';
            $reactionsByMessage[$msgKey][] = [
                'emoji' => is_scalar($rr['emoji'] ?? null) ? (string) $rr['emoji'] : '',
                'count' => (int) ($rr['count'] ?? 0),
                'reacted' => (bool) ($rr['reacted'] ?? false),
            ];
        }

        /** @var list<array<string, mixed>> $items */
        foreach ($items as &$message) {
            $sender = isset($message['senderPersonId']) && is_scalar($message['senderPersonId']) ? (string) $message['senderPersonId'] : '';
            $msgId = isset($message['id']) && is_scalar($message['id']) ? (string) $message['id'] : '';
            $senderName = $sender !== '' && $this->access->member($sender, $scope->organizationId) ? $this->access->personName($sender) : null;
            $message['senderName'] = $senderName;
            if ($senderName === null || !$this->access->resourceAllowed($sender, 'message', $msgId)) {
                $message['body'] = null;
            }
            $message['reactions'] = $reactionsByMessage[$msgId] ?? [];

            $rawMediaId = $message['mediaAssetId'] ?? null;
            if ($rawMediaId !== null) {
                $rawMediaKind = $message['mediaKind'] ?? null;
                $rawKind = $message['kind'] ?? null;
                $rawMediaName = $message['mediaName'] ?? null;
                $rawMediaMime = $message['mediaMime'] ?? null;
                $rawMediaSize = $message['mediaSize'] ?? null;
                $rawMediaStatus = $message['mediaStatus'] ?? null;
                $message['media'] = [
                    'id' => (string) $rawMediaId,
                    'kind' => is_string($rawMediaKind) ? $rawMediaKind : (is_string($rawKind) ? $rawKind : 'text'),
                    'name' => is_string($rawMediaName) ? $rawMediaName : '',
                    'mime' => is_string($rawMediaMime) ? $rawMediaMime : '',
                    'size' => (int) ($rawMediaSize ?? 0),
                    'status' => is_string($rawMediaStatus) ? $rawMediaStatus : 'READY',
                ];
            } else {
                $message['media'] = null;
            }

            $rawReplyId = $message['replyToId'] ?? null;
            if ($rawReplyId !== null) {
                $rawReplyBody = $message['replyBody'] ?? null;
                $rawReplySender = $message['replySenderPersonId'] ?? null;
                $message['replyTo'] = [
                    'id' => (string) $rawReplyId,
                    'body' => is_string($rawReplyBody) ? $rawReplyBody : null,
                    'senderPersonId' => is_string($rawReplySender) ? $rawReplySender : null,
                ];
            } else {
                $message['replyTo'] = null;
            }

            $rawMeta = $message['metadataJson'] ?? null;
            $message['metadata'] = is_string($rawMeta) ? (json_decode($rawMeta, true) ?: []) : [];
            unset($message['metadataJson'], $message['mediaKind'], $message['mediaName'], $message['mediaMime'], $message['mediaSize'], $message['mediaStatus'], $message['replyBody'], $message['replySenderPersonId']);
        }
        unset($message);
        $page = self::page($items, $offset);
        $page['items'] = array_reverse(array_slice($items, 0, 50));
        return $page;
    }

    public function startThread(ParentScope $scope, array $input): array
    {
        $recipient = Input::uuid($input['recipientPersonId'] ?? null);
        $subject = Input::text($input['subject'] ?? null, 160);
        $body = Input::text($input['body'] ?? null, 5000);
        $contacts = $this->contacts($scope);
        /** @var list<array<string, mixed>> $contactItems */
        $contactItems = is_array($contacts['items'] ?? null) ? $contacts['items'] : [];
        /** @var list<string> $allowed */
        $allowed = array_column($contactItems, 'personId');
        if (!in_array($recipient, $allowed, true)) {
            throw new EducationError(403, 'recipient_unavailable');
        }
        return $this->db->transaction(function () use ($scope, $recipient, $subject, $body): array {
            $thread = $this->db->createCommand(
                'INSERT INTO education.threads(organization_id,subject,context_type,context_id,last_activity_at)
                VALUES(CAST(:org AS uuid),:subject,\'student\',CAST(:student AS uuid),now())
                RETURNING id::text,subject',
                [':org' => $scope->organizationId, ':subject' => $subject, ':student' => $scope->studentId],
            )->queryOne();
            if (!is_array($thread)) {
                throw new EducationError(500, 'database_error');
            }
            $id = (string) $thread['id'];
            foreach ([$scope->personId, $recipient] as $personId) {
                $this->db->createCommand(
                    'INSERT INTO education.thread_members(thread_id,organization_id,person_id)
                    VALUES(CAST(:id AS uuid),CAST(:org AS uuid),CAST(:person AS uuid))',
                    [':id' => $id, ':org' => $scope->organizationId, ':person' => $personId],
                )->execute();
            }
            $this->sendMessage($scope, $id, ['body' => $body]);
            return $thread;
        });
    }

    public function sendMessage(ParentScope $scope, string $threadId, array $input): array
    {
        $rawKind = $input['kind'] ?? 'text';
        $kind = in_array($rawKind, ['text', 'voice', 'video_note', 'image', 'document', 'audio'], true) ? $rawKind : 'text';
        $mediaAssetId = isset($input['mediaAssetId']) && is_string($input['mediaAssetId']) ? Input::uuid($input['mediaAssetId']) : null;
        $replyToId = isset($input['replyToId']) && is_string($input['replyToId']) ? Input::uuid($input['replyToId']) : null;
        $metadata = isset($input['metadata']) && is_array($input['metadata']) ? $input['metadata'] : [];

        $rawBody = $input['body'] ?? null;
        if ($rawBody === null || trim((string) $rawBody) === '') {
            if ($mediaAssetId === null) {
                throw new EducationError(422, 'invalid_input');
            }
            $body = null;
        } else {
            $body = Input::text((string) $rawBody, 5000);
        }

        $rawKey = $input['clientKey'] ?? null;
        $clientKey = is_string($rawKey) && strlen($rawKey) >= 8 && strlen($rawKey) <= 128 ? $rawKey : null;
        $this->thread($scope, $threadId);
        $peers = $this->all(
            'SELECT peer.person_id::text AS id FROM education.thread_members peer
            WHERE peer.organization_id=CAST(:org AS uuid) AND peer.person_id<>CAST(:person AS uuid) AND peer.thread_id=CAST(:id AS uuid)',
            [':org' => $scope->organizationId, ':person' => $scope->personId, ':id' => $threadId],
        );
        $contacts = $this->contacts($scope);
        /** @var list<array<string, mixed>> $contactItems */
        $contactItems = is_array($contacts['items'] ?? null) ? $contacts['items'] : [];
        /** @var list<string> $allowed */
        $allowed = array_column($contactItems, 'personId');
        /** @var array<string, mixed> $peer */
        foreach ($peers as $peer) {
            $peerId = is_scalar($peer['id'] ?? null) ? (string) $peer['id'] : '';
            if (!in_array($peerId, $allowed, true)) {
                throw new EducationError(403, 'recipient_unavailable');
            }
        }
        return $this->db->transaction(function () use ($scope, $threadId, $body, $clientKey, $kind, $mediaAssetId, $replyToId, $metadata): array {
            $params = [':thread' => $threadId, ':org' => $scope->organizationId, ':person' => $scope->personId];
            // Idempotency: return existing message if same client_key already received
            if ($clientKey !== null) {
                $existing = $this->db->createCommand(
                    'SELECT id::text,sender_person_id::text AS "senderPersonId",body,created_at::text AS "createdAt",
                            kind,media_asset_id::text AS "mediaAssetId",reply_to_id::text AS "replyToId",metadata::text AS "metadataJson"
                     FROM education.messages WHERE thread_id=CAST(:thread AS uuid)
                     AND organization_id=CAST(:org AS uuid) AND sender_person_id=CAST(:person AS uuid)
                     AND client_key=:key',
                    [...$params, ':key' => $clientKey],
                )->queryOne();
                if (is_array($existing)) {
                    $existing['metadata'] = json_decode((string) ($existing['metadataJson'] ?? '{}'), true) ?: [];
                    unset($existing['metadataJson']);
                    $existing['reactions'] = [];
                    return $existing;
                }
            }
            $message = $this->db->createCommand(
                'INSERT INTO education.messages(thread_id,organization_id,sender_person_id,body,client_key,kind,media_asset_id,reply_to_id,metadata)
                VALUES(CAST(:thread AS uuid),CAST(:org AS uuid),CAST(:person AS uuid),:body,:key,:kind,CAST(:media AS uuid),CAST(:reply AS uuid),CAST(:meta AS jsonb))
                RETURNING id::text,sender_person_id::text AS "senderPersonId",body,created_at::text AS "createdAt",kind,media_asset_id::text AS "mediaAssetId",reply_to_id::text AS "replyToId",metadata::text AS "metadataJson"',
                [
                    ...$params,
                    ':body' => $body,
                    ':key' => $clientKey,
                    ':kind' => $kind,
                    ':media' => $mediaAssetId,
                    ':reply' => $replyToId,
                    ':meta' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ],
            )->queryOne();
            if (!is_array($message)) {
                throw new EducationError(500, 'database_error');
            }
            $this->db->createCommand(
                'UPDATE education.threads SET last_activity_at=now() WHERE id=CAST(:thread AS uuid) AND organization_id=CAST(:org AS uuid)',
                [':thread' => $threadId, ':org' => $scope->organizationId],
            )->execute();
            $this->db->createCommand(
                'INSERT INTO education.notifications(organization_id,person_id,thread_id,message_id)
                SELECT organization_id,person_id,thread_id,CAST(:message AS uuid) FROM education.thread_members WHERE thread_id=CAST(:thread AS uuid)
                AND organization_id=CAST(:org AS uuid) AND person_id<>CAST(:person AS uuid)',
                [...$params, ':message' => (string) $message['id']],
            )->execute();
            $message['metadata'] = json_decode((string) ($message['metadataJson'] ?? '{}'), true) ?: [];
            unset($message['metadataJson']);
            $message['reactions'] = [];
            return $message;
        });
    }

    public function toggleReaction(ParentScope $scope, string $threadId, array $input): array
    {
        $messageId = Input::uuid($input['messageId'] ?? null);
        $emoji = Input::text($input['emoji'] ?? null, 16);
        $this->thread($scope, $threadId);
        return $this->db->transaction(function () use ($scope, $threadId, $messageId, $emoji): array {
            $msg = $this->db->createCommand(
                'SELECT id FROM education.messages WHERE id=CAST(:id AS uuid) AND thread_id=CAST(:thread AS uuid) AND organization_id=CAST(:org AS uuid)',
                [':id' => $messageId, ':thread' => $threadId, ':org' => $scope->organizationId],
            )->queryOne();
            if (!is_array($msg)) {
                throw new EducationError(404, 'not_found');
            }
            $existing = $this->db->createCommand(
                'SELECT id FROM education.message_reactions WHERE message_id=CAST(:msg AS uuid) AND person_id=CAST(:person AS uuid) AND emoji=:emoji',
                [':msg' => $messageId, ':person' => $scope->personId, ':emoji' => $emoji],
            )->queryOne();
            if (is_array($existing)) {
                $this->db->createCommand(
                    'DELETE FROM education.message_reactions WHERE id=CAST(:id AS uuid)',
                    [':id' => $existing['id']],
                )->execute();
                $reacted = false;
            } else {
                $this->db->createCommand(
                    'INSERT INTO education.message_reactions(message_id, organization_id, person_id, emoji)
                     VALUES(CAST(:msg AS uuid), CAST(:org AS uuid), CAST(:person AS uuid), :emoji)',
                    [':msg' => $messageId, ':org' => $scope->organizationId, ':person' => $scope->personId, ':emoji' => $emoji],
                )->execute();
                $reacted = true;
            }

            $reactionsRows = $this->all(
                'SELECT emoji, count(*)::int AS count, bool_or(person_id=CAST(:person AS uuid)) AS reacted
                 FROM education.message_reactions
                 WHERE message_id=CAST(:msg AS uuid)
                 GROUP BY emoji ORDER BY count DESC, emoji',
                [':msg' => $messageId, ':person' => $scope->personId],
            );

            return [
                'reacted' => $reacted,
                'reactions' => array_map(static fn(array $r): array => [
                    'emoji' => is_scalar($r['emoji'] ?? null) ? (string) $r['emoji'] : '',
                    'count' => (int) ($r['count'] ?? 0),
                    'reacted' => (bool) ($r['reacted'] ?? false),
                ], $reactionsRows),
            ];
        });
    }

    public function readThread(ParentScope $scope, string $threadId, array $input): array
    {
        $lastSeenMessageId = Input::uuid($input['lastSeenMessageId'] ?? null);
        $this->thread($scope, $threadId);
        $this->db->transaction(function () use ($scope, $threadId, $lastSeenMessageId): void {
            $params = [':org' => $scope->organizationId, ':person' => $scope->personId, ':id' => $threadId, ':message' => $lastSeenMessageId];
            $target = $this->db->createCommand(
                'SELECT m.id::text,m.created_at::text AS "createdAt" FROM education.messages m
                JOIN education.thread_members tm ON tm.thread_id=m.thread_id WHERE m.organization_id=CAST(:org AS uuid)
                AND tm.person_id=CAST(:person AS uuid) AND m.thread_id=CAST(:id AS uuid) AND m.id=CAST(:message AS uuid)',
                $params,
            )->queryOne();
            if (!is_array($target)) {
                throw new EducationError(404, 'not_found');
            }
            $params[':at'] = (string) $target['createdAt'];
            $this->db->createCommand(
                "UPDATE education.thread_members SET
                last_read_message_id=CASE WHEN last_read_at IS NULL OR (last_read_at,COALESCE(last_read_message_id,'ffffffff-ffff-ffff-ffff-ffffffffffff'::uuid))<(CAST(:at AS timestamptz),CAST(:message AS uuid)) THEN CAST(:message AS uuid) ELSE last_read_message_id END,
                last_read_at=GREATEST(last_read_at,CAST(:at AS timestamptz))
                WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND thread_id=CAST(:id AS uuid)",
                $params,
            )->execute();
            $this->db->createCommand(
                'UPDATE education.notifications n SET read_at=now() WHERE n.organization_id=CAST(:org AS uuid)
                AND n.person_id=CAST(:person AS uuid) AND n.thread_id=CAST(:id AS uuid) AND n.read_at IS NULL AND
                ((n.message_id IS NULL AND n.created_at<=CAST(:at AS timestamptz)) OR EXISTS(SELECT 1 FROM education.messages m
                    WHERE m.id=n.message_id AND (m.created_at,m.id)<=(CAST(:at AS timestamptz),CAST(:message AS uuid))))',
                $params,
            )->execute();
        });
        return ['updated' => true];
    }
}
