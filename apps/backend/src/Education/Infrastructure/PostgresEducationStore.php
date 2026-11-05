<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\EducationError;
use App\Education\Application\EducationStore;
use App\Education\Application\Scope;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresEducationStore implements EducationStore
{
    private const string LESSON = 'SELECT l.id::text, l.group_id::text AS "groupId", g.name AS "groupName", l.title,
        l.starts_at::text AS "startsAt", l.ends_at::text AS "endsAt", l.location, l.status,
        (SELECT count(*) FROM education.attendance a WHERE a.lesson_id=l.id) AS "studentCount"
        FROM education.lessons l JOIN education.groups g ON g.id=l.group_id WHERE ';

    public function __construct(private ConnectionInterface $db) {}

    public function teacherOrganizations(string $accountId, string $personId): array
    {
        $rows = $this->all('SELECT organization_id::text AS id FROM education.teachers
            WHERE account_id=CAST(:account AS uuid) AND person_id=CAST(:person AS uuid) ORDER BY organization_id',
            [':account' => $accountId, ':person' => $personId]);
        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }

    public function teacherExists(Scope $scope): bool
    {
        return $this->one('SELECT id::text FROM education.teachers WHERE organization_id=CAST(:org AS uuid)
            AND account_id=CAST(:actor AS uuid) AND person_id=CAST(:person AS uuid)',
            [...self::owner($scope), ':person' => $scope->personId]) !== null;
    }

    public function grantTeacher(string $organizationId, string $accountId, string $personId): void
    {
        $this->db->createCommand('INSERT INTO education.teachers(organization_id,account_id,person_id)
            VALUES(CAST(:org AS uuid),CAST(:account AS uuid),CAST(:person AS uuid))
            ON CONFLICT(organization_id,account_id) DO NOTHING',
            [':org' => $organizationId, ':account' => $accountId, ':person' => $personId])->execute();
    }

    public function groups(Scope $scope, int $offset): array
    {
        return $this->all('SELECT g.id::text,g.name,(SELECT count(*) FROM education.group_students gs
            WHERE gs.group_id=g.id) AS "studentCount" FROM education.groups g WHERE ' . self::where('g') . '
            ORDER BY g.created_at,g.id LIMIT 51 OFFSET :offset', [...self::owner($scope), ':offset' => $offset]);
    }

    public function group(Scope $scope, string $id): ?array
    {
        return $this->one('SELECT g.id::text,g.name FROM education.groups g WHERE ' . self::where('g') . '
            AND g.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function createGroup(Scope $scope, string $name): array
    {
        return $this->required('INSERT INTO education.groups(organization_id,owner_account_id,owner_person_id,name)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:name) RETURNING id::text,name',
            [...self::owner($scope), ':person' => $scope->personId, ':name' => $name]);
    }

    public function students(Scope $scope, int $offset): array
    {
        return $this->all('SELECT s.id::text,s.person_id::text AS "personId",s.created_at::text AS "createdAt"
            FROM education.students s WHERE ' . self::where('s') . ' ORDER BY s.created_at,s.id LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':offset' => $offset]);
    }

    public function enrollStudent(Scope $scope, string $personId, string $groupId): array
    {
        return $this->db->transaction(function () use ($scope, $personId, $groupId): array {
            if ($this->group($scope, $groupId) === null) throw new EducationError(404, 'not_found');
            $student = $this->required('INSERT INTO education.students(organization_id,owner_account_id,person_id)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid))
                ON CONFLICT(organization_id,owner_account_id,person_id) DO UPDATE SET person_id=EXCLUDED.person_id
                RETURNING id::text,person_id::text AS "personId"',
                [...self::owner($scope), ':person' => $personId]);
            $params = [...self::owner($scope), ':student' => (string) $student['id'], ':group' => $groupId];
            $this->db->createCommand('INSERT INTO education.group_students(group_id,student_id,organization_id,owner_account_id)
                VALUES(CAST(:group AS uuid),CAST(:student AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid)) ON CONFLICT DO NOTHING', $params)->execute();
            $this->db->createCommand('INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id)
                SELECT l.id,CAST(:student AS uuid),l.organization_id,l.owner_account_id FROM education.lessons l
                WHERE ' . self::where('l') . ' AND l.group_id=CAST(:group AS uuid) AND l.starts_at>now()
                AND l.status=\'scheduled\' ON CONFLICT DO NOTHING', $params)->execute();
            return $student;
        });
    }

    public function lessons(Scope $scope, string $from, string $to): array
    {
        return $this->all(self::LESSON . self::where('l') . ' AND l.starts_at>=CAST(:from AS timestamptz)
            AND l.starts_at<CAST(:to AS timestamptz) ORDER BY l.starts_at,l.id LIMIT 501',
            [...self::owner($scope), ':from' => $from, ':to' => $to]);
    }

    public function lesson(Scope $scope, string $id): ?array
    {
        return $this->one(self::LESSON . self::where('l') . ' AND l.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function nextLesson(Scope $scope, string $now, bool $currentOnly): ?array
    {
        return $this->one(self::LESSON . self::where('l') . " AND l.status='scheduled' AND l.ends_at>CAST(:now AS timestamptz)"
            . ($currentOnly ? ' AND l.starts_at<=CAST(:now AS timestamptz)' : '') . ' ORDER BY l.starts_at,l.id LIMIT 1',
            [...self::owner($scope), ':now' => $now]);
    }

    public function createLesson(Scope $scope, array $values): array
    {
        return $this->db->transaction(function () use ($scope, $values): array {
            if ($this->group($scope, $values['groupId']) === null) throw new EducationError(404, 'not_found');
            $row = $this->required('INSERT INTO education.lessons(organization_id,owner_account_id,owner_person_id,group_id,title,starts_at,ends_at,location)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),CAST(:group AS uuid),:title,CAST(:start AS timestamptz),CAST(:end AS timestamptz),:location)
                RETURNING id::text', [...self::owner($scope), ':person' => $scope->personId, ':group' => $values['groupId'],
                ':title' => $values['title'], ':start' => $values['startsAt'], ':end' => $values['endsAt'], ':location' => $values['location']]);
            $id = (string) $row['id'];
            $this->db->createCommand('INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id)
                SELECT CAST(:id AS uuid),student_id,organization_id,owner_account_id FROM education.group_students
                WHERE group_id=CAST(:group AS uuid) AND organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid)',
                [...self::owner($scope), ':id' => $id, ':group' => $values['groupId']])->execute();
            return $this->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
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
        $this->required('UPDATE education.attendance a SET status=:status,updated_at=now() WHERE ' . self::where('a') . '
            AND lesson_id=CAST(:lesson AS uuid) AND student_id=CAST(:student AS uuid) RETURNING student_id::text AS id',
            [...self::owner($scope), ':lesson' => $lessonId, ':student' => $studentId, ':status' => $status]);
    }

    public function events(Scope $scope, string $from, string $to): array
    {
        return $this->all('SELECT e.id::text,e.title,e.kind,e.location,e.starts_at::text AS "startsAt",e.ends_at::text AS "endsAt"
            FROM education.events e WHERE ' . self::where('e') . ' AND starts_at>=CAST(:from AS timestamptz)
            AND starts_at<CAST(:to AS timestamptz) ORDER BY starts_at,id LIMIT 501',
            [...self::owner($scope), ':from' => $from, ':to' => $to]);
    }

    public function createEvent(Scope $scope, array $values): array
    {
        return $this->required('INSERT INTO education.events(organization_id,owner_account_id,owner_person_id,title,kind,location,starts_at,ends_at)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:title,:kind,:location,CAST(:start AS timestamptz),CAST(:end AS timestamptz))
            RETURNING id::text,title,kind,location,starts_at::text AS "startsAt",ends_at::text AS "endsAt"',
            [...self::owner($scope), ':person' => $scope->personId, ':title' => $values['title'], ':kind' => $values['kind'],
                ':location' => $values['location'], ':start' => $values['startsAt'], ':end' => $values['endsAt']]);
    }

    public function files(Scope $scope, ?string $lessonId, int $offset): array
    {
        return $this->all('SELECT f.id::text,f.lesson_id::text AS "lessonId",f.name,f.mime,f.size_bytes AS "sizeBytes",f.created_at::text AS "createdAt"
            FROM education.files f WHERE ' . self::where('f') . " AND f.purpose='material'" . ($lessonId === null ? '' : ' AND lesson_id=CAST(:lesson AS uuid)') . '
            ORDER BY f.created_at,f.id LIMIT 51 OFFSET :offset', [...self::owner($scope), ':offset' => $offset,
                ...($lessonId === null ? [] : [':lesson' => $lessonId])]);
    }

    public function file(Scope $scope, string $id): ?array
    {
        return $this->one('SELECT f.id::text,f.lesson_id::text AS "lessonId",f.name,f.mime,f.purpose,f.size_bytes AS "sizeBytes",f.sha256,f.storage_key::text AS "storageKey"
            FROM education.files f WHERE f.owner_account_id=CAST(:actor AS uuid) AND (f.organization_id=CAST(:org AS uuid) OR f.purpose=\'avatar\') AND f.id=CAST(:id AS uuid)', [...self::owner($scope), ':id' => $id]);
    }

    public function createFile(Scope $scope, array $values): array
    {
        return $this->required('INSERT INTO education.files(organization_id,owner_account_id,owner_person_id,lesson_id,name,mime,purpose,size_bytes,sha256,storage_key)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),CAST(:lesson AS uuid),:name,:mime,:purpose,:size,:hash,CAST(:key AS uuid))
            RETURNING id::text,name,mime,size_bytes AS "sizeBytes"', [...self::owner($scope), ':person' => $scope->personId,
                ':lesson' => $values['lessonId'], ':name' => $values['name'], ':mime' => $values['mime'], ':purpose' => $values['purpose'],
                ':size' => $values['sizeBytes'], ':hash' => $values['sha256'], ':key' => $values['storageKey']]);
    }

    public function profile(Scope $scope): ?array
    {
        return $this->one('SELECT id::text,display_name AS "displayName",avatar_file_id::text AS "avatarFileId"
            FROM education.profiles WHERE account_id=CAST(:actor AS uuid) AND person_id=CAST(:person AS uuid)',
            [':actor' => $scope->accountId, ':person' => $scope->personId]);
    }

    public function saveProfile(Scope $scope, ?string $displayName, ?string $avatarFileId): void
    {
        $this->db->createCommand('INSERT INTO education.profiles(account_id,person_id,display_name,avatar_file_id)
            VALUES(CAST(:actor AS uuid),CAST(:person AS uuid),:name,CAST(:file AS uuid))
            ON CONFLICT(account_id) DO UPDATE SET display_name=EXCLUDED.display_name,avatar_file_id=EXCLUDED.avatar_file_id',
            [':actor' => $scope->accountId, ':person' => $scope->personId, ':name' => $displayName, ':file' => $avatarFileId])->execute();
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
        return $this->one('SELECT t.id::text,t.subject FROM education.threads t JOIN education.thread_members tm ON tm.thread_id=t.id
            WHERE t.organization_id=CAST(:org AS uuid) AND tm.person_id=CAST(:person AS uuid) AND t.id=CAST(:id AS uuid)',
            [...self::recipient($scope), ':id' => $id]);
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
        $rows = $this->all('SELECT peer.person_id::text AS id FROM education.thread_members peer
            JOIN education.thread_members self ON self.thread_id=peer.thread_id
            WHERE peer.organization_id=CAST(:org AS uuid) AND self.person_id=CAST(:person AS uuid)
            AND peer.person_id<>CAST(:person AS uuid) AND peer.thread_id=CAST(:id AS uuid) ORDER BY peer.person_id',
            [...self::recipient($scope), ':id' => $id]);
        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }

    public function startThread(Scope $scope, string $recipientPersonId, string $subject, string $body): array
    {
        return $this->db->transaction(function () use ($scope, $recipientPersonId, $subject, $body): array {
            $thread = $this->required('INSERT INTO education.threads(organization_id,subject)
                VALUES(CAST(:org AS uuid),:subject) RETURNING id::text,subject', [':org' => $scope->organizationId, ':subject' => $subject]);
            $id = (string) $thread['id'];
            foreach ([$scope->personId, $recipientPersonId] as $personId) {
                $this->db->createCommand('INSERT INTO education.thread_members(thread_id,organization_id,person_id)
                    VALUES(CAST(:id AS uuid),CAST(:org AS uuid),CAST(:person AS uuid))',
                    [':id' => $id, ':org' => $scope->organizationId, ':person' => $personId])->execute();
            }
            $this->sendMessage($scope, $id, $body);
            return $thread;
        });
    }

    public function sendMessage(Scope $scope, string $threadId, string $body): array
    {
        return $this->db->transaction(function () use ($scope, $threadId, $body): array {
            if ($this->thread($scope, $threadId) === null) throw new EducationError(404, 'not_found');
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
        return $this->all('SELECT n.id::text,n.thread_id::text AS "threadId",t.subject,n.created_at::text AS "createdAt",n.read_at::text AS "readAt"
            FROM education.notifications n JOIN education.threads t ON t.id=n.thread_id WHERE n.organization_id=CAST(:org AS uuid)
            AND n.person_id=CAST(:person AS uuid) ORDER BY n.created_at DESC,n.id LIMIT 51 OFFSET :offset',
            [...self::recipient($scope), ':offset' => $offset]);
    }

    public function unreadCount(Scope $scope): int
    {
        return (int) $this->db->createCommand('SELECT count(*) FROM education.notifications
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND read_at IS NULL', self::recipient($scope))->queryScalar();
    }

    public function readNotification(Scope $scope, string $id): void
    {
        $this->required('UPDATE education.notifications SET read_at=COALESCE(read_at,now())
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid) AND id=CAST(:id AS uuid) RETURNING id::text',
            [...self::recipient($scope), ':id' => $id]);
    }

    public function attention(Scope $scope, string $from, string $to): array
    {
        return $this->all('SELECT l.id::text,l.title,count(*) AS "absentCount" FROM education.lessons l
            JOIN education.attendance a ON a.lesson_id=l.id WHERE ' . self::where('l') . " AND a.status='absent'
            AND l.status<>'cancelled' AND l.starts_at>=CAST(:from AS timestamptz) AND l.starts_at<CAST(:to AS timestamptz)
            GROUP BY l.id,l.title,l.starts_at ORDER BY l.starts_at,l.id LIMIT 501",
            [...self::owner($scope), ':from' => $from, ':to' => $to]);
    }

    private static function where(string $alias): string
    {
        return "$alias.organization_id=CAST(:org AS uuid) AND $alias.owner_account_id=CAST(:actor AS uuid)";
    }

    /** @return array{':org':string,':actor':string} */
    private static function owner(Scope $scope): array { return [':org' => $scope->organizationId, ':actor' => $scope->accountId]; }
    /** @return array{':org':string,':person':string} */
    private static function recipient(Scope $scope): array { return [':org' => $scope->organizationId, ':person' => $scope->personId]; }

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
            if (str_ends_with($key, 'At') && is_string($value)) $row[$key] = (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP');
            if (str_ends_with($key, 'Count') || $key === 'sizeBytes') $row[$key] = (int) $value;
        }
        return $row;
    }
}
