<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Only persisted, active unlocks generate notices. No client award input. */
final readonly class ActivityAwardNotifications
{
    public function __construct(private ConnectionInterface $db, private ParentNotifications $parents) {}

    public function notify(Scope $scope, string $student): void
    {
        $rows = $this->db->createCommand(
            'SELECT u.id::text,s.person_id::text FROM education.achievement_unlocks u
            JOIN education.students s ON s.id=u.student_id AND s.organization_id=u.organization_id
            WHERE u.organization_id=CAST(:org AS uuid) AND u.student_id=CAST(:student AS uuid) AND u.revoked_at IS NULL',
            [':org' => $scope->organizationId, ':student' => $student],
        )->queryAll();
        foreach ($rows as $row) {
            $this->db->createCommand(
                'INSERT INTO education.notifications
                (organization_id,person_id,kind,resource_id,resource_path,subject,body,event_key)
                VALUES(CAST(:org AS uuid),CAST(:person AS uuid),\'achievement_unlocked\',CAST(:id AS uuid),
                    \'/student/achievements\',\'achievement_unlocked\',\'achievement_unlocked\',:key)
                ON CONFLICT(organization_id,person_id,event_key) WHERE event_key IS NOT NULL DO NOTHING',
                [':org' => $scope->organizationId, ':person' => $row['person_id'], ':id' => $row['id'],
                    ':key' => 'education:achievement:' . $row['id']],
            )->execute();
            $this->parents->achievement($scope, $student, $row['id']);
        }
    }
}
