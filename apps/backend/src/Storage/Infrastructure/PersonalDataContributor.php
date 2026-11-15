<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\PagedPersonalDataContributor as Contributor;
use App\Storage\Application\StorageFileAdapter;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PersonalDataContributor implements Contributor
{
    public function __construct(private ConnectionInterface $db, private StorageFileAdapter $files) {}

    public function code(): string
    {
        return 'storage.export';
    }

    public function categories(): array
    {
        return ['storage'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $records = [];
        foreach ($this->pages($context) as $page) {
            foreach ($page->records as $record) $records[] = $record;
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }

    public function pages(PersonalDataExportContext $context): iterable
    {
        $queries = [
            'transfer' => 'SELECT tr.id::text,tr.ticket_id::text AS "ticketId",tr.direction,tr.path,tr.status,tr.mode,
                tr.expected_size AS "expectedSize",tr.expected_sha256 AS "expectedSha256",tr.created_at::text AS "createdAt",
                tr.expires_at::text AS "expiresAt",tr.verified_at::text AS "verifiedAt"
                FROM storage.transfers tr JOIN storage.spaces s ON s.id=tr.space_id WHERE s.subject_person_id=CAST(:subject AS uuid)',
            'transfer_part' => 'SELECT tr.id::text AS "transferId",p.part_number AS "partNumber",p.sha256,p.size_bytes AS "sizeBytes"
                FROM storage.transfer_parts p JOIN storage.transfers tr ON tr.id=p.transfer_id
                JOIN storage.spaces s ON s.id=tr.space_id WHERE s.subject_person_id=CAST(:subject AS uuid)',
            'space' => 'SELECT s.id::text, s.organization_id::text AS "organizationId", s.student_id::text AS "studentId",
                s.max_files AS "maxFiles", s.max_bytes AS "maxBytes", s.created_at::text AS "createdAt"
                FROM storage.spaces s
                WHERE s.subject_person_id = CAST(:subject AS uuid)',
            'file' => 'SELECT f.id::text, f.space_id::text AS "spaceId", f.path, f.deleted_at::text AS "deletedAt",
                f.created_at::text AS "createdAt"
                FROM storage.files f
                JOIN storage.spaces s ON s.id = f.space_id
                WHERE s.subject_person_id = CAST(:subject AS uuid)',
            'version' => 'SELECT v.id::text, v.file_id::text AS "fileId", v.version, v.size_bytes AS "sizeBytes",
                v.sha256,v.storage_key::text AS "storageKey", v.created_at::text AS "createdAt"
                FROM storage.versions v
                JOIN storage.files f ON f.id = v.file_id
                JOIN storage.spaces s ON s.id = f.space_id
                WHERE s.subject_person_id = CAST(:subject AS uuid)',
            'manifest' => 'SELECT m.id::text, m.space_id::text AS "spaceId", m.version, m.status,
                m.created_at::text AS "createdAt", m.confirmed_at::text AS "confirmedAt"
                FROM storage.manifests m
                JOIN storage.spaces s ON s.id = m.space_id
                WHERE s.subject_person_id = CAST(:subject AS uuid)',
            'sync_session' => 'SELECT ss.id::text, ss.space_id::text AS "spaceId", ss.device_id::text AS "deviceId",
                ss.direction, ss.status, ss.created_at::text AS "createdAt", ss.finished_at::text AS "finishedAt"
                FROM storage.sync_sessions ss
                JOIN storage.spaces s ON s.id = ss.space_id
                WHERE s.subject_person_id = CAST(:subject AS uuid)',
        ];
        foreach ($queries as $type => $sql) {
            $offset = 0;
            do {
                $rows = $this->db->createCommand($sql . ' ORDER BY 1 LIMIT 100 OFFSET :offset',
                    [':subject' => $context->subjectPersonId, ':offset' => $offset])->queryAll();
                $records = [];
                foreach ($rows as $row) {
                    $key = $row['storageKey'] ?? null;
                    unset($row['storageKey']);
                    $records[] = ['type' => $type, ...$row];
                    if ($type === 'version' && is_string($key)) {
                        if ($records !== []) {
                            yield new PersonalDataSection($this->code(), $this->categories(), $records);
                            $records = [];
                        }
                        $stream = $this->files->open($key);
                        $hash = hash_init('sha256');
                        $size = 0;
                        $chunk = 0;
                        try {
                            while (!feof($stream)) {
                                $bytes = fread($stream, 4096);
                                if ($bytes === false) throw new DomainException('Storage export read failed.');
                                if ($bytes === '') break;
                                hash_update($hash, $bytes);
                                $size += strlen($bytes);
                                $records[] = ['type' => 'content_chunk', 'versionId' => $row['id'], 'chunk' => $chunk++,
                                    'encoding' => 'base64', 'content' => base64_encode($bytes)];
                                if (count($records) === 50) {
                                    yield new PersonalDataSection($this->code(), $this->categories(), $records);
                                    $records = [];
                                }
                            }
                        } finally {
                            fclose($stream);
                        }
                        if ($size !== (int) $row['sizeBytes'] || !hash_equals((string) $row['sha256'], hash_final($hash))) {
                            throw new DomainException('Storage export content failed checksum verification.');
                        }
                    }
                }
                if ($records !== []) yield new PersonalDataSection($this->code(), $this->categories(), $records);
                $offset += count($rows);
            } while (count($rows) === 100);
        }

    }
}

