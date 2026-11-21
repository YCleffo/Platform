<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\EducationError;
use App\Education\Application\EducationStore;
use App\Education\Application\Scope;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresEducationStore implements EducationStore
{
    private const string LESSON = 'SELECT l.id::text, l.group_id::text AS "groupId", g.name AS "groupName", l.title,
        l.starts_at::text AS "startsAt", l.ends_at::text AS "endsAt", l.location, l.status,
        l.completed_at::text AS "completedAt", l.cancelled_at::text AS "cancelledAt",
        l.curriculum_lesson_id::text AS "curriculumLessonId",l.group_program_id::text AS "groupProgramId",
        l.curriculum_snapshot::text AS "curriculumSnapshot",
        (SELECT count(*) FROM education.attendance a WHERE a.lesson_id=l.id) AS "studentCount"
        FROM education.lessons l JOIN education.groups g ON g.id=l.group_id WHERE ';

    public function __construct(private ConnectionInterface $db) {}

    public function teacherOrganizations(string $accountId, string $personId): array
    {
        $rows = $this->all(
            'SELECT organization_id::text AS id FROM education.teachers
            WHERE account_id=CAST(:account AS uuid) AND person_id=CAST(:person AS uuid) ORDER BY organization_id',
            [':account' => $accountId, ':person' => $personId],
        );
        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }

    public function teacherExists(Scope $scope): bool
    {
        return $this->one(
            'SELECT id::text FROM education.teachers WHERE organization_id=CAST(:org AS uuid)
            AND account_id=CAST(:actor AS uuid) AND person_id=CAST(:person AS uuid)',
            [...self::owner($scope), ':person' => $scope->personId],
        ) !== null;
    }

    public function grantTeacher(string $organizationId, string $accountId, string $personId): void
    {
        $this->db->createCommand(
            'INSERT INTO education.teachers(organization_id,account_id,person_id)
            VALUES(CAST(:org AS uuid),CAST(:account AS uuid),CAST(:person AS uuid))
            ON CONFLICT(organization_id,account_id) DO NOTHING',
            [':org' => $organizationId, ':account' => $accountId, ':person' => $personId],
        )->execute();
    }

    public function groups(Scope $scope, int $offset): array
    {
        return $this->all('SELECT g.id::text,g.name,g.archived_at::text AS "archivedAt",(SELECT count(*) FROM education.group_students gs
            WHERE gs.group_id=g.id) AS "studentCount" FROM education.groups g WHERE ' . self::where('g') . '
            ORDER BY (g.archived_at IS NOT NULL),g.created_at,g.id LIMIT 51 OFFSET :offset', [...self::owner($scope), ':offset' => $offset]);
    }

    public function group(Scope $scope, string $id): ?array
    {
        return $this->one('SELECT g.id::text,g.name,g.archived_at::text AS "archivedAt",
            (SELECT count(*) FROM education.group_students gs WHERE gs.group_id=g.id) AS "studentCount"
            FROM education.groups g WHERE ' . self::where('g') . '
            AND g.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function createGroup(Scope $scope, string $name): array
    {
        $row = $this->required(
            'INSERT INTO education.groups(organization_id,owner_account_id,owner_person_id,name)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:name)
            RETURNING id::text,name,archived_at::text AS "archivedAt"',
            [...self::owner($scope), ':person' => $scope->personId, ':name' => $name],
        );
        return [...$row, 'studentCount' => 0];
    }

    public function updateGroup(Scope $scope, string $id, string $name): array
    {
        return $this->db->transaction(function () use ($scope, $id, $name): array {
            $this->lockGroup($scope, $id);
            $current = $this->one('SELECT g.id::text,g.archived_at::text AS "archivedAt" FROM education.groups g
                WHERE ' . self::where('g') . ' AND g.id=CAST(:id AS uuid) FOR UPDATE', [...self::owner($scope), ':id' => $id]);
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $this->required(
                'UPDATE education.groups g SET name=:name WHERE ' . self::where('g') . '
                AND g.id=CAST(:id AS uuid) RETURNING g.id::text',
                [...self::owner($scope), ':id' => $id, ':name' => $name],
            );
            return $this->group($scope, $id) ?? throw new EducationError(404, 'not_found');
        });
    }

    public function archiveGroup(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $this->lockGroup($scope, $id);
            $current = $this->one('SELECT g.id::text,g.archived_at::text AS "archivedAt" FROM education.groups g
                WHERE ' . self::where('g') . ' AND g.id=CAST(:id AS uuid) FOR UPDATE', [...self::owner($scope), ':id' => $id]);
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['archivedAt'] !== null) {
                return $this->group($scope, $id) ?? throw new EducationError(404, 'not_found');
            }
            $scheduled = (int) $this->db->createCommand(
                "SELECT count(*) FROM education.lessons l WHERE " . self::where('l')
                . " AND l.group_id=CAST(:id AS uuid) AND l.status='scheduled'",
                [...self::owner($scope), ':id' => $id],
            )->queryScalar();
            if ($scheduled > 0) {
                throw new EducationError(409, 'group_has_scheduled_lessons');
            }
            $this->required(
                'UPDATE education.groups g SET archived_at=now() WHERE ' . self::where('g') . '
                AND g.id=CAST(:id AS uuid) RETURNING g.id::text',
                [...self::owner($scope), ':id' => $id],
            );
            return $this->group($scope, $id) ?? throw new EducationError(404, 'not_found');
        });
    }

    public function groupStudents(Scope $scope, string $groupId, int $offset): array
    {
        return $this->all(
            'SELECT s.id::text,s.person_id::text AS "personId",s.created_at::text AS "createdAt"
            FROM education.group_students gs JOIN education.students s ON s.id=gs.student_id
            WHERE gs.organization_id=CAST(:org AS uuid) AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)
            AND gs.group_id=CAST(:group AS uuid) ORDER BY s.created_at,s.id LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':group' => $groupId, ':offset' => $offset],
        );
    }

    public function students(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT s.id::text,s.person_id::text AS "personId",s.created_at::text AS "createdAt"
            FROM education.students s WHERE ' . self::where('s') . ' ORDER BY s.created_at,s.id LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':offset' => $offset],
        );
    }

    public function enrollStudent(Scope $scope, string $personId, string $groupId): array
    {
        return $this->db->transaction(function () use ($scope, $personId, $groupId): array {
            $this->lockGroup($scope, $groupId);
            $group = $this->group($scope, $groupId);
            if ($group === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($group['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $student = $this->required(
                'INSERT INTO education.students(organization_id,owner_account_id,person_id)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid))
                ON CONFLICT(organization_id,owner_account_id,person_id) DO UPDATE SET person_id=EXCLUDED.person_id
                RETURNING id::text,person_id::text AS "personId"',
                [...self::owner($scope), ':person' => $personId],
            );
            $params = [...self::owner($scope), ':student' => (string) $student['id'], ':group' => $groupId];
            $this->db->createCommand('INSERT INTO education.group_students(group_id,student_id,organization_id,owner_account_id)
                VALUES(CAST(:group AS uuid),CAST(:student AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid)) ON CONFLICT DO NOTHING', $params)->execute();
            $this->addFutureAttendance($scope, (string) $student['id'], $groupId);
            return $student;
        });
    }

    public function removeGroupStudent(Scope $scope, string $groupId, string $studentId): void
    {
        $this->db->transaction(function () use ($scope, $groupId, $studentId): void {
            $this->lockGroup($scope, $groupId);
            $group = $this->group($scope, $groupId);
            if ($group === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($group['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $this->required(
                'SELECT s.id::text FROM education.students s WHERE ' . self::where('s') . ' AND s.id=CAST(:student AS uuid)',
                [...self::owner($scope), ':student' => $studentId],
            );
            $this->db->createCommand(
                'DELETE FROM education.group_students gs WHERE gs.organization_id=CAST(:org AS uuid)
                AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id) AND gs.group_id=CAST(:group AS uuid)
                AND gs.student_id=CAST(:student AS uuid)',
                [...self::owner($scope), ':group' => $groupId, ':student' => $studentId],
            )->execute();
            $this->deleteFutureAttendance($scope, $studentId, $groupId);
        });
    }

    public function transferStudent(Scope $scope, string $sourceGroupId, string $targetGroupId, string $studentId): void
    {
        $this->db->transaction(function () use ($scope, $sourceGroupId, $targetGroupId, $studentId): void {
            if ($sourceGroupId === $targetGroupId) {
                throw new EducationError(422, 'same_group');
            }
            $this->lockGroups($scope, [$sourceGroupId, $targetGroupId]);
            $source = $this->group($scope, $sourceGroupId);
            $target = $this->group($scope, $targetGroupId);
            if ($source === null || $target === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($source['archivedAt'] !== null || $target['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $this->required(
                'SELECT s.id::text FROM education.students s WHERE ' . self::where('s') . ' AND s.id=CAST(:student AS uuid)',
                [...self::owner($scope), ':student' => $studentId],
            );
            $this->required(
                'SELECT gs.student_id::text FROM education.group_students gs WHERE gs.organization_id=CAST(:org AS uuid)
                AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id) AND gs.group_id=CAST(:group AS uuid)
                AND gs.student_id=CAST(:student AS uuid)',
                [...self::owner($scope), ':group' => $sourceGroupId, ':student' => $studentId],
            );
            $this->db->createCommand(
                'INSERT INTO education.group_students(group_id,student_id,organization_id,owner_account_id)
                VALUES(CAST(:target AS uuid),CAST(:student AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid)) ON CONFLICT DO NOTHING',
                [...self::owner($scope), ':target' => $targetGroupId, ':student' => $studentId],
            )->execute();
            $this->db->createCommand(
                'DELETE FROM education.group_students gs WHERE gs.organization_id=CAST(:org AS uuid)
                AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id) AND gs.group_id=CAST(:source AS uuid)
                AND gs.student_id=CAST(:student AS uuid)',
                [...self::owner($scope), ':source' => $sourceGroupId, ':student' => $studentId],
            )->execute();
            $this->deleteFutureAttendance($scope, $studentId, $sourceGroupId);
            $this->addFutureAttendance($scope, $studentId, $targetGroupId);
        });
    }

    public function lessons(Scope $scope, string $from, string $to): array
    {
        return $this->all(
            self::LESSON . self::where('l') . ' AND l.starts_at>=CAST(:from AS timestamptz)
            AND l.starts_at<CAST(:to AS timestamptz) ORDER BY l.starts_at,l.id LIMIT 501',
            [...self::owner($scope), ':from' => $from, ':to' => $to],
        );
    }

    public function lesson(Scope $scope, string $id): ?array
    {
        return $this->one(self::LESSON . self::where('l') . ' AND l.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function nextLesson(Scope $scope, string $now, bool $currentOnly): ?array
    {
        return $this->one(
            self::LESSON . self::where('l') . " AND l.status='scheduled' AND l.ends_at>CAST(:now AS timestamptz)"
            . ($currentOnly ? ' AND l.starts_at<=CAST(:now AS timestamptz)' : '') . ' ORDER BY l.starts_at,l.id LIMIT 1',
            [...self::owner($scope), ':now' => $now],
        );
    }

    public function createLesson(Scope $scope, array $values): array
    {
        return $this->db->transaction(function () use ($scope, $values): array {
            $this->lockSchedule($scope);
            $this->lockGroup($scope, $values['groupId']);
            $group = $this->group($scope, $values['groupId']);
            if ($group === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($group['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $this->assertNoOverlap($scope, $values['startsAt'], $values['endsAt'], null);
            $row = $this->required('INSERT INTO education.lessons(organization_id,owner_account_id,owner_person_id,group_id,title,starts_at,ends_at,location)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),CAST(:group AS uuid),:title,CAST(:start AS timestamptz),CAST(:end AS timestamptz),:location)
                RETURNING id::text', [...self::owner($scope), ':person' => $scope->personId, ':group' => $values['groupId'],
                ':title' => $values['title'], ':start' => $values['startsAt'], ':end' => $values['endsAt'], ':location' => $values['location']]);
            $id = (string) $row['id'];
            $this->db->createCommand(
                'INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id)
                SELECT CAST(:id AS uuid),student_id,organization_id,owner_account_id FROM education.group_students
                WHERE group_id=CAST(:group AS uuid) AND organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid)',
                [...self::owner($scope), ':id' => $id, ':group' => $values['groupId']],
            )->execute();
            return $this->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
        });
    }

    public function updateLesson(Scope $scope, string $id, array $values): array
    {
        return $this->db->transaction(function () use ($scope, $id, $values): array {
            $this->lockSchedule($scope);
            $current = $this->one(
                self::LESSON . self::where('l') . ' AND l.id=CAST(:id AS uuid) FOR UPDATE OF l',
                [...self::owner($scope), ':id' => $id],
            );
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['status'] !== 'scheduled') {
                if (self::sameLessonValues($current, $values)) {
                    return $current;
                }
                throw new EducationError(409, 'lesson_locked');
            }
            $previousStudents = $this->db->createCommand(
                'SELECT student_id::text FROM education.attendance
                WHERE lesson_id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)',
                [':id' => $id, ':org' => $scope->organizationId],
            )->queryColumn();
            $groupChanged = $current['groupId'] !== $values['groupId'];
            if ($groupChanged) {
                $this->lockGroup($scope, $values['groupId']);
                $group = $this->group($scope, $values['groupId']);
                if ($group === null) {
                    throw new EducationError(404, 'not_found');
                }
                if ($group['archivedAt'] !== null) {
                    throw new EducationError(409, 'group_archived');
                }
                $recorded = (int) $this->db->createCommand(
                    "SELECT count(*) FROM education.attendance a WHERE " . self::where('a')
                    . " AND a.lesson_id=CAST(:id AS uuid) AND a.status<>'pending'",
                    [...self::owner($scope), ':id' => $id],
                )->queryScalar();
                if ($recorded > 0) {
                    throw new EducationError(409, 'attendance_recorded');
                }
            }
            $this->assertNoOverlap($scope, $values['startsAt'], $values['endsAt'], $id);
            $this->required(
                'UPDATE education.lessons l SET group_id=CAST(:group AS uuid),title=:title,
                starts_at=CAST(:start AS timestamptz),ends_at=CAST(:end AS timestamptz),location=:location
                WHERE ' . self::where('l') . ' AND l.id=CAST(:id AS uuid) RETURNING l.id::text',
                [...self::owner($scope), ':id' => $id, ':group' => $values['groupId'], ':title' => $values['title'],
                    ':start' => $values['startsAt'], ':end' => $values['endsAt'], ':location' => $values['location']],
            );
            if ($groupChanged) {
                $this->db->createCommand(
                    'DELETE FROM education.attendance a WHERE ' . self::where('a') . ' AND a.lesson_id=CAST(:id AS uuid)
                    AND a.student_id NOT IN (SELECT gs.student_id FROM education.group_students gs
                        WHERE gs.organization_id=CAST(:org AS uuid) AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)
                        AND gs.group_id=CAST(:group AS uuid))',
                    [...self::owner($scope), ':id' => $id, ':group' => $values['groupId']],
                )->execute();
                $this->db->createCommand(
                    'INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id)
                    SELECT CAST(:id AS uuid),gs.student_id,gs.organization_id,gs.owner_account_id
                    FROM education.group_students gs WHERE gs.organization_id=CAST(:org AS uuid)
                    AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id) AND gs.group_id=CAST(:group AS uuid)
                    ON CONFLICT DO NOTHING',
                    [...self::owner($scope), ':id' => $id, ':group' => $values['groupId']],
                )->execute();
            }
            $lesson = $this->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
            if (!self::sameLessonValues($current, $values)) {
                (new ParentNotifications($this->db))->schedule($scope, $lesson, $previousStudents);
            }
            return $lesson;
        });
    }

    public function completeLesson(Scope $scope, string $id, string $now): array
    {
        return $this->db->transaction(function () use ($scope, $id, $now): array {
            $updated = $this->one(
                "UPDATE education.lessons l SET status='completed',completed_at=now(),cancelled_at=NULL
                WHERE " . self::where('l') . " AND l.id=CAST(:id AS uuid) AND l.status='scheduled'
                AND l.starts_at<=CAST(:now AS timestamptz) RETURNING l.id::text",
                [...self::owner($scope), ':id' => $id, ':now' => $now],
            );
            if ($updated !== null) {
                return $this->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
            }
            $current = $this->lesson($scope, $id);
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['status'] === 'completed') {
                return $current;
            }
            if ($current['status'] === 'cancelled') {
                throw new EducationError(409, 'invalid_status_transition');
            }
            throw new EducationError(409, 'lesson_not_started');
        });
    }

    public function cancelLesson(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $updated = $this->one(
                "UPDATE education.lessons l SET status='cancelled',cancelled_at=now(),completed_at=NULL
                WHERE " . self::where('l') . " AND l.id=CAST(:id AS uuid) AND l.status='scheduled'
                RETURNING l.id::text",
                [...self::owner($scope), ':id' => $id],
            );
            if ($updated !== null) {
                $lesson = $this->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
                (new ParentNotifications($this->db))->schedule($scope, $lesson);
                return $lesson;
            }
            $current = $this->lesson($scope, $id);
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['status'] === 'cancelled') {
                return $current;
            }
            throw new EducationError(409, 'invalid_status_transition');
        });
    }

    public function participants(Scope $scope, string $lessonId): array
    {
        return $this->all('SELECT s.id::text,s.person_id::text AS "personId",a.status FROM education.attendance a
            JOIN education.students s ON s.id=a.student_id WHERE ' . self::where('a') . '
            AND a.lesson_id=CAST(:id AS uuid) ORDER BY s.created_at,s.id LIMIT 501', [...self::owner($scope), ':id' => $lessonId]);
    }

    public function markAttendance(Scope $scope, string $lessonId, string $studentId, string $status): void
    {
        $this->required(
            'UPDATE education.attendance a SET status=:status,updated_at=now() WHERE ' . self::where('a') . '
            AND lesson_id=CAST(:lesson AS uuid) AND student_id=CAST(:student AS uuid) RETURNING student_id::text AS id',
            [...self::owner($scope), ':lesson' => $lessonId, ':student' => $studentId, ':status' => $status],
        );
    }

    public function events(Scope $scope, string $from, string $to): array
    {
        return $this->all(
            'SELECT e.id::text,e.title,e.kind,e.location,e.starts_at::text AS "startsAt",e.ends_at::text AS "endsAt"
            FROM education.events e WHERE ' . self::where('e') . ' AND starts_at>=CAST(:from AS timestamptz)
            AND starts_at<CAST(:to AS timestamptz) ORDER BY starts_at,id LIMIT 501',
            [...self::owner($scope), ':from' => $from, ':to' => $to],
        );
    }

    public function createEvent(Scope $scope, array $values): array
    {
        return $this->required(
            'INSERT INTO education.events(organization_id,owner_account_id,owner_person_id,title,kind,location,starts_at,ends_at)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:title,:kind,:location,CAST(:start AS timestamptz),CAST(:end AS timestamptz))
            RETURNING id::text,title,kind,location,starts_at::text AS "startsAt",ends_at::text AS "endsAt"',
            [...self::owner($scope), ':person' => $scope->personId, ':title' => $values['title'], ':kind' => $values['kind'],
                ':location' => $values['location'], ':start' => $values['startsAt'], ':end' => $values['endsAt']],
        );
    }

    public function files(Scope $scope, ?string $lessonId, int $offset): array
    {
        return $this->all('SELECT f.id::text,f.lesson_id::text AS "lessonId",f.name,f.mime,f.size_bytes AS "sizeBytes",f.created_at::text AS "createdAt",
            (SELECT l.status FROM education.lessons l WHERE l.id=f.lesson_id) AS "lessonStatus"
            FROM education.files f WHERE ' . self::where('f') . " AND f.purpose='material'" . ($lessonId === null ? '' : ' AND lesson_id=CAST(:lesson AS uuid)') . '
            ORDER BY f.created_at,f.id LIMIT 51 OFFSET :offset', [...self::owner($scope), ':offset' => $offset,
            ...($lessonId === null ? [] : [':lesson' => $lessonId])]);
    }

    public function file(Scope $scope, string $id): ?array
    {
        if ($scope->studentId !== null) {
            return $this->one(
                'SELECT f.id::text,f.lesson_id::text AS "lessonId",f.name,f.mime,f.purpose,f.size_bytes AS "sizeBytes",
                f.sha256,f.storage_key::text AS "storageKey"
                FROM education.files f JOIN education.lessons l ON l.id=f.lesson_id AND l.organization_id=f.organization_id
                JOIN education.group_students gs ON gs.group_id=l.group_id AND gs.organization_id=l.organization_id
                WHERE f.organization_id=CAST(:org AS uuid) AND f.purpose=\'material\' AND f.id=CAST(:id AS uuid)
                AND gs.student_id=CAST(:student AS uuid)',
                [':org' => $scope->organizationId, ':id' => $id, ':student' => $scope->studentId],
            );
        }
        return $this->one('SELECT f.id::text,f.lesson_id::text AS "lessonId",f.name,f.mime,f.purpose,f.size_bytes AS "sizeBytes",f.sha256,f.storage_key::text AS "storageKey"
            FROM education.files f WHERE ((f.owner_account_id=CAST(:actor AS uuid) AND (f.organization_id=CAST(:org AS uuid) OR f.purpose=\'avatar\')) OR (f.organization_id=CAST(:org AS uuid) AND f.purpose=\'material\' AND EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=f.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))) AND f.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function createFile(Scope $scope, array $values): array
    {
        return $this->required('INSERT INTO education.files(organization_id,owner_account_id,owner_person_id,lesson_id,name,mime,purpose,size_bytes,sha256,storage_key)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),CAST(:lesson AS uuid),:name,:mime,:purpose,:size,:hash,CAST(:key AS uuid))
            RETURNING id::text,name,mime,size_bytes AS "sizeBytes"', [...self::owner($scope), ':person' => $scope->personId,
            ':lesson' => $values['lessonId'], ':name' => $values['name'], ':mime' => $values['mime'], ':purpose' => $values['purpose'],
            ':size' => $values['sizeBytes'], ':hash' => $values['sha256'], ':key' => $values['storageKey']]);
    }

    public function deleteFile(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $file = $this->one(
                'SELECT f.id::text,f.lesson_id::text AS "lessonId",f.storage_key::text AS "storageKey"
                FROM education.files f WHERE f.owner_account_id=CAST(:actor AS uuid)
                AND f.organization_id=CAST(:org AS uuid) AND f.purpose=\'material\' AND f.id=CAST(:id AS uuid) FOR UPDATE',
                [...self::owner($scope), ':id' => $id],
            );
            if ($file === null) {
                throw new EducationError(404, 'not_found');
            }
            $lessonId = $file['lessonId'] ?? null;
            if (is_string($lessonId)) {
                $status = $this->db->createCommand(
                    'SELECT l.status FROM education.lessons l WHERE ' . self::where('l') . ' AND l.id=CAST(:id AS uuid) FOR UPDATE',
                    [...self::owner($scope), ':id' => $lessonId],
                )->queryScalar();
                if (!is_string($status)) {
                    throw new EducationError(404, 'not_found');
                }
                if ($status === 'completed') {
                    throw new EducationError(409, 'lesson_locked');
                }
            }
            $this->required(
                'DELETE FROM education.files f WHERE f.owner_account_id=CAST(:actor AS uuid)
                AND f.organization_id=CAST(:org AS uuid) AND f.purpose=\'material\' AND f.id=CAST(:id AS uuid)
                RETURNING f.id::text',
                [...self::owner($scope), ':id' => $id],
            );
            return $file;
        });
    }

    public function profile(Scope $scope): ?array
    {
        return $this->one(
            'SELECT id::text,display_name AS "displayName",avatar_file_id::text AS "avatarFileId"
            FROM education.profiles WHERE account_id=CAST(:actor AS uuid) AND person_id=CAST(:person AS uuid)',
            [':actor' => $scope->accountId, ':person' => $scope->personId],
        );
    }

    public function saveProfile(Scope $scope, ?string $displayName, ?string $avatarFileId): void
    {
        $this->db->createCommand(
            'INSERT INTO education.profiles(account_id,person_id,display_name,avatar_file_id)
            VALUES(CAST(:actor AS uuid),CAST(:person AS uuid),:name,CAST(:file AS uuid))
            ON CONFLICT(account_id) DO UPDATE SET display_name=EXCLUDED.display_name,avatar_file_id=EXCLUDED.avatar_file_id',
            [':actor' => $scope->accountId, ':person' => $scope->personId, ':name' => $displayName, ':file' => $avatarFileId],
        )->execute();
    }

    public function colleagues(Scope $scope): array
    {
        return $this->all('SELECT person_id::text AS "personId" FROM education.teachers
            WHERE organization_id=CAST(:org AS uuid) AND account_id<>CAST(:actor AS uuid) ORDER BY person_id LIMIT 501', self::owner($scope));
    }

    public function threads(Scope $scope, int $offset): array
    {
        return $this->all('SELECT t.id::text,t.subject,t.created_at::text AS "createdAt",
            (SELECT count(*) FROM education.messages m WHERE m.thread_id=t.id AND m.sender_person_id<>CAST(:person AS uuid)
                AND (tm.last_read_at IS NULL OR (m.created_at,m.id)>(tm.last_read_at,COALESCE(tm.last_read_message_id,\'ffffffff-ffff-ffff-ffff-ffffffffffff\'::uuid)))) AS "unreadCount"
            FROM education.threads t JOIN education.thread_members tm ON tm.thread_id=t.id
            WHERE t.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid)
            ORDER BY t.created_at DESC,t.id LIMIT 51 OFFSET :offset', [...self::recipient($scope), ':offset' => $offset]);
    }

    public function thread(Scope $scope, string $id): ?array
    {
        return $this->one(
            'SELECT t.id::text,t.subject FROM education.threads t JOIN education.thread_members tm ON tm.thread_id=t.id
            WHERE t.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid) AND t.id=CAST(:id AS uuid)',
            [...self::recipient($scope), ':id' => $id],
        );
    }

    public function messages(Scope $scope, string $threadId, int $offset): array
    {
        return $this->all('SELECT m.id::text,m.sender_person_id::text AS "senderPersonId",m.body,m.created_at::text AS "createdAt"
            FROM education.messages m JOIN education.thread_members tm ON tm.thread_id=m.thread_id
            WHERE m.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid) AND m.thread_id=CAST(:id AS uuid)
            ORDER BY m.created_at DESC,m.id DESC LIMIT 51 OFFSET :offset', [...self::recipient($scope), ':id' => $threadId, ':offset' => $offset]);
    }

    public function threadPeers(Scope $scope, string $id): array
    {
        $rows = $this->all(
            'SELECT peer.person_id::text AS id FROM education.thread_members peer
            JOIN education.thread_members self ON self.thread_id=peer.thread_id
            WHERE peer.organization_id=CAST(:org AS uuid) AND self.person_id=CAST(:person AS uuid)
            AND peer.person_id<>CAST(:person AS uuid) AND peer.thread_id=CAST(:id AS uuid) ORDER BY peer.person_id',
            [...self::recipient($scope), ':id' => $id],
        );
        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }

    public function startThread(Scope $scope, string $recipientPersonId, string $subject, string $body): array
    {
        return $this->db->transaction(function () use ($scope, $recipientPersonId, $subject, $body): array {
            $thread = $this->required('INSERT INTO education.threads(organization_id,subject)
                VALUES(CAST(:org AS uuid),:subject) RETURNING id::text,subject', [':org' => $scope->organizationId, ':subject' => $subject]);
            $id = (string) $thread['id'];
            foreach ([$scope->personId, $recipientPersonId] as $personId) {
                $this->db->createCommand(
                    'INSERT INTO education.thread_members(thread_id,organization_id,person_id)
                    VALUES(CAST(:id AS uuid),CAST(:org AS uuid),CAST(:person AS uuid))',
                    [':id' => $id, ':org' => $scope->organizationId, ':person' => $personId],
                )->execute();
            }
            $this->sendMessage($scope, $id, $body);
            return $thread;
        });
    }

    public function sendMessage(Scope $scope, string $threadId, string $body): array
    {
        return $this->db->transaction(function () use ($scope, $threadId, $body): array {
            if ($this->thread($scope, $threadId) === null) {
                throw new EducationError(404, 'not_found');
            }
            $params = [...self::recipient($scope), ':thread' => $threadId];
            $message = $this->required('INSERT INTO education.messages(thread_id,organization_id,sender_person_id,body)
                VALUES(CAST(:thread AS uuid),CAST(:org AS uuid),CAST(:person AS uuid),:body)
                RETURNING id::text,sender_person_id::text AS "senderPersonId",body,created_at::text AS "createdAt"', [...$params, ':body' => $body]);
            $this->db->createCommand('INSERT INTO education.notifications(organization_id,person_id,thread_id,message_id)
                SELECT organization_id,person_id,thread_id,CAST(:message AS uuid) FROM education.thread_members WHERE thread_id=CAST(:thread AS uuid)
                AND organization_id=CAST(:org AS uuid) AND person_id<>CAST(:person AS uuid)', [...$params, ':message' => (string) $message['id']])->execute();
            return $message;
        });
    }

    public function readThread(Scope $scope, string $threadId, string $lastSeenMessageId): void
    {
        $this->db->transaction(function () use ($scope, $threadId, $lastSeenMessageId): void {
            $params = [...self::recipient($scope), ':id' => $threadId, ':message' => $lastSeenMessageId];
            $target = $this->required('SELECT m.id::text,m.created_at::text AS "createdAt" FROM education.messages m
                JOIN education.thread_members tm ON tm.thread_id=m.thread_id WHERE m.organization_id=CAST(:org AS uuid)
                AND tm.person_id=CAST(:person AS uuid) AND m.thread_id=CAST(:id AS uuid) AND m.id=CAST(:message AS uuid)', $params);
            $params[':at'] = (string) $target['createdAt'];
            $this->db->createCommand("UPDATE education.thread_members SET
                last_read_message_id=CASE WHEN last_read_at IS NULL OR (last_read_at,COALESCE(last_read_message_id,'ffffffff-ffff-ffff-ffff-ffffffffffff'::uuid))<(CAST(:at AS timestamptz),CAST(:message AS uuid)) THEN CAST(:message AS uuid) ELSE last_read_message_id END,
                last_read_at=GREATEST(last_read_at,CAST(:at AS timestamptz))
                WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND thread_id=CAST(:id AS uuid)", $params)->execute();
            $this->db->createCommand('UPDATE education.notifications n SET read_at=now() WHERE n.organization_id=CAST(:org AS uuid)
                AND n.person_id=CAST(:person AS uuid) AND n.thread_id=CAST(:id AS uuid) AND n.read_at IS NULL AND
                ((n.message_id IS NULL AND n.created_at<=CAST(:at AS timestamptz)) OR EXISTS(SELECT 1 FROM education.messages m
                    WHERE m.id=n.message_id AND (m.created_at,m.id)<=(CAST(:at AS timestamptz),CAST(:message AS uuid))))', $params)->execute();
        });
    }

    public function notifications(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT n.id::text,n.thread_id::text AS "threadId",COALESCE(n.subject,t.subject) AS subject,n.kind,
            n.resource_id::text AS "resourceId",n.resource_path AS "resourcePath",n.body,n.created_at::text AS "createdAt",n.read_at::text AS "readAt"
            FROM education.notifications n LEFT JOIN education.threads t ON t.id=n.thread_id WHERE n.organization_id=CAST(:org AS uuid)
            AND n.person_id=CAST(:person AS uuid) AND n.parent_student_id IS NULL ORDER BY n.created_at DESC,n.id LIMIT 51 OFFSET :offset',
            [...self::recipient($scope), ':offset' => $offset],
        );
    }

    public function unreadCount(Scope $scope): int
    {
        return (int) $this->db->createCommand('SELECT count(*) FROM education.notifications
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND read_at IS NULL AND parent_student_id IS NULL', self::recipient($scope))->queryScalar();
    }

    public function readNotification(Scope $scope, string $id): void
    {
        $this->required(
            'UPDATE education.notifications SET read_at=COALESCE(read_at,now())
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND parent_student_id IS NULL AND id=CAST(:id AS uuid) RETURNING id::text',
            [...self::recipient($scope), ':id' => $id],
        );
    }

    public function attention(Scope $scope, string $from, string $to): array
    {
        return $this->all(
            'SELECT l.id::text,l.title,count(*) AS "absentCount" FROM education.lessons l
            JOIN education.attendance a ON a.lesson_id=l.id WHERE ' . self::where('l') . " AND a.status='absent'
            AND l.status<>'cancelled' AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            GROUP BY l.id,l.title,l.starts_at ORDER BY l.starts_at,l.id LIMIT 501",
            [...self::owner($scope), ':from' => $from, ':to' => $to],
        );
    }

    public function lessonReport(Scope $scope, string $lessonId): ?array
    {
        return $this->one(
            'SELECT r.id::text,r.lesson_id::text AS "lessonId",r.topic,r.summary,r.teacher_comment AS "teacherComment",
            r.updated_at::text AS "updatedAt" FROM education.lesson_reports r
            WHERE ' . self::where('r') . ' AND r.lesson_id=CAST(:lesson AS uuid)',
            [...self::owner($scope), ':lesson' => $lessonId],
        );
    }

    public function saveLessonReport(Scope $scope, array $values): array
    {
        return $this->db->transaction(function () use ($scope, $values): array {
            $this->lockLesson($scope, $values['lessonId']);
            $current = $this->one(
                self::LESSON . self::where('l') . ' AND l.id=CAST(:id AS uuid) FOR UPDATE OF l',
                [...self::owner($scope), ':id' => $values['lessonId']],
            );
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['status'] === 'cancelled') {
                throw new EducationError(409, 'lesson_cancelled');
            }
            if ($current['status'] !== 'completed') {
                throw new EducationError(409, 'lesson_not_completed');
            }
            $this->required(
                'INSERT INTO education.lesson_reports(organization_id,owner_account_id,owner_person_id,lesson_id,topic,summary,teacher_comment)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),CAST(:lesson AS uuid),:topic,:summary,:comment)
                ON CONFLICT(lesson_id,organization_id,owner_account_id) DO UPDATE SET
                topic=EXCLUDED.topic,summary=EXCLUDED.summary,teacher_comment=EXCLUDED.teacher_comment,updated_at=now()
                RETURNING id::text,lesson_id::text AS "lessonId",topic,summary,teacher_comment AS "teacherComment",updated_at::text AS "updatedAt"',
                [...self::owner($scope), ':person' => $scope->personId, ':lesson' => $values['lessonId'],
                    ':topic' => $values['topic'], ':summary' => $values['summary'], ':comment' => $values['teacherComment']],
            );
            return $this->lessonReport($scope, $values['lessonId']) ?? throw new EducationError(409, 'write_not_confirmed');
        });
    }

    public function lessonResults(Scope $scope, string $lessonId): array
    {
        return $this->all(
            'SELECT s.id::text,s.person_id::text AS "personId",
            COALESCE(r.status,\'pending\') AS status,r.comment,r.updated_at::text AS "updatedAt"
            FROM education.attendance a JOIN education.students s ON s.id=a.student_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=a.lesson_id AND r.student_id=a.student_id
            WHERE ' . self::where('a') . ' AND a.lesson_id=CAST(:lesson AS uuid)
            ORDER BY s.created_at,s.id LIMIT 501',
            [...self::owner($scope), ':lesson' => $lessonId],
        );
    }

    public function saveLessonResult(Scope $scope, string $lessonId, string $studentId, string $status, ?string $comment): array
    {
        return $this->db->transaction(function () use ($scope, $lessonId, $studentId, $status, $comment): array {
            $this->lockLesson($scope, $lessonId);
            $current = $this->one(
                self::LESSON . self::where('l') . ' AND l.id=CAST(:id AS uuid) FOR UPDATE OF l',
                [...self::owner($scope), ':id' => $lessonId],
            );
            if ($current === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($current['status'] === 'cancelled') {
                throw new EducationError(409, 'lesson_cancelled');
            }
            if ($current['status'] !== 'completed') {
                throw new EducationError(409, 'lesson_not_completed');
            }
            $this->required(
                'SELECT s.id::text FROM education.students s WHERE ' . self::where('s') . ' AND s.id=CAST(:student AS uuid)',
                [...self::owner($scope), ':student' => $studentId],
            );
            $participant = $this->one(
                'SELECT a.student_id::text AS "studentId" FROM education.attendance a WHERE ' . self::where('a')
                . ' AND a.lesson_id=CAST(:lesson AS uuid) AND a.student_id=CAST(:student AS uuid) FOR UPDATE',
                [...self::owner($scope), ':lesson' => $lessonId, ':student' => $studentId],
            );
            if ($participant === null) {
                throw new EducationError(404, 'not_found');
            }
            $row = $this->required(
                'INSERT INTO education.lesson_results(organization_id,owner_account_id,lesson_id,student_id,status,comment)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:lesson AS uuid),CAST(:student AS uuid),:status,:comment)
                ON CONFLICT(lesson_id,student_id) DO UPDATE SET status=EXCLUDED.status,comment=EXCLUDED.comment,updated_at=now()
                RETURNING id::text,lesson_id::text AS "lessonId",student_id::text AS "studentId",status,comment,updated_at::text AS "updatedAt"',
                [...self::owner($scope), ':lesson' => $lessonId, ':student' => $studentId,
                    ':status' => $status, ':comment' => $comment],
            );
            $this->db->createCommand(
                'INSERT INTO education.result_revisions(organization_id,owner_account_id,result_id,status,comment,revised_by_account_id)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:result AS uuid),:status,:comment,CAST(:reviser AS uuid))',
                [...self::owner($scope), ':result' => (string) $row['id'], ':status' => $status, ':comment' => $comment,
                    ':reviser' => $scope->actor()],
            )->execute();
            return $row;
        });
    }

    public function lessonResultRevisions(Scope $scope, string $lessonId, string $studentId): array
    {
        return $this->all(
            'SELECT v.id::text,v.result_id::text AS "resultId",v.status,v.comment,v.revised_at::text AS "revisedAt",
            v.revised_by_account_id::text AS "revisedByAccountId" FROM education.result_revisions v
            JOIN education.lesson_results r ON r.id=v.result_id
            WHERE v.organization_id=CAST(:org AS uuid) AND (v.owner_account_id=CAST(:actor AS uuid)
                OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=r.lesson_id
                    AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND r.lesson_id=CAST(:lesson AS uuid) AND r.student_id=CAST(:student AS uuid)
            ORDER BY v.revised_at,v.id LIMIT 501',
            [...self::owner($scope), ':lesson' => $lessonId, ':student' => $studentId],
        );
    }

    public function journal(Scope $scope, string $groupId, string $from, string $to, ?string $studentId, int $offset): array
    {
        $filters = ' AND l.status=\'completed\' AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)';
        $params = [...self::owner($scope), ':group' => $groupId, ':from' => $from, ':to' => $to, ':offset' => $offset];
        if ($studentId !== null) {
            $filters .= ' AND EXISTS(SELECT 1 FROM education.attendance a2 WHERE a2.lesson_id=l.id AND a2.organization_id=CAST(:org AS uuid)
                AND (a2.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=a2.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id))) AND a2.student_id=CAST(:student AS uuid))';
            $params[':student'] = $studentId;
        }
        return $this->all(
            self::LESSON . self::where('l') . ' AND l.group_id=CAST(:group AS uuid)' . $filters
            . ' ORDER BY l.starts_at DESC,l.id DESC LIMIT 51 OFFSET :offset',
            $params,
        );
    }

    public function student(Scope $scope, string $id): ?array
    {
        return $this->one(
            'SELECT s.id::text,s.person_id::text AS "personId",s.created_at::text AS "createdAt"
            FROM education.students s WHERE ' . self::where('s') . ' AND s.id=CAST(:id AS uuid)',
            [...self::owner($scope), ':id' => $id],
        );
    }

    public function studentGroups(Scope $scope, string $studentId): array
    {
        return $this->all(
            'SELECT g.id::text,g.name,g.archived_at::text AS "archivedAt" FROM education.groups g
            JOIN education.group_students gs ON gs.group_id=g.id
            WHERE gs.organization_id=CAST(:org AS uuid) AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)
            AND gs.student_id=CAST(:student AS uuid) ORDER BY g.created_at,g.id',
            [...self::owner($scope), ':student' => $studentId],
        );
    }

    public function studentAttendance(Scope $scope, string $studentId, string $from, string $to, int $offset): array
    {
        return $this->all(
            'SELECT a.lesson_id::text AS "lessonId",l.title,l.starts_at::text AS "startsAt",l.status,a.status AS "attendance"
            FROM education.attendance a JOIN education.lessons l ON l.id=a.lesson_id
            WHERE a.organization_id=CAST(:org AS uuid) AND (a.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=a.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND a.student_id=CAST(:student AS uuid) AND l.status<>\'cancelled\'
            AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            ORDER BY l.starts_at DESC,l.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':student' => $studentId, ':from' => $from, ':to' => $to, ':offset' => $offset],
        );
    }

    public function studentResults(Scope $scope, string $studentId, int $offset): array
    {
        return $this->all(
            'SELECT r.id::text,r.lesson_id::text AS "lessonId",l.title AS "lessonTitle",l.starts_at::text AS "startsAt",
            r.status,r.comment,r.updated_at::text AS "updatedAt"
            FROM education.lesson_results r JOIN education.lessons l ON l.id=r.lesson_id
            WHERE r.organization_id=CAST(:org AS uuid) AND (r.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=r.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND r.student_id=CAST(:student AS uuid) AND l.status=\'completed\'
            ORDER BY l.starts_at DESC,l.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':student' => $studentId, ':offset' => $offset],
        );
    }

    public function studentProgress(Scope $scope, string $studentId, string $from, string $to): array
    {
        $results = $this->all(
            'SELECT r.status,count(*) AS "count" FROM education.lesson_results r JOIN education.lessons l ON l.id=r.lesson_id
            WHERE r.organization_id=CAST(:org AS uuid) AND (r.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=r.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND r.student_id=CAST(:student AS uuid) AND l.status=\'completed\' AND r.status<>\'pending\'
            AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            GROUP BY r.status',
            [...self::owner($scope), ':student' => $studentId, ':from' => $from, ':to' => $to],
        );
        $results = array_column($results, 'count', 'status');
        $attendance = $this->all(
            'SELECT a.status,count(*) AS "count" FROM education.attendance a JOIN education.lessons l ON l.id=a.lesson_id
            WHERE a.organization_id=CAST(:org AS uuid) AND (a.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=a.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND a.student_id=CAST(:student AS uuid) AND l.status<>\'cancelled\' AND a.status<>\'pending\'
            AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            GROUP BY a.status',
            [...self::owner($scope), ':student' => $studentId, ':from' => $from, ':to' => $to],
        );
        $attendance = array_column($attendance, 'count', 'status');
        return [
            'attendance' => ['present' => (int) ($attendance['present'] ?? 0), 'absent' => (int) ($attendance['absent'] ?? 0)],
            'results' => ['mastered' => (int) ($results['mastered'] ?? 0), 'developing' => (int) ($results['developing'] ?? 0),
                'needs_support' => (int) ($results['needs_support'] ?? 0)],
        ];
    }

    public function journalReports(Scope $scope, array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }
        return $this->all(
            'SELECT r.id::text,r.lesson_id::text AS "lessonId",r.topic,r.summary,r.teacher_comment AS "teacherComment",
            r.updated_at::text AS "updatedAt" FROM education.lesson_reports r
            WHERE ' . self::where('r') . ' AND r.lesson_id IN (' . self::in(':lesson', $lessonIds) . ')',
            [...self::owner($scope), ...self::inParams(':lesson', $lessonIds)],
        );
    }

    public function journalResults(Scope $scope, array $lessonIds, ?string $studentId): array
    {
        if ($lessonIds === []) {
            return [];
        }
        $params = [...self::owner($scope), ...self::inParams(':lesson', $lessonIds)];
        $studentFilter = '';
        if ($studentId !== null) {
            $studentFilter = ' AND s.id=CAST(:student AS uuid)';
            $params[':student'] = $studentId;
        }
        return $this->all(
            'SELECT a.lesson_id::text AS "lessonId",s.id::text AS "studentId",s.person_id::text AS "personId",
            COALESCE(r.status,\'pending\') AS status,r.comment,r.updated_at::text AS "updatedAt"
            FROM education.attendance a JOIN education.students s ON s.id=a.student_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=a.lesson_id AND r.student_id=a.student_id
            WHERE ' . self::where('a') . ' AND a.lesson_id IN (' . self::in(':lesson', $lessonIds) . ')'
            . $studentFilter . ' ORDER BY s.created_at,s.id',
            $params,
        );
    }

    public function studentOrganizations(string $accountId): array
    {
        return array_column($this->all(
            'SELECT organization_id::text AS org FROM education.student_accounts
            WHERE account_id=CAST(:account AS uuid) AND revoked_at IS NULL',
            [':account' => $accountId],
        ), 'org');
    }

    public function studentGroupList(Scope $scope): array
    {
        return $this->all(
            'SELECT g.id::text,g.name,g.archived_at::text AS "archivedAt" FROM education.groups g
            JOIN education.group_students gs ON gs.group_id=g.id
            WHERE gs.student_id=CAST(:student AS uuid) AND gs.organization_id=CAST(:org AS uuid)
            ORDER BY g.created_at,g.id',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId],
        );
    }

    public function studentMaterials(Scope $scope, string $lessonId): array
    {
        return $this->all(
            'SELECT f.id::text,f.name,f.mime,f.size_bytes AS "sizeBytes",f.created_at::text AS "createdAt"
            FROM education.files f WHERE f.lesson_id=CAST(:lesson AS uuid) AND f.organization_id=CAST(:org AS uuid)
            AND f.purpose=\'material\' ORDER BY f.created_at,f.id',
            [':org' => $scope->organizationId, ':lesson' => $lessonId],
        );
    }

    public function studentByAccount(string $accountId, string $organizationId, string $personId): ?array
    {
        return $this->one(
            'SELECT s.id::text,s.person_id::text AS "personId" FROM education.student_accounts sa
            JOIN education.students s ON s.id=sa.student_id
            WHERE sa.account_id=CAST(:account AS uuid) AND sa.organization_id=CAST(:org AS uuid)
            AND s.person_id=CAST(:person AS uuid) AND sa.revoked_at IS NULL',
            [':account' => $accountId, ':org' => $organizationId, ':person' => $personId],
        );
    }

    public function studentAccount(Scope $scope, string $studentId): ?array
    {
        return $this->one(
            'SELECT sa.account_id::text AS "accountId",sa.activated_at::text AS "activatedAt",sa.revoked_at::text AS "revokedAt"
            FROM education.student_accounts sa
            WHERE sa.student_id=CAST(:student AS uuid) AND sa.organization_id=CAST(:org AS uuid)
            AND sa.student_id IN (SELECT id FROM education.students WHERE id=CAST(:student AS uuid)
                AND organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid))',
            [...self::owner($scope), ':student' => $studentId],
        );
    }

    public function createStudentInvite(Scope $scope, string $studentId, string $codeHash, string $expiresAt): array
    {
        return $this->required(
            'INSERT INTO education.student_invites(organization_id,owner_account_id,student_id,code_hash,expires_at)
            SELECT s.organization_id,s.owner_account_id,s.id,:code,:expires FROM education.students s
            WHERE ' . self::where('s') . ' AND s.id=CAST(:student AS uuid)
            RETURNING id::text,organization_id::text AS "organizationId",student_id::text AS "studentId",expires_at::text AS "expiresAt"',
            [...self::owner($scope), ':student' => $studentId, ':code' => $codeHash, ':expires' => $expiresAt],
        );
    }

    public function findStudentInvite(string $codeHash): ?array
    {
        return $this->one(
            'SELECT i.id::text,i.organization_id::text AS "organizationId",i.owner_account_id::text AS "ownerAccountId",
            i.student_id::text AS "studentId",i.expires_at::text AS "expiresAt",i.used_at::text AS "usedAt",i.used_account_id::text AS "usedAccountId",
            s.person_id::text AS "personId" FROM education.student_invites i
            JOIN education.students s ON s.id=i.student_id
            WHERE i.code_hash=:hash',
            [':hash' => $codeHash],
        );
    }

    public function activateStudentAccount(string $inviteId, string $studentId, string $organizationId, string $accountId, string $personId): array
    {
        return $this->db->transaction(function () use ($inviteId, $studentId, $organizationId, $accountId, $personId): array {
            $invite = $this->one(
                'SELECT id::text,student_id::text AS "studentId",organization_id::text AS "organizationId",
                expires_at::text AS "expiresAt",used_at::text AS "usedAt" FROM education.student_invites
                WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $inviteId],
            );
            if ($invite === null || $invite['studentId'] !== $studentId || $invite['organizationId'] !== $organizationId) {
                throw new EducationError(404, 'not_found');
            }
            if ($invite['usedAt'] !== null || new DateTimeImmutable((string) $invite['expiresAt']) <= new DateTimeImmutable('now')) {
                throw new EducationError(409, 'invite_invalid');
            }
            $linked = $this->one(
                'SELECT student_id::text AS "studentId" FROM education.student_accounts
                WHERE organization_id=CAST(:org AS uuid) AND account_id=CAST(:account AS uuid)',
                [':org' => $organizationId, ':account' => $accountId],
            );
            if ($linked !== null) {
                throw new EducationError(409, 'account_already_linked');
            }
            $this->db->createCommand(
                'UPDATE education.student_invites SET used_at=now(),used_account_id=CAST(:account AS uuid) WHERE id=CAST(:id AS uuid)',
                [':account' => $accountId, ':id' => $inviteId],
            )->execute();
            $this->required(
                'INSERT INTO education.student_accounts(student_id,organization_id,account_id,person_id,activated_at,revoked_at)
                VALUES(CAST(:student AS uuid),CAST(:org AS uuid),CAST(:account AS uuid),CAST(:person AS uuid),now(),NULL)
                ON CONFLICT(student_id,organization_id) DO UPDATE SET
                account_id=EXCLUDED.account_id,person_id=EXCLUDED.person_id,activated_at=now(),revoked_at=NULL
                RETURNING student_id::text AS "studentId",organization_id::text AS "organizationId",account_id::text AS "accountId",
                activated_at::text AS "activatedAt"',
                [':student' => $studentId, ':org' => $organizationId, ':account' => $accountId, ':person' => $personId],
            );
            return ['studentId' => $studentId, 'organizationId' => $organizationId, 'accountId' => $accountId];
        });
    }

    public function revokeStudentAccount(Scope $scope, string $studentId): void
    {
        $this->db->createCommand(
            'UPDATE education.student_accounts sa SET revoked_at=now()
            WHERE sa.student_id=CAST(:student AS uuid) AND sa.organization_id=CAST(:org AS uuid)
            AND sa.student_id IN (SELECT id FROM education.students WHERE id=CAST(:student AS uuid)
                AND organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid))',
            [...self::owner($scope), ':student' => $studentId],
        )->execute();
    }

    public function studentSchedule(Scope $scope, string $from, string $to): array
    {
        return $this->all(
            'SELECT l.id::text,l.group_id::text AS "groupId",g.name AS "groupName",l.title,l.starts_at::text AS "startsAt",
            l.ends_at::text AS "endsAt",l.location,l.status,
            COALESCE(a.status,\'pending\') AS "attendance",
            COALESCE(r.status,\'pending\') AS "resultStatus",r.comment AS "resultComment"
            FROM education.lessons l JOIN education.groups g ON g.id=l.group_id
            JOIN education.group_students gs ON gs.group_id=l.group_id
            LEFT JOIN education.attendance a ON a.lesson_id=l.id AND a.student_id=gs.student_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=l.id AND r.student_id=gs.student_id
            WHERE gs.student_id=CAST(:student AS uuid) AND gs.organization_id=CAST(:org AS uuid)
            AND l.organization_id=CAST(:org AS uuid)
            AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            ORDER BY l.starts_at,l.id LIMIT 101',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':from' => $from, ':to' => $to],
        );
    }

    public function studentLessons(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT l.id::text,l.group_id::text AS "groupId",g.name AS "groupName",l.title,l.starts_at::text AS "startsAt",
            l.ends_at::text AS "endsAt",l.location,l.status,
            COALESCE(a.status,\'pending\') AS "attendance",
            COALESCE(r.status,\'pending\') AS "resultStatus",r.comment AS "resultComment"
            FROM education.lessons l JOIN education.groups g ON g.id=l.group_id
            JOIN education.group_students gs ON gs.group_id=l.group_id
            LEFT JOIN education.attendance a ON a.lesson_id=l.id AND a.student_id=gs.student_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=l.id AND r.student_id=gs.student_id
            WHERE gs.student_id=CAST(:student AS uuid) AND gs.organization_id=CAST(:org AS uuid)
            AND l.organization_id=CAST(:org AS uuid)
            ORDER BY l.starts_at DESC,l.id DESC LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':offset' => $offset],
        );
    }

    public function studentLesson(Scope $scope, string $lessonId): ?array
    {
        return $this->one(
            'SELECT l.id::text,l.group_id::text AS "groupId",g.name AS "groupName",l.title,l.starts_at::text AS "startsAt",
            l.ends_at::text AS "endsAt",l.location,l.status,
            COALESCE(a.status,\'pending\') AS "attendance",
            COALESCE(r.status,\'pending\') AS "resultStatus",r.comment AS "resultComment"
            FROM education.lessons l JOIN education.groups g ON g.id=l.group_id
            JOIN education.group_students gs ON gs.group_id=l.group_id
            LEFT JOIN education.attendance a ON a.lesson_id=l.id AND a.student_id=gs.student_id
            LEFT JOIN education.lesson_results r ON r.lesson_id=l.id AND r.student_id=gs.student_id
            WHERE gs.student_id=CAST(:student AS uuid) AND gs.organization_id=CAST(:org AS uuid)
            AND l.organization_id=CAST(:org AS uuid) AND l.id=CAST(:lesson AS uuid)',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':lesson' => $lessonId],
        );
    }

    public function activityCatalog(): array
    {
        return $this->all(
            'SELECT id::text,code,name,kind,contract_version AS "contractVersion",enabled,config,metrics,required_permission AS "requiredPermission"
            FROM education.activity_definitions WHERE enabled ORDER BY CASE WHEN code=\'platform-typing\' THEN 0 ELSE 1 END,name,code',
            [],
        );
    }

    public function activityDefinition(string $code): ?array
    {
        return $this->one(
            'SELECT id::text,code,name,kind,contract_version AS "contractVersion",enabled,config,metrics,required_permission AS "requiredPermission"
            FROM education.activity_definitions WHERE code=:code',
            [':code' => $code],
        );
    }

    public function activityDefinitionById(string $id): ?array
    {
        return $this->one(
            'SELECT id::text,code,name,kind,contract_version AS "contractVersion",enabled,config,metrics,required_permission AS "requiredPermission"
            FROM education.activity_definitions WHERE id=CAST(:id AS uuid)',
            [':id' => $id],
        );
    }

    public function studentAssignments(Scope $scope): array
    {
        return $this->all(
            'SELECT a.id::text,a.activity_id::text AS "activityId",d.code AS "activityCode",d.name AS "activityName",
            a.group_id::text AS "groupId",a.student_id::text AS "studentId",a.config,a.opens_at::text AS "opensAt",
            a.due_at::text AS "dueAt",a.completion FROM education.activity_assignments a
            JOIN education.activity_definitions d ON d.id=a.activity_id
            WHERE a.organization_id=CAST(:org AS uuid) AND (
                a.student_id=CAST(:student AS uuid)
                OR a.group_id IN (SELECT group_id FROM education.group_students
                    WHERE student_id=CAST(:student AS uuid) AND organization_id=CAST(:org AS uuid))
            ) ORDER BY a.created_at,a.id',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId],
        );
    }

    public function createAttempt(Scope $scope, string $activityId, ?string $assignmentId, string $source, string $exercise, array $config): array
    {
        return $this->db->transaction(function () use ($scope, $activityId, $assignmentId, $source, $exercise, $config): array {
            $attempt = $this->required(
                'INSERT INTO education.activity_attempts(organization_id,student_id,account_id,activity_id,assignment_id,status,source,exercise_text,config)
                VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:account AS uuid),CAST(:activity AS uuid),
                    :assignment, \'running\', :source, :exercise, CAST(:config AS jsonb))
                RETURNING id::text,student_id::text AS "studentId",activity_id::text AS "activityId",status,source,
                started_at::text AS "startedAt",exercise_text AS "exercise"',
                [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':account' => $scope->accountId,
                    ':activity' => $activityId, ':assignment' => $assignmentId, ':source' => $source,
                    ':exercise' => $exercise, ':config' => json_encode($config, JSON_THROW_ON_ERROR)],
            );
            return [...$attempt, 'config' => $config];
        });
    }

    public function attempt(Scope $scope, string $attemptId): ?array
    {
        return $this->one(
            'SELECT a.id::text,a.student_id::text AS "studentId",a.account_id::text AS "accountId",
            a.activity_id::text AS "activityId",d.code AS "activityCode",a.assignment_id::text AS "assignmentId",
            a.status,a.source,a.contract_version AS "contractVersion",a.exercise_text AS "exercise",a.config,
            a.started_at::text AS "startedAt",a.finished_at::text AS "finishedAt",a.exercise_payload AS "exercisePayload",
            a.client_state AS "state",a.state_version AS "stateVersion",a.revoked_at::text AS "revokedAt",r.metrics AS "result"
            FROM education.activity_attempts a JOIN education.activity_definitions d ON d.id=a.activity_id
            LEFT JOIN education.activity_results r ON r.attempt_id=a.id
            WHERE a.account_id=CAST(:account AS uuid) AND a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)',
            [':org' => $scope->organizationId, ':id' => $attemptId, ':student' => (string) $scope->studentId, ':account' => $scope->accountId],
        );
    }

    public function finishAttempt(Scope $scope, string $attemptId, string $typed, int $durationSeconds, array $metrics, array $payload): array
    {
        return $this->db->transaction(function () use ($scope, $attemptId, $typed, $durationSeconds, $metrics, $payload): array {
            $attempt = $this->one(
                'SELECT id::text,status,exercise_text AS "exercise",result_payload::text AS "payload" FROM education.activity_attempts
                WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)
                FOR UPDATE',
                [':org' => $scope->organizationId, ':id' => $attemptId, ':student' => (string) $scope->studentId],
            );
            if ($attempt === null) {
                throw new EducationError(404, 'not_found');
            }
            if ($attempt['status'] === 'finished') {
                $existing = $this->one(
                    'SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,
                    t.keystrokes,t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float
                    FROM education.typing_results t WHERE t.attempt_id=CAST(:id AS uuid)',
                    [':id' => $attemptId],
                );
                $stored = is_string($attempt['payload'] ?? null) ? json_decode((string) $attempt['payload'], true) : null;
                if ($existing === null || $existing['durationSeconds'] !== $durationSeconds
                    || (isset($stored['typedSha256']) ? !hash_equals((string) $stored['typedSha256'], hash('sha256', $typed))
                        : (int) ($stored['typedLength'] ?? -1) !== strlen($typed))) {
                    throw new EducationError(409, 'attempt_finished');
                }
                return $existing;
            }
            $this->db->createCommand(
                'UPDATE education.activity_attempts SET status=\'finished\',finished_at=now(),
                result_payload=CAST(:payload AS jsonb),result_version=1 WHERE id=CAST(:id AS uuid)',
                [':payload' => json_encode($payload, JSON_THROW_ON_ERROR), ':id' => $attemptId],
            )->execute();
            $this->required(
                'INSERT INTO education.typing_results(attempt_id,organization_id,student_id,duration_seconds,language,keystrokes,correct_chars,errors,wpm,accuracy)
                VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:student AS uuid),:duration,:language,:keystrokes,:correct,:errors,:wpm,:accuracy)
                RETURNING attempt_id::text AS "attemptId",duration_seconds AS "durationSeconds",language,keystrokes,
                correct_chars AS "correctChars",errors,wpm::float,accuracy::float',
                [':attempt' => $attemptId, ':org' => $scope->organizationId, ':student' => (string) $scope->studentId,
                    ':duration' => $durationSeconds, ':language' => $metrics['language'],
                    ':keystrokes' => $metrics['keystrokes'], ':correct' => $metrics['correct'],
                    ':errors' => $metrics['errors'], ':wpm' => $metrics['wpm'], ':accuracy' => $metrics['accuracy']],
            );
            return $this->one(
                'SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,
                t.keystrokes,t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float
                FROM education.typing_results t WHERE t.attempt_id=CAST(:id AS uuid)',
                [':id' => $attemptId],
            ) ?? throw new EducationError(409, 'write_not_confirmed');
        });
    }

    public function guardAssignmentAttempt(Scope $scope, string $assignmentId, int $maxAttempts, bool $fromHomework): void
    {
        $assignment = $this->required(
            'SELECT a.opens_at::text AS "opensAt",a.due_at::text AS "dueAt",
            EXISTS(SELECT 1 FROM education.homework_recipients r WHERE r.activity_assignment_id=a.id) AS homework
            FROM education.activity_assignments a WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid)
            AND (a.student_id=CAST(:student AS uuid) OR EXISTS(SELECT 1 FROM education.group_students gs WHERE gs.group_id=a.group_id
                AND gs.organization_id=a.organization_id AND gs.student_id=CAST(:student AS uuid))) FOR UPDATE OF a',
            [':id' => $assignmentId,':org' => $scope->organizationId,':student' => $scope->studentId],
        );
        $isHomework = in_array($assignment['homework'], [true,'t',1], true);
        if ($isHomework && !$fromHomework) {
            throw new EducationError(409, 'use_homework_route');
        }
        if (!$isHomework && $fromHomework) {
            throw new EducationError(422, 'invalid_input');
        }
        if (!$isHomework) {
            $now = new DateTimeImmutable();
            if (($assignment['opensAt'] !== null && new DateTimeImmutable($assignment['opensAt']) > $now)
                || ($assignment['dueAt'] !== null && new DateTimeImmutable($assignment['dueAt']) < $now)) {
                throw new EducationError(409, 'assignment_unavailable');
            }
            $count = (int) $this->db->createCommand(
                'SELECT count(*) FROM education.activity_attempts WHERE assignment_id=CAST(:id AS uuid)
                AND organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)',
                [':id' => $assignmentId,':org' => $scope->organizationId,':student' => $scope->studentId],
            )->queryScalar();
            if ($count >= $maxAttempts) {
                throw new EducationError(409, 'attempt_limit_reached');
            }
        }
    }

    public function exerciseSet(string $code, string $language, string $mode, int $difficulty): ?array
    {
        return $this->one('SELECT code,version,content FROM education.activity_exercise_sets
            WHERE activity_code=:code AND language=:language AND mode=:mode AND difficulty=:difficulty
            ORDER BY version DESC LIMIT 1', [':code' => $code, ':language' => $language, ':mode' => $mode, ':difficulty' => $difficulty]);
    }

    public function snapshotAttempt(Scope $scope, string $attemptId, int $version, array $exercise): void
    {
        $this->db->createCommand('UPDATE education.activity_attempts SET contract_version=:version,exercise_payload=CAST(:exercise AS jsonb)
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)
            AND account_id=CAST(:account AS uuid) AND status=\'running\'', [':id' => $attemptId,':org' => $scope->organizationId,
            ':student' => $scope->studentId,':account' => $scope->accountId,':version' => $version,':exercise' => json_encode($exercise, JSON_THROW_ON_ERROR)])->execute();
    }

    public function saveAttemptState(Scope $scope, string $attemptId, int $version, array $state): void
    {
        $changed = $this->db->createCommand(
            'UPDATE education.activity_attempts SET client_state=CAST(:state AS jsonb),state_version=state_version+1
            WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)
            AND account_id=CAST(:account AS uuid) AND status=\'running\' AND revoked_at IS NULL AND state_version=:version',
            [':id' => $attemptId,':org' => $scope->organizationId,':student' => $scope->studentId,':account' => $scope->accountId,
                ':state' => json_encode($state, JSON_THROW_ON_ERROR),':version' => $version],
        )->execute();
        if ($changed !== 1) {
            throw new EducationError(409, 'state_conflict');
        }
    }

    public function completeActivityAttempt(Scope $scope, string $attemptId, array $evaluation): array
    {
        return $this->db->transaction(function () use ($scope, $attemptId, $evaluation): array {
            $row = $this->required(
                'SELECT a.status,a.revoked_at::text AS "revokedAt",a.result_payload,a.contract_version AS "contractVersion"
                FROM education.activity_attempts a WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)
                AND a.account_id=CAST(:account AS uuid) FOR UPDATE OF a',
                [':id' => $attemptId,':org' => $scope->organizationId,':student' => $scope->studentId,':account' => $scope->accountId],
            );
            // Re-read the result AFTER the attempt row lock. A joined SELECT can retain
            // the pre-wait snapshot of the absent result during concurrent finishes.
            $stored = $this->one('SELECT request_hash AS "requestHash",metrics FROM education.activity_results WHERE attempt_id=CAST(:id AS uuid)', [':id' => $attemptId]);
            $row = [...$row,'requestHash' => $stored['requestHash'] ?? null,'metrics' => $stored['metrics'] ?? null];
            if ($row['revokedAt'] !== null) {
                throw new EducationError(409, 'attempt_revoked');
            }
            if ($row['status'] === 'finished') {
                if (is_string($row['requestHash'] ?? null) && hash_equals($row['requestHash'], $evaluation['requestHash'])) {
                    return ['attemptId' => $attemptId,...\App\Education\Application\ActivityHandlerRegistry::json($row['metrics'])];
                }
                // Completed v1 typing attempts retain their historical finish contract.
                if ($row['requestHash'] === null && $evaluation['type'] === 'typing' && (int) $row['contractVersion'] === 1) {
                    $payload = \App\Education\Application\ActivityHandlerRegistry::json($row['result_payload']);
                    if (isset($payload['typedSha256']) && hash_equals($payload['typedSha256'], $evaluation['evidence']['typedSha256'])
                        && (int) ($payload['durationSeconds'] ?? 0) === $evaluation['metrics']['durationSeconds']) {
                        return $this->required('SELECT attempt_id::text AS "attemptId",duration_seconds AS "durationSeconds",language,keystrokes,
                            correct_chars AS "correctChars",errors,wpm::float,accuracy::float FROM education.typing_results WHERE attempt_id=CAST(:id AS uuid)', [':id' => $attemptId]);
                    }
                }
                throw new EducationError(409, 'attempt_finished');
            }
            if ($row['status'] !== 'running') {
                throw new EducationError(409, 'attempt_not_running');
            }
            $metrics = $evaluation['metrics'];
            $params = [':attempt' => $attemptId,':org' => $scope->organizationId,':student' => $scope->studentId];
            $this->db->createCommand(
                'INSERT INTO education.activity_results(attempt_id,organization_id,student_id,type,version,request_hash,metrics,passed)
                VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:student AS uuid),:type,:version,:hash,CAST(:metrics AS jsonb),:passed)',
                [...$params,':type' => $evaluation['type'],':version' => $evaluation['version'],':hash' => $evaluation['requestHash'],
                    ':metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),':passed' => $metrics['passed'] ? 'true' : 'false'],
            )->execute();
            if ($evaluation['type'] === 'typing') {
                $this->db->createCommand(
                    'INSERT INTO education.typing_results(attempt_id,organization_id,student_id,duration_seconds,language,keystrokes,correct_chars,errors,wpm,accuracy)
                    VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:student AS uuid),:duration,:language,:chars,:correct,:errors,:wpm,:accuracy)',
                    [...$params,':duration' => $metrics['durationSeconds'],':language' => $metrics['language'],':chars' => $metrics['typedCharacters'],
                        ':correct' => $metrics['correctChars'],':errors' => $metrics['errors'],':wpm' => $metrics['wpm'],':accuracy' => $metrics['accuracy']],
                )->execute();
            } else {
                $this->db->createCommand(
                    'INSERT INTO education.logic_results(attempt_id,organization_id,student_id,correct_answers,total_rounds,score,accuracy)
                    VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:student AS uuid),:correct,:total,:score,:accuracy)',
                    [...$params,':correct' => $metrics['correctAnswers'],':total' => $metrics['totalRounds'],':score' => $metrics['score'],':accuracy' => $metrics['accuracy']],
                )->execute();
            }
            $this->db->createCommand(
                'UPDATE education.activity_attempts SET status=\'finished\',finished_at=now(),client_state=\'{}\'::jsonb,
                result_payload=CAST(:payload AS jsonb),result_version=:version WHERE id=CAST(:id AS uuid)',
                [':id' => $attemptId,':payload' => json_encode($evaluation['evidence'], JSON_THROW_ON_ERROR),':version' => $evaluation['version']],
            )->execute();
            return ['attemptId' => $attemptId,...$metrics];
        });
    }

    public function revokeActivityAttempt(Scope $scope, string $attemptId): array
    {
        return $this->required(
            'UPDATE education.activity_attempts a SET revoked_at=COALESCE(a.revoked_at,now())
            FROM education.students s WHERE a.id=CAST(:id AS uuid) AND a.student_id=s.id AND a.organization_id=s.organization_id
            AND a.organization_id=CAST(:org AS uuid) AND (s.owner_account_id=CAST(:actor AS uuid) OR EXISTS(
                SELECT 1 FROM education.group_students gs WHERE gs.student_id=s.id AND gs.organization_id=s.organization_id
                AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)))
            RETURNING a.id::text,a.student_id::text AS "studentId",a.revoked_at::text AS "revokedAt"',
            [':id' => $attemptId,':org' => $scope->organizationId,':actor' => $scope->actor()],
        );
    }

    public function studentAttempts(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT a.id::text,a.activity_id::text AS "activityId",d.code AS "activityCode",d.name AS "activityName",
            a.status,a.source,a.started_at::text AS "startedAt",a.finished_at::text AS "finishedAt",
            t.duration_seconds AS "durationSeconds",COALESCE(t.language,r.metrics->>\'language\') AS language,t.wpm::float,
            COALESCE(t.accuracy,(r.metrics->>\'accuracy\')::numeric)::float AS accuracy,t.errors,
            (r.metrics->>\'correctAnswers\')::int AS "correctAnswers",(r.metrics->>\'totalRounds\')::int AS "totalRounds",
            (r.metrics->>\'score\')::int AS score,r.passed,a.revoked_at::text AS "revokedAt"
            FROM education.activity_attempts a JOIN education.activity_definitions d ON d.id=a.activity_id
            LEFT JOIN education.typing_results t ON t.attempt_id=a.id
            LEFT JOIN education.activity_results r ON r.attempt_id=a.id
            WHERE a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)
            ORDER BY a.started_at DESC,a.id DESC LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':offset' => $offset],
        );
    }

    public function studentTypingResults(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,
            t.keystrokes,t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float,t.created_at::text AS "createdAt"
            FROM education.typing_results t JOIN education.activity_attempts a ON a.id=t.attempt_id
            WHERE a.status=\'finished\' AND a.revoked_at IS NULL
            AND t.organization_id=CAST(:org AS uuid) AND t.student_id=CAST(:student AS uuid)
            ORDER BY t.created_at DESC,t.attempt_id DESC LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId, ':offset' => $offset],
        );
    }

    public function bestTyping(Scope $scope): ?array
    {
        return $this->one(
            'SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,
            t.wpm::float,t.accuracy::float,t.errors,t.created_at::text AS "createdAt"
            FROM education.typing_results t JOIN education.activity_attempts a ON a.id=t.attempt_id
            WHERE a.status=\'finished\' AND a.revoked_at IS NULL
            AND t.organization_id=CAST(:org AS uuid) AND t.student_id=CAST(:student AS uuid)
            ORDER BY t.wpm DESC,t.created_at LIMIT 1',
            [':org' => $scope->organizationId, ':student' => (string) $scope->studentId],
        );
    }

    public function createAssignment(Scope $scope, string $activityId, ?string $groupId, ?string $studentId, array $config): array
    {
        return $this->db->transaction(function () use ($scope, $activityId, $groupId, $studentId, $config): array {
            if ($groupId !== null) {
                $this->required(
                    'SELECT g.id::text FROM education.groups g WHERE ' . self::where('g') . ' AND g.id=CAST(:id AS uuid) FOR UPDATE',
                    [...self::owner($scope), ':id' => $groupId],
                );
            }
            if ($studentId !== null) {
                $this->required(
                    'SELECT s.id::text FROM education.students s WHERE ' . self::where('s') . ' AND s.id=CAST(:id AS uuid) FOR UPDATE',
                    [...self::owner($scope), ':id' => $studentId],
                );
            }
            return $this->required(
                'INSERT INTO education.activity_assignments(organization_id,owner_account_id,activity_id,group_id,student_id,config)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:activity AS uuid),:group,:student,CAST(:config AS jsonb))
                RETURNING id::text,activity_id::text AS "activityId",group_id::text AS "groupId",student_id::text AS "studentId",config',
                [...self::owner($scope), ':activity' => $activityId, ':group' => $groupId, ':student' => $studentId,
                    ':config' => json_encode($config, JSON_THROW_ON_ERROR)],
            );
        });
    }

    public function assignments(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT a.id::text,a.activity_id::text AS "activityId",d.code AS "activityCode",d.name AS "activityName",
            a.group_id::text AS "groupId",g.name AS "groupName",a.student_id::text AS "studentId",s.person_id::text AS "studentPersonId",
            a.config,a.opens_at::text AS "opensAt",a.due_at::text AS "dueAt",a.completion,a.created_at::text AS "createdAt"
            FROM education.activity_assignments a JOIN education.activity_definitions d ON d.id=a.activity_id
            LEFT JOIN education.groups g ON g.id=a.group_id
            LEFT JOIN education.students s ON s.id=a.student_id
            WHERE a.organization_id=CAST(:org AS uuid) AND (a.owner_account_id=CAST(:actor AS uuid)
                OR education.can_teach_group(a.organization_id,CAST(:actor AS uuid),a.group_id)
                OR (a.group_id IS NULL AND EXISTS(SELECT 1 FROM education.group_students scope_gs
                    WHERE scope_gs.student_id=a.student_id AND education.can_teach_group(scope_gs.organization_id,CAST(:actor AS uuid),scope_gs.group_id))))
            ORDER BY a.created_at DESC,a.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':offset' => $offset],
        );
    }

    public function groupActivityResults(Scope $scope, string $groupId, string $activityCode, int $offset, ?string $from = null, ?string $to = null): array
    {
        $range = '';
        $params = [...self::owner($scope),':group' => $groupId,':code' => $activityCode,':offset' => $offset];
        if ($from !== null) {
            $range .= ' AND a.started_at>=CAST(:from AS timestamptz)';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $range .= ' AND a.started_at<CAST(:to AS timestamptz)';
            $params[':to'] = $to;
        }
        return $this->all('SELECT a.id::text,a.student_id::text AS "studentId",s.person_id::text AS "personId",a.status,a.source,
            a.started_at::text AS "startedAt",a.finished_at::text AS "finishedAt",a.revoked_at::text AS "revokedAt",
            t.duration_seconds AS "durationSeconds",COALESCE(t.language,r.metrics->>\'language\') AS language,t.keystrokes,t.correct_chars AS "correctChars",
            COALESCE(t.errors,(r.metrics->>\'errors\')::int) AS errors,t.wpm::float,COALESCE(t.accuracy,(r.metrics->>\'accuracy\')::numeric)::float AS accuracy,
            (r.metrics->>\'correctAnswers\')::int AS "correctAnswers",(r.metrics->>\'totalRounds\')::int AS "totalRounds",(r.metrics->>\'score\')::int AS score,r.passed
            FROM education.activity_attempts a JOIN education.activity_definitions d ON d.id=a.activity_id
            JOIN education.students s ON s.id=a.student_id AND s.organization_id=a.organization_id
            JOIN education.group_students gs ON gs.student_id=a.student_id AND gs.group_id=CAST(:group AS uuid)
            LEFT JOIN education.typing_results t ON t.attempt_id=a.id LEFT JOIN education.activity_results r ON r.attempt_id=a.id
            WHERE a.organization_id=CAST(:org AS uuid) AND d.code=:code
            AND gs.organization_id=CAST(:org AS uuid) AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)' . $range . '
            ORDER BY a.started_at DESC,a.id DESC LIMIT 51 OFFSET :offset', $params);
    }

    private function lockLesson(Scope $scope, string $lessonId): void
    {
        $this->db->createCommand(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            [':key' => 'education.lesson.' . $scope->organizationId . '.' . $scope->accountId . '.' . $lessonId],
        )->queryScalar();
    }

    private function lockSchedule(Scope $scope): void
    {
        $this->db->createCommand(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            [':key' => 'education.schedule.' . $scope->organizationId . '.' . $scope->accountId],
        )->queryScalar();
    }

    private function lockGroup(Scope $scope, string $groupId): void
    {
        $this->db->createCommand(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            [':key' => 'education.group.' . $scope->organizationId . '.' . $scope->accountId . '.' . $groupId],
        )->queryScalar();
    }

    /** @param list<string> $groupIds */
    private function lockGroups(Scope $scope, array $groupIds): void
    {
        $groupIds = array_values(array_unique($groupIds));
        sort($groupIds, SORT_STRING);
        foreach ($groupIds as $groupId) {
            $this->lockGroup($scope, $groupId);
        }
    }

    private function assertNoOverlap(Scope $scope, string $start, string $end, ?string $excludeId): void
    {
        $sql = "SELECT 1 FROM education.lessons l WHERE " . self::where('l')
            . " AND l.status='scheduled' AND l.starts_at<CAST(:end AS timestamptz)"
            . ' AND l.ends_at>CAST(:start AS timestamptz)';
        $params = [...self::owner($scope), ':start' => $start, ':end' => $end];
        if ($excludeId !== null) {
            $sql .= ' AND l.id<>CAST(:exclude AS uuid)';
            $params[':exclude'] = $excludeId;
        }
        if ($this->db->createCommand($sql . ' LIMIT 1', $params)->queryScalar() !== false) {
            throw new EducationError(409, 'schedule_conflict');
        }
    }

    private function addFutureAttendance(Scope $scope, string $studentId, string $groupId): void
    {
        $this->db->createCommand(
            'INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id)
            SELECT l.id,CAST(:student AS uuid),l.organization_id,l.owner_account_id FROM education.lessons l
            WHERE ' . self::where('l') . " AND l.group_id=CAST(:group AS uuid) AND l.starts_at>now()
            AND l.status='scheduled' ON CONFLICT DO NOTHING",
            [...self::owner($scope), ':student' => $studentId, ':group' => $groupId],
        )->execute();
    }

    private function deleteFutureAttendance(Scope $scope, string $studentId, string $groupId): void
    {
        $this->db->createCommand(
            'DELETE FROM education.attendance a USING education.lessons l
            WHERE a.lesson_id=l.id AND a.organization_id=CAST(:org AS uuid) AND (a.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=a.lesson_id AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id)))
            AND a.student_id=CAST(:student AS uuid) AND l.group_id=CAST(:group AS uuid)
            AND l.status=\'scheduled\' AND l.starts_at>now()',
            [...self::owner($scope), ':student' => $studentId, ':group' => $groupId],
        )->execute();
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,string> $values
     */
    private static function sameLessonValues(array $current, array $values): bool
    {
        return $current['groupId'] === $values['groupId']
            && $current['title'] === $values['title']
            && $current['location'] === $values['location']
            && self::instantKey((string) $current['startsAt']) === self::instantKey($values['startsAt'])
            && self::instantKey((string) $current['endsAt']) === self::instantKey($values['endsAt']);
    }

    private static function instantKey(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u');
        } catch (\Exception) {
            return $value;
        }
    }

    private static function where(string $alias): string
    {
        $own = "$alias.owner_account_id=CAST(:actor AS uuid)";
        $assigned = match ($alias) {
            'g' => "education.can_teach_group($alias.organization_id,CAST(:actor AS uuid),$alias.id)",
            'l' => "education.can_teach_group($alias.organization_id,CAST(:actor AS uuid),$alias.group_id)",
            's' => "EXISTS(SELECT 1 FROM education.group_students scope_gs WHERE scope_gs.student_id=$alias.id
                AND education.can_teach_group(scope_gs.organization_id,CAST(:actor AS uuid),scope_gs.group_id))",
            'a', 'r', 'f' => "EXISTS(SELECT 1 FROM education.lessons scope_l WHERE scope_l.id=$alias.lesson_id
                AND education.can_teach_group(scope_l.organization_id,CAST(:actor AS uuid),scope_l.group_id))",
            default => 'FALSE',
        };
        return "$alias.organization_id=CAST(:org AS uuid) AND ($own OR $assigned)";
    }

    /**
     * @param list<string> $ids
     */
    private static function in(string $prefix, array $ids): string
    {
        return implode(',', array_map(static fn(int $index): string => $prefix . $index, array_keys($ids)));
    }

    /**
     * @param list<string> $ids
     * @return array<string,string>
     */
    private static function inParams(string $prefix, array $ids): array
    {
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $params[$prefix . $index] = $id;
        }
        return $params;
    }

    /** @return array{':org':string,':actor':string} */
    private static function owner(Scope $scope): array
    {
        return [':org' => $scope->organizationId, ':actor' => $scope->accountId];
    }
    /** @return array{':org':string,':person':string} */
    private static function recipient(Scope $scope): array
    {
        return [':org' => $scope->organizationId, ':person' => $scope->personId];
    }

    /**
     * @param array<non-empty-string,string|int|null> $params
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $rows = $this->db->createCommand($sql, $params)->queryAll();
        /** @var list<array<string,mixed>> $rows */
        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<non-empty-string,string|int|null> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        /** @var array<string,mixed>|false $row */
        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * @param array<non-empty-string,string|int|null> $params
     * @return array<string,mixed>
     */
    private function required(string $sql, array $params): array
    {
        return $this->one($sql, $params) ?? throw new EducationError(404, 'not_found');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($key === 'curriculumSnapshot' && is_string($value)) {
                $row[$key] = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            }
            if (str_ends_with($key, 'At') && is_string($value)) {
                $row[$key] = (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP');
            }
            if (str_ends_with($key, 'Count') || $key === 'sizeBytes') {
                $row[$key] = (int) $value;
            }
            if (($key === 'wpm' || $key === 'accuracy') && is_string($value)) {
                $row[$key] = (float) $value;
            }
        }
        return $row;
    }
}
