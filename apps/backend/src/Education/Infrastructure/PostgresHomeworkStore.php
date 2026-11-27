<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\EducationError;
use App\Education\Application\HomeworkStore;
use App\Education\Application\Scope;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresHomeworkStore implements HomeworkStore
{
    public function __construct(private ConnectionInterface $db) {}

    public function transaction(callable $action): array
    {
        return $this->db->transaction($action);
    }

    private const ASSIGNMENT = 'h.id::text,h.organization_id::text AS "organizationId",h.author_account_id::text AS "authorAccountId",
        h.author_person_id::text AS "authorPersonId",h.title,h.description,h.instructions,h.type,h.group_id::text AS "groupId",
        h.lesson_id::text AS "lessonId",h.due_at::text AS "dueAt",h.allow_late AS "allowLate",h.activity_code AS "activityCode",
        h.activity_config::text AS "activityConfig",h.max_attempts AS "maxAttempts",h.attempt_policy AS "attemptPolicy",
        h.status,h.version,h.created_at::text AS "createdAt",h.published_at::text AS "publishedAt",h.closed_at::text AS "closedAt",h.archived_at::text AS "archivedAt"';
    private const RECIPIENT = 'r.id::text,r.assignment_id::text AS "assignmentId",r.organization_id::text AS "organizationId",
        r.student_id::text AS "studentId",r.person_id::text AS "personId",r.assigned_at::text AS "assignedAt",
        r.activity_assignment_id::text AS "activityAssignmentId"';
    private const TEACHER = '(h.author_account_id=CAST(:actor AS uuid) OR (h.group_id IS NOT NULL AND education.can_teach_group(h.organization_id,CAST(:actor AS uuid),h.group_id)))';

    public function assignment(Scope $scope, string $id, bool $lock = false): array
    {
        return $this->required(
            'SELECT ' . self::ASSIGNMENT . ' FROM education.homework_assignments h
            WHERE h.organization_id=CAST(:org AS uuid) AND h.id=CAST(:id AS uuid)' . ($lock ? ' FOR UPDATE OF h' : ''),
            [':org' => $scope->organizationId, ':id' => $id],
        );
    }

    public function teacherAssignment(Scope $scope, string $id, bool $lock = false): array
    {
        return $this->required(
            'SELECT ' . self::ASSIGNMENT . ' FROM education.homework_assignments h
            WHERE h.organization_id=CAST(:org AS uuid) AND h.id=CAST(:id AS uuid) AND ' . self::TEACHER . ($lock ? ' FOR UPDATE OF h' : ''),
            [...self::teacher($scope), ':id' => $id],
        );
    }

    public function studentRecipient(Scope $scope, string $assignmentId, bool $lock = false): array
    {
        return $this->required(
            'SELECT ' . self::RECIPIENT . ' FROM education.homework_recipients r
            JOIN education.homework_assignments h ON h.id=r.assignment_id AND h.organization_id=r.organization_id
            WHERE r.organization_id=CAST(:org AS uuid) AND r.assignment_id=CAST(:id AS uuid)
            AND r.student_id=CAST(:student AS uuid) AND r.person_id=CAST(:person AS uuid) AND h.status<>\'DRAFT\'' . ($lock ? ' FOR UPDATE OF r' : ''),
            [':org' => $scope->organizationId, ':id' => $assignmentId, ':student' => $scope->studentId, ':person' => $scope->personId],
        );
    }

    public function recipient(Scope $scope, string $id, bool $lock = false): array
    {
        return $this->required('SELECT ' . self::RECIPIENT . ' FROM education.homework_recipients r
            WHERE r.organization_id=CAST(:org AS uuid) AND r.id=CAST(:id AS uuid)' . ($lock ? ' FOR UPDATE OF r' : ''), [':org' => $scope->organizationId, ':id' => $id]);
    }

    public function submissionRecipient(Scope $scope, string $submissionId): array
    {
        return $this->required('SELECT ' . self::RECIPIENT . ' FROM education.homework_recipients r
            JOIN education.homework_submissions s ON s.recipient_id=r.id AND s.organization_id=r.organization_id
            WHERE s.organization_id=CAST(:org AS uuid) AND s.id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $submissionId]);
    }

    public function submission(Scope $scope, array $recipient, bool $lock = false): array
    {
        $row = $this->one(
            'SELECT s.id::text,s.recipient_id::text AS "recipientId",s.state,s.version,s.draft_text AS text,
            s.created_at::text AS "createdAt",s.updated_at::text AS "updatedAt"
            FROM education.homework_submissions s WHERE s.organization_id=CAST(:org AS uuid) AND s.recipient_id=CAST(:recipient AS uuid)' . ($lock ? ' FOR UPDATE OF s' : ''),
            [':org' => $scope->organizationId, ':recipient' => $recipient['id']],
        );
        $submission = $row ?? ['id' => null, 'recipientId' => $recipient['id'], 'state' => 'NOT_STARTED', 'version' => 0, 'text' => '', 'createdAt' => null, 'updatedAt' => null];
        $submission += ['studentId' => $recipient['studentId'], 'personId' => $recipient['personId'], 'versions' => [], 'reviews' => [], 'currentVersion' => null,
            'files' => [], 'selectedActivityAttemptId' => null, 'activityResult' => null];
        if ($row === null) {
            return $submission;
        }
        $params = [':org' => $scope->organizationId, ':id' => $row['id']];
        $submission['versions'] = $this->all('SELECT id::text,sequence,assignment_version AS "assignmentVersion",assignment_snapshot::text AS "assignmentSnapshot",
            text,activity_attempt_id::text AS "activityAttemptId",submitted_at::text AS "submittedAt",late FROM education.homework_submission_versions
            WHERE organization_id=CAST(:org AS uuid) AND submission_id=CAST(:id AS uuid) ORDER BY sequence', $params);
        foreach ($submission['versions'] as &$version) {
            $version['files'] = [];
        }
        unset($version);
        $submission['currentVersion'] = $submission['versions'] === [] ? null : $submission['versions'][array_key_last($submission['versions'])];
        $submission['reviews'] = $this->all('SELECT id::text,submission_version_id::text AS "submissionVersionId",teacher_account_id::text AS "teacherAccountId",
            decision,result_status AS "resultStatus",comment,created_at::text AS "createdAt" FROM education.homework_reviews
            WHERE organization_id=CAST(:org AS uuid) AND submission_id=CAST(:id AS uuid) ORDER BY created_at,id', $params);
        return $submission;
    }

    public function assignments(Scope $scope, array $filters, int $offset, string $now): array
    {
        [$where, $params] = $this->where($scope, $filters, $now, false);
        $order = self::order($filters);
        return $this->all('SELECT ' . self::ASSIGNMENT . ',
            (SELECT count(*)::int FROM education.homework_recipients rc WHERE rc.assignment_id=h.id) AS assigned,
            (SELECT count(*)::int FROM education.homework_recipients rc JOIN education.homework_submissions ss ON ss.recipient_id=rc.id WHERE rc.assignment_id=h.id AND ss.state IN (\'SUBMITTED\',\'RESUBMITTED\',\'REVIEWED\',\'RETURNED\')) AS submitted,
            (SELECT count(*)::int FROM education.homework_recipients rc JOIN education.homework_submissions ss ON ss.recipient_id=rc.id WHERE rc.assignment_id=h.id AND ss.state IN (\'SUBMITTED\',\'RESUBMITTED\')) AS "pendingReview",
            (SELECT count(*)::int FROM education.homework_recipients rc JOIN education.homework_submissions ss ON ss.recipient_id=rc.id WHERE rc.assignment_id=h.id AND ss.state=\'RETURNED\') AS returned,
            (SELECT count(*)::int FROM education.homework_recipients rc JOIN education.homework_submissions ss ON ss.recipient_id=rc.id WHERE rc.assignment_id=h.id AND ss.state=\'REVIEWED\') AS completed,
            (h.status=\'PUBLISHED\' AND h.due_at<CAST(:now AS timestamptz)) AS overdue
            FROM education.homework_assignments h WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 51 OFFSET :offset', [...$params, ':offset' => $offset]);
    }

    public function studentAssignments(Scope $scope, array $filters, int $offset, string $now): array
    {
        [$where, $params] = $this->where($scope, $filters, $now, true);
        return $this->all('SELECT ' . self::ASSIGNMENT . ',r.id::text AS "recipientId",s.id::text AS "submissionId",COALESCE(s.version,0) AS "submissionVersion",
            COALESCE(s.state,\'NOT_STARTED\') AS state,sv.submitted_at::text AS "submittedAt",COALESCE(sv.late,false) AS late,rv.result_status AS "resultStatus",
            (h.status=\'PUBLISHED\' AND h.due_at<CAST(:now AS timestamptz) AND COALESCE(s.state,\'NOT_STARTED\') IN (\'NOT_STARTED\',\'IN_PROGRESS\',\'RETURNED\')) AS overdue
            FROM education.homework_assignments h JOIN education.homework_recipients r ON r.assignment_id=h.id AND r.organization_id=h.organization_id
            LEFT JOIN education.homework_submissions s ON s.recipient_id=r.id
            LEFT JOIN LATERAL(SELECT submitted_at,late,id FROM education.homework_submission_versions WHERE submission_id=s.id ORDER BY sequence DESC LIMIT 1) sv ON true
            LEFT JOIN education.homework_reviews rv ON rv.submission_version_id=sv.id
            WHERE ' . $where . ' ORDER BY ' . (($filters['sort'] ?? '') === 'reviewedAt' ? 'rv.created_at DESC NULLS LAST,h.id DESC' : self::order($filters)) . ' LIMIT 51 OFFSET :offset', [...$params, ':offset' => $offset]);
    }

    public function recipients(Scope $scope, string $assignmentId, int $offset): array
    {
        return $this->all(
            'SELECT r.id::text AS "recipientId",r.student_id::text AS "studentId",r.person_id::text AS "personId",s.id::text AS "submissionId",
            COALESCE(s.state,\'NOT_STARTED\') AS state,COALESCE(s.version,0) AS version,sv.submitted_at::text AS "submittedAt",COALESCE(sv.late,false) AS late,rv.result_status AS "resultStatus"
            FROM education.homework_recipients r LEFT JOIN education.homework_submissions s ON s.recipient_id=r.id
            LEFT JOIN LATERAL(SELECT id,submitted_at,late FROM education.homework_submission_versions WHERE submission_id=s.id ORDER BY sequence DESC LIMIT 1) sv ON true
            LEFT JOIN education.homework_reviews rv ON rv.submission_version_id=sv.id
            WHERE r.organization_id=CAST(:org AS uuid) AND r.assignment_id=CAST(:id AS uuid) ORDER BY r.assigned_at,r.id LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':id' => $assignmentId, ':offset' => $offset],
        );
    }

    public function summary(Scope $scope, array $filters, string $now, ?string $assignmentId = null): array
    {
        $student = $scope->studentId !== null;
        [$where, $params] = $this->where($scope, $filters, $now, $student);
        if ($assignmentId !== null) {
            $where .= ' AND h.id=CAST(:assignment AS uuid)';
            $params[':assignment'] = $assignmentId;
        } else {
            $where .= ' AND h.status<>\'DRAFT\'';
        }
        if (isset($filters['studentId']) && !$student) {
            $where .= ' AND r.student_id=CAST(:filterStudent AS uuid)';
            $params[':filterStudent'] = $filters['studentId'];
        }
        return $this->required('SELECT count(*)::int AS assigned,
            count(*) FILTER(WHERE s.state=\'REVIEWED\')::int AS completed,
            count(*) FILTER(WHERE s.state IN (\'SUBMITTED\',\'RESUBMITTED\'))::int AS "pendingReview",
            count(*) FILTER(WHERE s.state=\'RETURNED\')::int AS returned,
            count(*) FILTER(WHERE s.id IS NULL)::int AS "notStarted",count(*) FILTER(WHERE s.state=\'IN_PROGRESS\')::int AS "inProgress",
            count(*) FILTER(WHERE h.status=\'PUBLISHED\' AND h.due_at<CAST(:now AS timestamptz) AND COALESCE(s.state,\'NOT_STARTED\') IN (\'NOT_STARTED\',\'IN_PROGRESS\',\'RETURNED\'))::int AS overdue
            FROM education.homework_assignments h JOIN education.homework_recipients r ON r.assignment_id=h.id AND r.organization_id=h.organization_id
            LEFT JOIN education.homework_submissions s ON s.recipient_id=r.id WHERE ' . $where, $params);
    }

    private function where(Scope $scope, array $filters, string $now, bool $student): array
    {
        $params = [':org' => $scope->organizationId, ':now' => $now];
        $where = 'h.organization_id=CAST(:org AS uuid)';
        if ($student) {
            $where .= ' AND h.status<>\'DRAFT\' AND r.student_id=CAST(:student AS uuid) AND r.person_id=CAST(:person AS uuid)';
            $params += [':student' => $scope->studentId, ':person' => $scope->personId];
        } else {
            $where .= ' AND ' . self::TEACHER;
            $params[':actor'] = $scope->actor();
        }
        foreach (['status' => 'h.status', 'groupId' => 'h.group_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $where .= ' AND ' . $column . '=' . ($key === 'groupId' ? 'CAST(:groupId AS uuid)' : ':status');
                $params[':' . $key] = $filters[$key];
            }
        }
        if (isset($filters['search'])) {
            $where .= ' AND (h.title ILIKE :search OR h.description ILIKE :search)';
            $params[':search'] = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $filters['search']) . '%';
        }
        if (isset($filters['studentId']) && !$student) {
            $where .= ' AND EXISTS(SELECT 1 FROM education.homework_recipients fr WHERE fr.assignment_id=h.id AND fr.student_id=CAST(:studentFilter AS uuid))';
            $params[':studentFilter'] = $filters['studentId'];
        }
        if ($student && isset($filters['state'])) {
            $where .= ' AND COALESCE(s.state,\'NOT_STARTED\')=:state';
            $params[':state'] = $filters['state'];
        }
        if (isset($filters['overdue'])) {
            $condition = '(h.status=\'PUBLISHED\' AND h.due_at<CAST(:now AS timestamptz))';
            if ($student) {
                $condition = '(h.status=\'PUBLISHED\' AND h.due_at<CAST(:now AS timestamptz) AND COALESCE(s.state,\'NOT_STARTED\') IN (\'NOT_STARTED\',\'IN_PROGRESS\',\'RETURNED\'))';
            }
            $where .= ' AND ' . ($filters['overdue'] ? '' : 'NOT ') . $condition;
        }
        // Some queries only need clock for SELECT aggregates; always bind it through a true predicate.
        $where .= ' AND CAST(:now AS timestamptz) IS NOT NULL';
        return [$where, $params];
    }

    private static function order(array $filters): string
    {
        return match ($filters['sort'] ?? 'dueAt') {
            'createdAt','assignedAt','newest' => 'h.created_at DESC,h.id DESC',
            'oldest' => 'h.created_at,h.id', default => 'h.due_at ASC NULLS LAST,h.created_at DESC,h.id DESC',
        };
    }

    public function createAssignment(Scope $scope, array $values, string $now): string
    {
        return $this->required(
            'INSERT INTO education.homework_assignments(organization_id,author_account_id,author_person_id,title,description,instructions,type,
            group_id,lesson_id,due_at,allow_late,activity_code,activity_config,max_attempts,attempt_policy,created_at)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:title,:description,:instructions,:type,CAST(:groupId AS uuid),CAST(:lessonId AS uuid),
            CAST(:dueAt AS timestamptz),CAST(:allowLate AS boolean),:activityCode,CAST(:activityConfig AS jsonb),:maxAttempts,:attemptPolicy,CAST(:now AS timestamptz)) RETURNING id::text',
            [...self::teacher($scope), ':person' => $scope->personId, ':now' => $now, ...self::values($values)],
        )['id'];
    }

    public function updateAssignment(Scope $scope, string $id, array $values): void
    {
        $this->execute('UPDATE education.homework_assignments SET title=:title,description=:description,instructions=:instructions,type=:type,
            group_id=CAST(:groupId AS uuid),lesson_id=CAST(:lessonId AS uuid),due_at=CAST(:dueAt AS timestamptz),allow_late=CAST(:allowLate AS boolean),
            activity_code=:activityCode,activity_config=CAST(:activityConfig AS jsonb),max_attempts=:maxAttempts,attempt_policy=:attemptPolicy,version=version+1
            WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $id, ...self::values($values)]);
    }

    private static function values(array $values): array
    {
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = match ($key) {
                'activityConfig' => json_encode((object) $value, JSON_THROW_ON_ERROR), 'allowLate' => $value ? 'true' : 'false', default => $value,
            };
        }
        return $params;
    }

    public function setStatus(Scope $scope, string $id, string $status, string $now): void
    {
        $column = match ($status) {
            'PUBLISHED' => 'published_at', 'CLOSED' => 'closed_at', 'ARCHIVED' => 'archived_at',
        };
        $this->execute('UPDATE education.homework_assignments SET status=:status,version=version+1,' . $column . '=CAST(:now AS timestamptz)
            WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $id, ':status' => $status, ':now' => $now]);
    }

    public function bumpAssignment(Scope $scope, string $id): void
    {
        $this->execute('UPDATE education.homework_assignments SET version=version+1 WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $id]);
    }

    public function addRecipients(Scope $scope, string $id, array $students, string $now): void
    {
        foreach ($students as $student) {
            $this->execute(
                'INSERT INTO education.homework_recipients(assignment_id,organization_id,student_id,person_id,assigned_at)
            VALUES(CAST(:id AS uuid),CAST(:org AS uuid),CAST(:student AS uuid),CAST(:person AS uuid),CAST(:now AS timestamptz)) ON CONFLICT(assignment_id,student_id) DO NOTHING',
                [':id' => $id, ':org' => $scope->organizationId, ':student' => $student['id'], ':person' => $student['personId'], ':now' => $now],
            );
        }
    }

    public function groupRecipients(Scope $scope, string $groupId): array
    {
        return $this->all('SELECT s.id::text,s.person_id::text AS "personId" FROM education.group_students gs JOIN education.students s ON s.id=gs.student_id AND s.organization_id=gs.organization_id
            WHERE gs.organization_id=CAST(:org AS uuid) AND gs.group_id=CAST(:group AS uuid) AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)
            ORDER BY s.id FOR SHARE OF gs,s', [...self::teacher($scope), ':group' => $groupId]);
    }

    public function replaceDraftRecipients(Scope $scope, string $id, array $students, string $now): void
    {
        $this->execute(
            'DELETE FROM education.homework_recipients r USING education.homework_assignments h
            WHERE r.assignment_id=h.id AND h.id=CAST(:id AS uuid) AND h.organization_id=CAST(:org AS uuid) AND h.status=\'DRAFT\'',
            [':id' => $id, ':org' => $scope->organizationId],
        );
        $this->addRecipients($scope, $id, $students, $now);
    }

    public function revision(Scope $scope, array $assignment, string $action, string $now): void
    {
        $snapshot = $assignment;
        $snapshot['studentIds'] = array_column($this->all(
            'SELECT student_id::text AS id FROM education.homework_recipients WHERE organization_id=CAST(:org AS uuid) AND assignment_id=CAST(:id AS uuid) ORDER BY student_id',
            [':org' => $scope->organizationId, ':id' => $assignment['id']],
        ), 'id');
        $this->execute(
            'INSERT INTO education.homework_revisions(assignment_id,organization_id,version,action,payload,actor_account_id,created_at)
            VALUES(CAST(:id AS uuid),CAST(:org AS uuid),:version,:action,CAST(:payload AS jsonb),CAST(:actor AS uuid),CAST(:now AS timestamptz))',
            [...self::teacher($scope), ':id' => $assignment['id'], ':version' => $assignment['version'], ':action' => $action, ':payload' => json_encode($snapshot, JSON_THROW_ON_ERROR), ':now' => $now],
        );
    }

    public function history(Scope $scope, string $id, int $offset): array
    {
        return $this->all(
            'SELECT version,action,payload::text,actor_account_id::text AS "actorAccountId",created_at::text AS "createdAt"
            FROM education.homework_revisions WHERE organization_id=CAST(:org AS uuid) AND assignment_id=CAST(:id AS uuid) ORDER BY version DESC LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':id' => $id, ':offset' => $offset],
        );
    }

    public function saveDraft(Scope $scope, array $recipient, array $submission, string $text, string $now): void
    {
        if ($submission['id'] === null) {
            $this->execute(
                'INSERT INTO education.homework_submissions(recipient_id,organization_id,draft_text,created_at,updated_at)
                VALUES(CAST(:recipient AS uuid),CAST(:org AS uuid),:text,CAST(:now AS timestamptz),CAST(:now AS timestamptz))',
                [':recipient' => $recipient['id'], ':org' => $scope->organizationId, ':text' => $text, ':now' => $now],
            );
        } else {
            $this->execute('UPDATE education.homework_submissions SET draft_text=:text,version=version+1,updated_at=CAST(:now AS timestamptz)
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)', [':id' => $submission['id'], ':org' => $scope->organizationId, ':text' => $text, ':now' => $now]);
        }
    }

    public function submit(Scope $scope, array $assignment, array $submission, ?string $attemptId, string $key, string $now, bool $late): void
    {
        $sequence = count($submission['versions']) + 1;
        $this->execute(
            'INSERT INTO education.homework_submission_versions(submission_id,organization_id,sequence,assignment_version,assignment_snapshot,text,activity_attempt_id,submitted_at,late,idempotency_key)
            VALUES(CAST(:id AS uuid),CAST(:org AS uuid),:sequence,:assignmentVersion,CAST(:snapshot AS jsonb),:text,CAST(:attempt AS uuid),CAST(:now AS timestamptz),CAST(:late AS boolean),CAST(:key AS uuid))',
            [':id' => $submission['id'], ':org' => $scope->organizationId, ':sequence' => $sequence, ':assignmentVersion' => $assignment['version'],
                ':snapshot' => json_encode($assignment, JSON_THROW_ON_ERROR), ':text' => $submission['text'], ':attempt' => $attemptId, ':now' => $now, ':late' => $late ? 'true' : 'false', ':key' => $key],
        );
        $this->execute(
            'UPDATE education.homework_submissions SET state=:state,version=version+1,updated_at=CAST(:now AS timestamptz)
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)',
            [':state' => $sequence === 1 ? 'SUBMITTED' : 'RESUBMITTED', ':now' => $now, ':id' => $submission['id'], ':org' => $scope->organizationId],
        );
    }

    public function review(Scope $scope, array $submission, array $values, string $now): void
    {
        $this->execute(
            'INSERT INTO education.homework_reviews(submission_id,submission_version_id,organization_id,teacher_account_id,decision,result_status,comment,created_at,idempotency_key)
            VALUES(CAST(:id AS uuid),CAST(:versionId AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid),:decision,:resultStatus,:comment,CAST(:now AS timestamptz),CAST(:key AS uuid))',
            [...self::teacher($scope), ':id' => $submission['id'], ':versionId' => $submission['currentVersion']['id'], ':decision' => $values['decision'], ':resultStatus' => $values['resultStatus'], ':comment' => $values['comment'], ':now' => $now, ':key' => $values['idempotencyKey']],
        );
        $this->execute(
            'UPDATE education.homework_submissions SET state=:state,version=version+1,updated_at=CAST(:now AS timestamptz)
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)',
            [':state' => $values['decision'] === 'accepted' ? 'REVIEWED' : 'RETURNED', ':now' => $now, ':id' => $submission['id'], ':org' => $scope->organizationId],
        );
    }

    public function replay(Scope $scope, string $operation, string $key, string $id, array $request): ?array
    {
        // Serializes the key too, including malicious reuse across different assignments.
        $params = [...self::teacher($scope), ':operation' => $operation, ':key' => $key];
        $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:lock,0))', [':lock' => $scope->organizationId . ':' . $scope->actor() . ':' . $operation . ':' . $key])->queryScalar();
        $row = $this->one('SELECT resource_id::text AS "resourceId",request::text,response::text FROM education.homework_idempotency
            WHERE organization_id=CAST(:org AS uuid) AND actor_account_id=CAST(:actor AS uuid) AND operation=:operation AND idempotency_key=CAST(:key AS uuid)', $params);
        if ($row === null) {
            return null;
        }
        if ($row['resourceId'] !== $id || self::canonical($row['request']) !== self::canonical($request)) {
            throw new EducationError(409, 'idempotency_conflict');
        }
        return $row['response'];
    }

    public function remember(Scope $scope, string $operation, string $key, string $id, array $request, array $response): void
    {
        $this->execute(
            'INSERT INTO education.homework_idempotency(organization_id,actor_account_id,operation,idempotency_key,resource_id,request,response)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),:operation,CAST(:key AS uuid),CAST(:id AS uuid),CAST(:request AS jsonb),CAST(:response AS jsonb))',
            [...self::teacher($scope), ':operation' => $operation, ':key' => $key, ':id' => $id, ':request' => json_encode($request, JSON_THROW_ON_ERROR), ':response' => json_encode($response, JSON_THROW_ON_ERROR)],
        );
    }

    private static function canonical(array $input): string
    {
        ksort($input);
        return json_encode($input, JSON_THROW_ON_ERROR);
    }

    private static function teacher(Scope $scope): array
    {
        return [':org' => $scope->organizationId, ':actor' => $scope->actor()];
    }
    private function execute(string $sql, array $params): void
    {
        $this->db->createCommand($sql, $params)->execute();
    }
    private function all(string $sql, array $params): array
    {
        return array_map(self::normalize(...), $this->db->createCommand($sql, $params)->queryAll());
    }
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        return is_array($row) ? self::normalize($row) : null;
    }
    private function required(string $sql, array $params): array
    {
        return $this->one($sql, $params) ?? throw new EducationError(404, 'not_found');
    }
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['activityConfig','assignmentSnapshot','payload','request','response'], true) && is_string($value)) {
                $row[$key] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if ($key === 'activityConfig' && $row[$key] === []) {
                    $row[$key] = (object) [];
                } elseif (in_array($key, ['assignmentSnapshot','payload','response'], true)) {
                    $row[$key] = self::restoreObjects($row[$key]);
                }
            } elseif ($key === 'overdue') {
                $row[$key] = is_bool($value) ? $value : (in_array($value, ['t','f'], true) ? $value === 't' : (int) $value);
            } elseif (in_array($key, ['allowLate','late'], true)) {
                $row[$key] = in_array($value, [true,1,'1','t'], true);
            } elseif (in_array($key, ['version','sequence','assignmentVersion','submissionVersion','maxAttempts','assigned','submitted','pendingReview','returned','completed','notStarted','inProgress'], true)) {
                $row[$key] = (int) $value;
            } elseif ($value !== null && in_array($key, ['dueAt','createdAt','publishedAt','closedAt','archivedAt','assignedAt','submittedAt','updatedAt'], true)) {
                $row[$key] = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            }
        }
        return $row;
    }

    /** JSON object identity matters to clients, including frozen and replayed DTOs. */
    private static function restoreObjects(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'activityConfig' && $item === []) {
                $value[$key] = (object) [];
            } elseif (is_array($item)) {
                $value[$key] = self::restoreObjects($item);
            }
        }
        return $value;
    }
}
