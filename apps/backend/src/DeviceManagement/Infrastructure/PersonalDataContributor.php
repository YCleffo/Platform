<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor as Contributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PersonalDataContributor implements Contributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'device_management.export';
    }

    public function categories(): array
    {
        return ['device_management'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $queries = [
            'device' => 'SELECT d.id::text,d.organization_id::text AS "organizationId",d.name,d.number,d.status,
                d.enrollment_state AS "enrollmentState",d.last_seen_at::text AS "lastSeenAt",
                d.created_at::text AS "createdAt",c.name AS "classroomName"
                FROM device_management.devices d LEFT JOIN device_management.classrooms c ON c.id=d.classroom_id
                WHERE d.owner_person_id=CAST(:subject AS uuid)
                    OR d.owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'classroom' => 'SELECT id::text,name,created_at::text AS "createdAt"
                FROM device_management.classrooms
                WHERE owner_person_id=CAST(:subject AS uuid)
                    OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'session_as_student' => 'SELECT s.id::text,s.device_id::text AS "deviceId",s.status,
                s.desired_status AS "desiredStatus",s.started_at::text AS "startedAt",s.ended_at::text AS "endedAt"
                FROM device_management.device_sessions s
                JOIN education.students st ON st.id=s.student_id
                WHERE st.person_id=CAST(:subject AS uuid)',
            'session_as_owner' => 'SELECT s.id::text,s.device_id::text AS "deviceId",s.student_id::text AS "studentId",
                s.status,s.desired_status AS "desiredStatus",s.started_at::text AS "startedAt",s.ended_at::text AS "endedAt"
                FROM device_management.device_sessions s
                WHERE s.owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'event' => 'SELECT e.id::text,e.device_id::text AS "deviceId",e.kind,e.payload,
                e.created_at::text AS "createdAt"
                FROM device_management.device_events e
                JOIN device_management.devices d ON d.id=e.device_id
                WHERE d.owner_person_id=CAST(:subject AS uuid)
                    OR d.owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'enrollment' => 'SELECT e.id::text,e.device_id::text AS "deviceId",e.expires_at::text AS "expiresAt",
                e.used_at::text AS "usedAt",e.created_at::text AS "createdAt"
                FROM device_management.device_enrollments e
                JOIN device_management.devices d ON d.id=e.device_id
                WHERE d.owner_person_id=CAST(:subject AS uuid)
                    OR d.owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'credential' => 'SELECT c.id::text,c.device_id::text AS "deviceId",c.revoked_at::text AS "revokedAt",
                c.created_at::text AS "createdAt"
                FROM device_management.device_credentials c
                JOIN device_management.devices d ON d.id=c.device_id
                WHERE d.owner_person_id=CAST(:subject AS uuid)
                    OR d.owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
        ];
        $records = [];
        foreach ($queries as $type => $sql) {
            $rows = $this->db->createCommand($sql . ' ORDER BY 1 LIMIT 1001',
                [':subject' => $context->subjectPersonId])->queryAll();
            if (count($rows) > 1000) {
                throw new DomainException('DeviceManagement export requires a dedicated large-export workflow.');
            }
            /** @var list<array<string,mixed>> $rows */
            foreach ($rows as $row) {
                $records[] = ['type' => $type, ...$row];
            }
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }
}
