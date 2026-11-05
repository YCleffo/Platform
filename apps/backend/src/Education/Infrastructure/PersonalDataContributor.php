<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

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
        return 'education.workspace';
    }
    public function categories(): array
    {
        return ['education'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $queries = [
            'teacher' => 'SELECT id::text,organization_id::text AS "organizationId",created_at::text AS "createdAt"
                FROM education.teachers WHERE person_id=CAST(:subject AS uuid)',
            'profile' => 'SELECT id::text,display_name AS "displayName",avatar_file_id::text AS "avatarFileId"
                FROM education.profiles WHERE person_id=CAST(:subject AS uuid)',
            'enrollment' => 'SELECT id::text,organization_id::text AS "organizationId",created_at::text AS "createdAt"
                FROM education.students WHERE person_id=CAST(:subject AS uuid)',
            'attendance' => 'SELECT a.lesson_id::text AS "lessonId",a.student_id::text AS "studentId",a.status
                FROM education.attendance a JOIN education.students s ON s.id=a.student_id WHERE s.person_id=CAST(:subject AS uuid)',
            'group' => 'SELECT id::text,organization_id::text AS "organizationId",name FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid)',
            'lesson' => 'SELECT id::text,title,starts_at::text AS "startsAt",ends_at::text AS "endsAt",location,status
                FROM education.lessons WHERE owner_person_id=CAST(:subject AS uuid)',
            'event' => 'SELECT id::text,title,kind,starts_at::text AS "startsAt",ends_at::text AS "endsAt",location
                FROM education.events WHERE owner_person_id=CAST(:subject AS uuid)',
            'file' => 'SELECT id::text,name,mime,purpose,size_bytes AS "sizeBytes",sha256 FROM education.files WHERE owner_person_id=CAST(:subject AS uuid)',
            'message' => 'SELECT id::text,thread_id::text AS "threadId",body,created_at::text AS "createdAt"
                FROM education.messages WHERE sender_person_id=CAST(:subject AS uuid)',
            'notification' => 'SELECT id::text,thread_id::text AS "threadId",created_at::text AS "createdAt",read_at::text AS "readAt"
                FROM education.notifications WHERE person_id=CAST(:subject AS uuid)',
        ];
        $records = [];
        foreach ($queries as $type => $sql) {
            $rows = $this->db->createCommand($sql . ' LIMIT 1001', [':subject' => $context->subjectPersonId])->queryAll();
            if (count($rows) > 1000) {
                throw new DomainException('Education export requires a dedicated large-export workflow.');
            }
            /** @var list<array<string,mixed>> $rows */
            foreach ($rows as $row) {
                $records[] = ['type' => $type, ...$row];
            }
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }
}
