<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Explicit family audience in the existing Education notification center. */
final readonly class ParentNotifications
{
    public function __construct(private ConnectionInterface $db) {}

    public function activity(Scope $actor, string $student, string $attempt): void
    {
        foreach ($this->audience($actor, $student) as $guardian) {
            $this->notify(
                $actor,
                $student,
                $guardian,
                'activity_finished',
                $attempt,
                'activity_finished',
                'parent:activity:' . $attempt,
                'progress',
            );
        }
    }

    public function achievement(Scope $actor, string $student, string $unlock): void
    {
        foreach ($this->audience($actor, $student) as $guardian) {
            $this->notify(
                $actor,
                $student,
                $guardian,
                'achievement_unlocked',
                $unlock,
                'achievement_unlocked',
                'parent:achievement:' . $unlock,
                'progress',
            );
        }
    }

    public function family(Scope $actor, string $student, string $guardian, string $event): void
    {
        if (!in_array($event, ['added', 'updated', 'confirmed', 'revoked', 'invited', 'invitation_revoked', 'relationship_changed'], true)) {
            throw new \InvalidArgumentException('Unsupported family notification event.');
        }
        // Invitation codes, names, contact details and actor comments never enter the center.
        $this->notify(
            $actor,
            $student,
            $guardian,
            'family_' . $event,
            $student,
            'family_' . $event,
            'family:' . $event . ':' . bin2hex(random_bytes(12)),
            'notifications',
        );
    }

    public function homework(Scope $actor, string $event, array $assignment, ?array $submission = null): void
    {
        if (!in_array($event, ['published', 'recipient_added', 'deadline_changed', 'reviewed', 'returned'], true)) {
            return;
        }
        $params = [':assignment' => $assignment['id'], ':org' => $actor->organizationId];
        $where = '';
        if ($submission !== null) {
            $where = ' AND r.id=CAST(:recipient AS uuid)';
            $params[':recipient'] = $submission['recipientId'];
        }
        $rows = $this->db->createCommand('SELECT r.student_id::text FROM education.homework_recipients r
            WHERE r.assignment_id=CAST(:assignment AS uuid) AND r.organization_id=CAST(:org AS uuid)' . $where, $params)->queryAll();
        foreach ($rows as $row) {
            $student = (string) $row['student_id'];
            foreach ($this->audience($actor, $student) as $guardian) {
                $kind = $event === 'recipient_added' ? 'published' : $event;
                $key = 'parent:homework:' . $kind . ':' . $assignment['id'] . ':' . $student . ':'
                    . ($submission['version'] ?? $assignment['version']);
                $this->notify(
                    $actor,
                    $student,
                    $guardian,
                    'homework_' . $kind,
                    (string) $assignment['id'],
                    'homework_' . $kind,
                    $key,
                    'homework',
                );
            }
        }
    }

    /** @param list<string> $previousStudents Includes students removed by a group change. */
    public function schedule(Scope $actor, array $lesson, array $previousStudents = []): void
    {
        $students = $this->db->createCommand(
            'SELECT student_id::text FROM education.attendance
            WHERE lesson_id=CAST(:lesson AS uuid) AND organization_id=CAST(:org AS uuid)',
            [':lesson' => $lesson['id'], ':org' => $actor->organizationId],
        )->queryColumn();
        $revision = substr(hash('sha256', json_encode(array_intersect_key(
            $lesson,
            array_flip(['title', 'groupId', 'startsAt', 'endsAt', 'location', 'status']),
        ), JSON_THROW_ON_ERROR)), 0, 32);
        foreach (array_unique([...$students, ...$previousStudents]) as $student) {
            foreach ($this->audience($actor, (string) $student) as $guardian) {
                $this->notify(
                    $actor,
                    (string) $student,
                    $guardian,
                    'schedule_changed',
                    (string) $lesson['id'],
                    'schedule_changed',
                    'parent:schedule:' . $lesson['id'] . ':' . $student . ':' . $revision,
                    'diary',
                );
            }
        }
    }

    /** @return list<string> */
    private function audience(Scope $actor, string $student): array
    {
        return $this->db->createCommand(
            "SELECT DISTINCT g.guardian_person_id::text
            FROM education.student_guardians g
            JOIN education.parent_accounts pa ON pa.organization_id=g.organization_id
                AND pa.guardian_person_id=g.guardian_person_id AND pa.revoked_at IS NULL
            JOIN core.accounts a ON a.id=pa.account_id AND a.person_id=g.guardian_person_id
                AND a.status='active' AND a.archived_at IS NULL
            JOIN core.persons p ON p.id=g.guardian_person_id AND p.status='active'
            JOIN education.students s ON s.id=g.student_id AND s.organization_id=g.organization_id
            JOIN core.persons child ON child.id=s.person_id AND child.status='active'
            WHERE g.organization_id=CAST(:org AS uuid) AND g.student_id=CAST(:student AS uuid) AND g.status='confirmed'
            AND EXISTS(SELECT 1 FROM core.organization_memberships m WHERE m.organization_id=g.organization_id
                AND m.person_id=g.guardian_person_id AND m.status='active')
            AND EXISTS(SELECT 1 FROM core.organization_memberships m WHERE m.organization_id=g.organization_id
                AND m.person_id=s.person_id AND m.status='active')",
            [':org' => $actor->organizationId, ':student' => $student],
        )->queryColumn();
    }

    private function notify(
        Scope $actor,
        string $student,
        string $guardian,
        string $event,
        string $resource,
        string $subject,
        string $key,
        string $section,
    ): void {
        $this->db->createCommand(
            'INSERT INTO education.notifications
            (organization_id,person_id,parent_student_id,kind,resource_id,resource_path,subject,body,event_key)
            VALUES(CAST(:org AS uuid),CAST(:person AS uuid),CAST(:student AS uuid),:kind,CAST(:resource AS uuid),:path,:subject,:body,:key)
            ON CONFLICT(organization_id,person_id,event_key) WHERE event_key IS NOT NULL DO NOTHING',
            [':org' => $actor->organizationId, ':person' => $guardian, ':student' => $student,
                ':kind' => 'parent_' . $event, ':resource' => $resource,
                ':path' => '/parent/' . $section . '?child=' . rawurlencode($student) . '&organization=' . rawurlencode($actor->organizationId),
                ':subject' => $subject, ':body' => $event, ':key' => $key],
        )->execute();
    }
}
