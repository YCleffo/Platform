<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageStore;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresStorageStore implements StorageStore
{
    private const MAX_FILE_SIZE = 5497558138880;

    public function __construct(private ConnectionInterface $db, private ?\App\Core\Privacy\Application\PrivacyResourceUsePolicy $privacy = null) {}

    /**
     * @param array<array-key,mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        /** @var array<string,mixed>|false $row */
        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * @param array<array-key,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $rows = $this->db->createCommand($sql, $params)->queryAll();
        /** @var list<array<string,mixed>> $rows */
        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with($key, 'At') && is_string($value)) {
                $row[$key] = (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP');
            }
            if (in_array($key, ['sizeBytes', 'maxFiles', 'maxBytes', 'version', 'serverVersion', 'deviceVersion', 'fileCount', 'totalBytes',
                'expectedSize', 'verifiedSize', 'baseVersion'], true)) {
                $row[$key] = $value === null ? null : (int) $value;
            }
        }
        return $row;
    }

    private function lockSpace(string $spaceId): void
    {
        $this->db->createCommand(
            'SELECT id FROM storage.spaces WHERE id=CAST(:id AS uuid) FOR UPDATE',
            [':id' => $spaceId],
        )->queryScalar();
    }

    public function space(StorageScope $scope): array
    {
        return $this->db->transaction(function () use ($scope): array {
            $space = $this->one(
                'SELECT id::text,organization_id::text AS "organizationId",student_id::text AS "studentId",
                account_id::text AS "accountId",subject_person_id::text AS "subjectPersonId",max_files AS "maxFiles",max_bytes AS "maxBytes",created_at::text AS "createdAt"
                FROM storage.spaces WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)',
                [':org' => $scope->organizationId, ':student' => $scope->studentId],
            );
            if ($space === null) {
                $space = $this->one(
                    'INSERT INTO storage.spaces(organization_id,student_id,account_id,subject_person_id)
                    SELECT CAST(:org AS uuid),st.id,coalesce(nullif(:account,\'\')::uuid,st.owner_account_id),st.person_id
                    FROM education.students st WHERE st.id=CAST(:student AS uuid) AND st.organization_id=CAST(:org AS uuid)
                    ON CONFLICT(organization_id,student_id) DO UPDATE SET subject_person_id=EXCLUDED.subject_person_id
                    RETURNING id::text,organization_id::text AS "organizationId",student_id::text AS "studentId",
                    account_id::text AS "accountId",subject_person_id::text AS "subjectPersonId",max_files AS "maxFiles",max_bytes AS "maxBytes",created_at::text AS "createdAt"',
                    [':org' => $scope->organizationId, ':student' => $scope->studentId, ':account' => $scope->accountId],
                ) ?? throw new StorageError(409, 'write_not_confirmed');
            }
            if ($this->privacy !== null && (!is_string($space['subjectPersonId'] ?? null)
                || !$this->privacy->allowed((string) $space['subjectPersonId'], 'storage.space', (string) $space['id'], 'storage'))) {
                throw new StorageError(403, 'processing_restricted');
            }
            $usage = $this->one(
                'SELECT count(*) AS "fileCount",coalesce(sum(v.size_bytes),0) AS "totalBytes"
                FROM storage.files f JOIN storage.versions v ON v.id=f.current_version_id
                WHERE f.space_id=CAST(:space AS uuid) AND f.deleted_at IS NULL',
                [':space' => $space['id']],
            ) ?? ['fileCount' => 0, 'totalBytes' => 0];
            return [...$space, ...$usage];
        });
    }

    public function files(StorageScope $scope, int $offset): array
    {
        $space = $this->space($scope);
        return $this->all(
            'SELECT f.id::text,f.path,f.deleted_at::text AS "deletedAt",v.version,v.size_bytes AS "sizeBytes",
            v.sha256,v.created_at::text AS "createdAt" FROM storage.files f
            LEFT JOIN storage.versions v ON v.id=f.current_version_id
            WHERE f.space_id=CAST(:space AS uuid) AND f.deleted_at IS NULL
            ORDER BY f.path LIMIT 51 OFFSET :offset',
            [':space' => $space['id'], ':offset' => $offset],
        );
    }

    public function spaceById(string $spaceId): ?array
    {
        return $this->one(
            'SELECT id::text,organization_id::text AS "organizationId",student_id::text AS "studentId",
            account_id::text AS "accountId",subject_person_id::text AS "subjectPersonId",max_files AS "maxFiles",max_bytes AS "maxBytes" FROM storage.spaces
            WHERE id=CAST(:id AS uuid)',
            [':id' => $spaceId],
        );
    }

    public function spaceByStudent(string $organizationId, string $studentId): ?array
    {
        return $this->one(
            'SELECT id::text,organization_id::text AS "organizationId",student_id::text AS "studentId",
            account_id::text AS "accountId",subject_person_id::text AS "subjectPersonId",max_files AS "maxFiles",max_bytes AS "maxBytes" FROM storage.spaces
            WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid)',
            [':org' => $organizationId, ':student' => $studentId],
        );
    }

    public function createTicket(StorageScope $scope, string $purpose, string $path, ?string $expectedSha256, ?int $expectedSize): array
    {
        $space = $this->space($scope);
        $versionId = null;
        if ($purpose === 'download') {
            $file = $this->fileByPath($scope, $path) ?? throw new StorageError(404, 'not_found');
            $versionId = (string) $file['versionId'];
            $expectedSha256 ??= (string) $file['sha256'];
            $expectedSize ??= (int) $file['sizeBytes'];
        }
        $ticket = $this->createDeviceTicket((string) $space['id'], $purpose, $path, $expectedSha256, $expectedSize, $versionId, null);
        $this->db->createCommand(
            'UPDATE storage.tickets SET actor_account_id=CAST(:actor AS uuid) WHERE id=CAST(:id AS uuid)',
            [':actor' => $scope->accountId, ':id' => $ticket['id']],
        )->execute();
        return $ticket;
    }

    public function createDeviceTicket(
        string $spaceId,
        string $purpose,
        string $path,
        ?string $expectedSha256,
        ?int $expectedSize,
        ?string $fileVersionId = null,
        ?string $deviceId = null,
    ): array {
        $token = bin2hex(random_bytes(24));
        $ticket = $this->one(
            'INSERT INTO storage.tickets(space_id,purpose,path,token_hash,expires_at,expected_sha256,expected_size,
                file_version_id,device_id,base_version_id,base_deleted_at)
            SELECT CAST(:space AS uuid),:purpose,:path,:hash,:expires,:expected_sha,:expected_size,
                CAST(:version AS uuid),CAST(:device AS uuid),f.current_version_id,f.deleted_at
            FROM (SELECT 1) seed LEFT JOIN storage.files f ON f.space_id=CAST(:space AS uuid) AND f.path=:path
            RETURNING id::text,space_id::text AS "spaceId",purpose,path,expires_at::text AS "expiresAt",
                expected_sha256 AS "expectedSha256",expected_size AS "expectedSize",
                file_version_id::text AS "fileVersionId",device_id::text AS "deviceId"',
            [':space' => $spaceId, ':purpose' => $purpose, ':path' => $path, ':hash' => hash('sha256', $token),
                ':expires' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+10 minutes')
                    ->format(DateTimeImmutable::ATOM),
                ':expected_sha' => $expectedSha256, ':expected_size' => $expectedSize,
                ':version' => $fileVersionId, ':device' => $deviceId],
        ) ?? throw new StorageError(409, 'write_not_confirmed');
        return [...$ticket, 'token' => $token];
    }

    public function findTicket(string $tokenHash): ?array
    {
        return $this->one(
            'SELECT id::text,space_id::text AS "spaceId",purpose,path,used_at::text AS "usedAt",
            expires_at::text AS "expiresAt",expected_sha256 AS "expectedSha256",expected_size AS "expectedSize",
            file_version_id::text AS "fileVersionId",device_id::text AS "deviceId"
            FROM storage.tickets WHERE token_hash=:hash',
            [':hash' => $tokenHash],
        );
    }

    public function claimTicket(string $ticketId): bool
    {
        $affected = $this->db->createCommand(
            'UPDATE storage.tickets SET used_at=now()
            WHERE id=CAST(:id AS uuid) AND used_at IS NULL AND expires_at>now()',
            [':id' => $ticketId],
        )->execute();
        return $affected === 1;
    }

    public function fileByPath(StorageScope $scope, string $path): ?array
    {
        $space = $this->space($scope);
        return $this->one(
            'SELECT f.id::text,f.path,v.id::text AS "versionId",v.version,v.size_bytes AS "sizeBytes",v.sha256,
            v.storage_key::text AS "storageKey",v.created_at::text AS "createdAt"
            FROM storage.files f JOIN storage.versions v ON v.id=f.current_version_id
            WHERE f.space_id=CAST(:space AS uuid) AND f.path=:path AND f.deleted_at IS NULL',
            [':space' => $space['id'], ':path' => $path],
        );
    }

    public function fileVersionByIdentifier(StorageScope $scope, string $path, ?string $sha256 = null, ?int $size = null): ?array
    {
        $file = $this->fileByPath($scope, $path);
        if ($file === null) {
            return null;
        }
        if ($sha256 !== null && !hash_equals((string) $file['sha256'], strtolower($sha256))) {
            return null;
        }
        if ($size !== null && (int) $file['sizeBytes'] !== $size) {
            return null;
        }
        return $file;
    }

    public function versionById(StorageScope $scope, string $versionId): ?array
    {
        $space = $this->space($scope);
        return $this->one(
            'SELECT v.id::text AS "versionId",v.version,v.size_bytes AS "sizeBytes",v.sha256,
            v.storage_key::text AS "storageKey",f.path
            FROM storage.versions v JOIN storage.files f ON f.id=v.file_id
            WHERE f.space_id=CAST(:space AS uuid) AND v.id=CAST(:version AS uuid)',
            [':space' => $space['id'], ':version' => $versionId],
        );
    }

    public function versionByNumber(StorageScope $scope, string $fileId, int $version): ?array
    {
        $space = $this->space($scope);
        return $this->one(
            'SELECT v.id::text, v.file_id::text AS "fileId", v.version, v.size_bytes AS "sizeBytes",
            v.sha256, v.storage_key::text AS "storageKey", v.created_at::text AS "createdAt"
            FROM storage.versions v
            JOIN storage.files f ON f.id=v.file_id
            WHERE f.space_id=CAST(:space AS uuid) AND v.file_id=CAST(:fileId AS uuid) AND v.version=:version',
            [':space' => $space['id'], ':fileId' => $fileId, ':version' => $version],
        );
    }

    public function fileVersions(StorageScope $scope, string $path): array
    {
        $space = $this->space($scope);
        return $this->all(
            'SELECT v.id::text, v.version, v.size_bytes AS "sizeBytes", v.sha256, v.created_at::text AS "createdAt"
            FROM storage.versions v
            JOIN storage.files f ON f.id=v.file_id
            WHERE f.space_id=CAST(:space AS uuid) AND f.path=:path
            ORDER BY v.version DESC',
            [':space' => $space['id'], ':path' => $path],
        );
    }

    public function restoreVersion(StorageScope $scope, string $path, int $version, string $newStorageKey): array
    {
        return $this->db->transaction(function () use ($scope, $path, $version, $newStorageKey): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $file = $this->one(
                'SELECT id::text,path,current_version_id::text AS "currentVersionId" FROM storage.files
                WHERE space_id=CAST(:space AS uuid) AND path=:path FOR UPDATE',
                [':space' => $spaceId, ':path' => $path],
            ) ?? throw new StorageError(404, 'not_found');

            $targetVersion = $this->one(
                'SELECT v.id::text, v.size_bytes AS "sizeBytes", v.sha256
                FROM storage.versions v
                WHERE v.file_id=CAST(:fileId AS uuid) AND v.version=:version',
                [':fileId' => $file['id'], ':version' => $version],
            ) ?? throw new StorageError(404, 'not_found');

            $maxVersion = (int) ($this->db->createCommand(
                'SELECT COALESCE(MAX(version), 0) FROM storage.versions WHERE file_id=CAST(:fileId AS uuid)',
                [':fileId' => $file['id']],
            )->queryScalar() ?: 0);

            $newVersionNumber = $maxVersion + 1;
            $newVersion = $this->one(
                'INSERT INTO storage.versions(file_id, version, size_bytes, sha256, storage_key)
                VALUES(CAST(:fileId AS uuid), :version, :size, :sha256, CAST(:storageKey AS uuid))
                RETURNING id::text, version, size_bytes AS "sizeBytes", sha256, created_at::text AS "createdAt"',
                [
                    ':fileId' => $file['id'],
                    ':version' => $newVersionNumber,
                    ':size' => $targetVersion['sizeBytes'],
                    ':sha256' => $targetVersion['sha256'],
                    ':storageKey' => $newStorageKey,
                ],
            ) ?? throw new StorageError(409, 'write_not_confirmed');

            $this->db->createCommand(
                'UPDATE storage.files SET current_version_id=CAST(:versionId AS uuid), deleted_at=NULL
                WHERE id=CAST(:fileId AS uuid)',
                [':versionId' => $newVersion['id'], ':fileId' => $file['id']],
            )->execute();

            return [
                'id' => (string) $file['id'],
                'path' => $path,
                'version' => $newVersionNumber,
                'sizeBytes' => (int) $targetVersion['sizeBytes'],
                'sha256' => (string) $targetVersion['sha256'],
                'createdAt' => (string) $newVersion['createdAt'],
            ];
        });
    }

    public function deleteFile(StorageScope $scope, string $path): void
    {
        $this->db->transaction(function () use ($scope, $path): void {
            $space = $this->space($scope);
            $this->lockSpace((string) $space['id']);
            $this->db->createCommand(
                'UPDATE storage.files SET deleted_at=now(),deleted_by_manifest_id=NULL
                WHERE space_id=CAST(:space AS uuid) AND path=:path AND deleted_at IS NULL',
                [':space' => $space['id'], ':path' => $path],
            )->execute();
        });
    }

    public function storeVersion(StorageScope $scope, string $path, int $size, string $sha256, string $storageKey, ?array $deviceVersion): array
    {
        return $this->db->transaction(function () use ($scope, $path, $size, $sha256, $storageKey, $deviceVersion): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $lock = hash('sha256', $spaceId . '|' . $path);
            $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtext(:key))', [':key' => $lock])->queryScalar();
            $file = $this->one(
                'SELECT id::text,path,current_version_id::text AS "currentVersionId",deleted_at::text AS "deletedAt" FROM storage.files
                WHERE space_id=CAST(:space AS uuid) AND path=:path FOR UPDATE',
                [':space' => $spaceId, ':path' => $path],
            );
            $caseCollision = $this->one('SELECT id::text FROM storage.files WHERE space_id=CAST(:space AS uuid) AND lower(path)=lower(:path) AND path<>:path', [':space' => $spaceId, ':path' => $path]);
            if ($caseCollision !== null) {
                throw new StorageError(409, 'path_conflict');
            }
            if ($file === null) {
                $file = $this->one(
                    'INSERT INTO storage.files(space_id,path) VALUES(CAST(:space AS uuid),:path)
                    RETURNING id::text,path,current_version_id::text AS "currentVersionId"',
                    [':space' => $spaceId, ':path' => $path],
                ) ?? throw new StorageError(409, 'write_not_confirmed');
                $current = null;
            } else {
                $current = $file['currentVersionId'] === null ? null : $this->one(
                    'SELECT id::text,version,sha256,storage_key::text AS "storageKey",size_bytes AS "sizeBytes" FROM storage.versions
                    WHERE id=CAST(:id AS uuid)',
                    [':id' => $file['currentVersionId']],
                );
                if ($current !== null && ($file['deletedAt'] ?? null) === null && $current['sha256'] === $sha256 && (int) $current['sizeBytes'] === $size) {
                    return ['fileId' => $file['id'], 'path' => $path, 'version' => (int) $current['version'],
                        'versionId' => (string) $current['id'], 'storageKey' => (string) $current['storageKey'],
                        'size' => $size, 'sha256' => $sha256, 'reused' => true];
                }
            }
            $usage = $this->one('SELECT count(*) AS count,coalesce(sum(v.size_bytes),0) AS bytes FROM storage.files f
                JOIN storage.versions v ON v.id=f.current_version_id WHERE f.space_id=CAST(:space AS uuid)
                AND f.deleted_at IS NULL AND f.id<>CAST(:file AS uuid)', [':space' => $spaceId, ':file' => $file['id']]) ?? [];
            if ((int) ($usage['count'] ?? 0) + 1 > (int) $space['maxFiles'] || (int) ($usage['bytes'] ?? 0) + $size > (int) $space['maxBytes']) {
                throw new StorageError(409, 'quota_exceeded');
            }
            $next = ($current === null ? 0 : (int) $current['version']) + 1;
            $versionRow = $this->one(
                'INSERT INTO storage.versions(file_id,version,size_bytes,sha256,storage_key)
                VALUES(CAST(:file AS uuid),:version,:size,:sha256,CAST(:key AS uuid))
                RETURNING id::text,version,size_bytes AS "sizeBytes",sha256,storage_key::text AS "storageKey"',
                [':file' => $file['id'], ':version' => $next, ':size' => $size, ':sha256' => $sha256, ':key' => $storageKey],
            ) ?? throw new StorageError(409, 'write_not_confirmed');
            $this->db->createCommand(
                "UPDATE storage.files SET current_version_id=CAST(:version AS uuid),deleted_at=NULL,
                    deleted_by_manifest_id=NULL WHERE id=CAST(:file AS uuid)",
                [':version' => $versionRow['id'], ':file' => $file['id']],
            )->execute();
            if ($deviceVersion !== null && $current !== null && (int) $deviceVersion['version'] !== (int) $current['version']) {
                $this->db->createCommand(
                    'INSERT INTO storage.conflicts(space_id,path,server_version,device_version,server_sha256,device_sha256)
                    VALUES(CAST(:space AS uuid),:path,:server,:device,:serverHash,:deviceHash)',
                    [':space' => $spaceId, ':path' => $path, ':server' => (int) $current['version'],
                        ':device' => (int) $deviceVersion['version'], ':serverHash' => (string) $current['sha256'],
                        ':deviceHash' => (string) ($deviceVersion['sha256'] ?? '')],
                )->execute();
            }
            return ['fileId' => $file['id'], 'path' => $path, 'version' => $next,
                'versionId' => (string) $versionRow['id'], 'storageKey' => (string) $versionRow['storageKey'],
                'size' => $size, 'sha256' => $sha256, 'reused' => false];
        });
    }

    public function commitUpload(
        string $ticketId,
        StorageScope $scope,
        string $path,
        int $size,
        string $sha256,
        string $storageKey,
    ): array {
        return $this->db->transaction(function () use ($ticketId, $scope, $path, $size, $sha256, $storageKey): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $ticket = $this->one(
                'SELECT id::text,space_id::text AS "spaceId",purpose,path,used_at::text AS "usedAt",
                    expires_at::text AS "expiresAt",expected_sha256 AS "expectedSha256",
                    expected_size AS "expectedSize",base_version_id::text AS "baseVersionId",base_deleted_at::text AS "baseDeletedAt"
                FROM storage.tickets WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $ticketId],
            );
            if ($ticket === null || $ticket['spaceId'] !== $spaceId || $ticket['purpose'] !== 'upload'
                || $ticket['usedAt'] !== null || (string) $ticket['path'] !== $path
                || new DateTimeImmutable((string) $ticket['expiresAt']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new StorageError(404, 'not_found');
            }
            if ($ticket['expectedSha256'] !== null
                && !hash_equals((string) $ticket['expectedSha256'], $sha256)) {
                throw new StorageError(422, 'checksum_mismatch');
            }
            if ($ticket['expectedSize'] !== null && (int) $ticket['expectedSize'] !== $size) {
                throw new StorageError(422, 'size_mismatch');
            }
            $live = $this->one('SELECT current_version_id::text AS "versionId",deleted_at::text AS "deletedAt"
                FROM storage.files WHERE space_id=CAST(:space AS uuid) AND path=:path FOR UPDATE', [':space' => $spaceId, ':path' => $path]);
            if (($live['versionId'] ?? null) !== $ticket['baseVersionId'] || ($live['deletedAt'] ?? null) !== $ticket['baseDeletedAt']) {
                throw new StorageError(409, 'version_conflict');
            }
            $entry = $this->one('SELECT e.base_sha256 AS "baseSha256",e.operation FROM storage.manifest_entries e
                WHERE e.ticket_id=CAST(:ticket AS uuid)', [':ticket' => $ticketId]);
            if ($entry !== null) {
                $current = $this->fileByPath($scope, $path);
                if (($entry['baseSha256'] === null && $current !== null)
                    || ($entry['baseSha256'] !== null && ($current === null || !hash_equals((string) $entry['baseSha256'], (string) $current['sha256'])))) {
                    throw new StorageError(409, 'version_conflict');
                }
            }
            $this->db->createCommand(
                'UPDATE storage.tickets SET used_at=now() WHERE id=CAST(:id AS uuid)',
                [':id' => $ticketId],
            )->execute();
            $result = $this->storeVersion($scope, $path, $size, $sha256, $storageKey, null);
            $this->verifyManifestUpload($ticketId, $path, $sha256, $size, (string) $result['versionId']);
            return $result;
        });
    }

    /**
     * @param list<array{path:string,op:string,sha256?:?string,size?:int,baseSha256?:?string}> $entries
     * @return array<string,mixed>
     */
    public function mergeManifest(StorageScope $scope, int $version, array $entries, ?string $deviceId): array
    {
        $canonical = self::canonicalEntries($entries);
        $entriesHash = self::entriesHash($canonical);
        return $this->db->transaction(function () use ($scope, $version, $canonical, $entriesHash, $deviceId): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $latestRow = $this->one(
                "SELECT coalesce(max(version),0) AS version FROM storage.manifests
                WHERE space_id=CAST(:space AS uuid) AND status='confirmed'",
                [':space' => $spaceId],
            );
            $latest = (int) ($latestRow['version'] ?? 0);
            $existing = $this->one(
                'SELECT id::text,status,version,device_id::text AS "deviceId",entries_sha256 AS "entriesSha256" FROM storage.manifests
                WHERE space_id=CAST(:space AS uuid) AND version=:version FOR UPDATE',
                [':space' => $spaceId, ':version' => $version],
            );
            if ($existing !== null) {
                if (($existing['deviceId'] ?? null) !== $deviceId) {
                    throw new StorageError(409, 'manifest_version_conflict');
                }
                if ($existing['entriesSha256'] !== null && $existing['entriesSha256'] !== $entriesHash) {
                    throw new StorageError(409, 'manifest_version_conflict');
                }
                return $this->manifestResponse($spaceId, $existing, $canonical, $deviceId);
            }
            if ($version <= $latest) {
                throw new StorageError(409, 'stale_version');
            }
            $pending = $this->one(
                "SELECT id::text FROM storage.manifests WHERE space_id=CAST(:space AS uuid)
                AND status='pending' FOR UPDATE",
                [':space' => $spaceId],
            );
            if ($pending !== null) {
                throw new StorageError(409, 'sync_in_progress');
            }
            $manifest = $this->one(
                'INSERT INTO storage.manifests(space_id,version,entries,entries_sha256,status,device_id,base_version)
                VALUES(CAST(:space AS uuid),:version,CAST(:entries AS jsonb),:hash,\'pending\',
                    CAST(:device AS uuid),:base)
                RETURNING id::text,status,version,entries_sha256 AS "entriesSha256"',
                [':space' => $spaceId, ':version' => $version,
                    ':entries' => json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ':hash' => $entriesHash,
                    ':device' => $deviceId, ':base' => $latest],
            ) ?? throw new StorageError(409, 'write_not_confirmed');
            $manifestId = (string) $manifest['id'];
            foreach ($canonical as $entry) {
                $state = 'expected';
                $verifiedSha = null;
                $verifiedSize = null;
                $versionId = null;
                $ticketId = null;
                $path = $entry['path'];
                $file = $this->one(
                    'SELECT f.id::text,v.id::text AS "versionId",coalesce(v.version,0) AS version,v.size_bytes AS "sizeBytes",coalesce(v.sha256,repeat(\'0\',64)) AS sha256,f.deleted_at::text AS "deletedAt"
                    FROM storage.files f LEFT JOIN storage.versions v ON v.id=f.current_version_id
                    WHERE f.space_id=CAST(:space AS uuid) AND f.path=:path FOR UPDATE OF f',
                    [':space' => $spaceId, ':path' => $path],
                );
                if ($file !== null && $file['deletedAt'] !== null && $entry['op'] !== 'delete') {
                    $state = 'conflict';
                    $this->recordConflict($spaceId, $manifestId, $path, $file, $entry['sha256']);
                } elseif ($entry['op'] === 'delete') {
                    if ($file === null || $file['deletedAt'] !== null) {
                        $state = 'skipped';
                    } elseif ($entry['baseSha256'] !== null
                        && hash_equals((string) $file['sha256'], $entry['baseSha256'])) {
                        $state = 'expected';
                    } else {
                        $state = 'conflict';
                        $this->recordConflict($spaceId, $manifestId, $path, $file, $entry['sha256']);
                    }
                } elseif ($file !== null && $entry['sha256'] !== null && hash_equals((string) $file['sha256'], $entry['sha256'])
                    && (int) $file['sizeBytes'] === $entry['size']) {
                    $state = 'verified';
                    $verifiedSha = $entry['sha256'];
                    $verifiedSize = $entry['size'];
                    $versionId = (string) $file['versionId'];
                } elseif ($file === null
                    || ($entry['baseSha256'] !== null && hash_equals((string) $file['sha256'], $entry['baseSha256']))) {
                    $state = 'expected';
                } else {
                    $state = 'conflict';
                    $this->recordConflict($spaceId, $manifestId, $path, $file, $entry['sha256']);
                }
                if ($entry['op'] !== 'delete' && $state === 'expected') {
                    $ticket = $this->createDeviceTicket(
                        $spaceId,
                        'upload',
                        $path,
                        $entry['sha256'],
                        $entry['size'],
                        null,
                        $deviceId,
                    );
                    $ticketId = (string) $ticket['id'];
                }
                $this->db->createCommand(
                    'INSERT INTO storage.manifest_entries(manifest_id,path,operation,sha256,size_bytes,base_sha256,
                        state,ticket_id,verified_sha256,verified_size,verified_version_id)
                    VALUES(CAST(:manifest AS uuid),:path,:operation,:sha,:size,:base,:state,
                        CAST(:ticket AS uuid),
                        :verified_sha,:verified_size,
                        CAST(:version_id AS uuid))',
                    [':manifest' => $manifestId, ':path' => $path, ':operation' => $entry['op'],
                        ':sha' => $entry['sha256'], ':size' => $entry['size'], ':base' => $entry['baseSha256'],
                        ':state' => $state, ':ticket' => $ticketId, ':verified_sha' => $verifiedSha,
                        ':verified_size' => $verifiedSize, ':version_id' => $versionId],
                )->execute();
            }
            $this->recordSync($scope, 'out', $deviceId, 'running');
            return $this->manifestResponse($spaceId, $manifest, $canonical, $deviceId);
        });
    }

    public function rebaseManifest(StorageScope $scope, int $version, string $deviceId): array
    {
        if ($version < 1 || $version > 999999) {
            throw new StorageError(422, 'invalid_input');
        }
        return $this->db->transaction(function () use ($scope, $version, $deviceId): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $session = $this->one(
                "SELECT s.id::text,s.desired_status AS \"desiredStatus\",s.status,s.evidence
                FROM device_management.device_sessions s JOIN device_management.devices d ON d.id=s.device_id
                WHERE s.device_id=CAST(:device AS uuid) AND s.student_id=CAST(:student AS uuid)
                    AND s.organization_id=CAST(:org AS uuid) AND s.status<>'CLOSED' AND d.enrollment_state='confirmed'
                ORDER BY s.created_at DESC LIMIT 1 FOR SHARE OF s",
                [':device' => $deviceId, ':student' => $scope->studentId, ':org' => $scope->organizationId],
            )
                ?? throw new StorageError(404, 'not_found');
            $evidence = is_string($session['evidence']) ? json_decode($session['evidence'], true) : $session['evidence'];
            $authorizedRetry = $this->one(
                "SELECT id::text FROM device_management.device_commands WHERE device_id=CAST(:device AS uuid)
                AND kind='end_session' AND payload->>'sessionId'=:session AND authorized_by_account_id IS NOT NULL LIMIT 1",
                [':device' => $deviceId, ':session' => $session['id']],
            );
            if ($authorizedRetry === null || $session['desiredStatus'] !== 'ENDING' || !is_array($evidence) || ($evidence['sessionEnded'] ?? false) !== true
                || !in_array($session['status'], ['ENDING','SYNCING_OUT','SYNC_PENDING','RECOVERY_REQUIRED'], true)) {
                throw new StorageError(409, 'recovery_not_authorized');
            }
            $manifest = $this->one(
                'SELECT id::text,status,device_id::text AS "deviceId"
                FROM storage.manifests WHERE space_id=CAST(:space AS uuid) AND version=:version FOR UPDATE',
                [':space' => $spaceId, ':version' => $version],
            );
            if ($manifest === null || $manifest['deviceId'] !== $deviceId) {
                throw new StorageError(404, 'not_found');
            }
            if (!in_array($manifest['status'], ['pending','accepted'], true)) {
                throw new StorageError(409, 'manifest_not_pending');
            }
            $manifestId = (string) $manifest['id'];
            $otherPending = $this->one(
                "SELECT id::text FROM storage.manifests WHERE space_id=CAST(:space AS uuid)
                AND status='pending' AND id<>CAST(:manifest AS uuid)
                AND (device_id IS DISTINCT FROM CAST(:device AS uuid) OR :is_pending=1) LIMIT 1 FOR UPDATE",
                [':space' => $spaceId, ':manifest' => $manifestId, ':device' => $deviceId,
                    ':is_pending' => $manifest['status'] === 'pending' ? 1 : 0],
            );
            if ($otherPending !== null) {
                throw new StorageError(409, 'sync_in_progress');
            }
            $conflicts = $this->all(
                "SELECT e.path,choice.resolution,choice.resolved_at::text AS \"resolvedAt\"
                FROM storage.manifest_entries e LEFT JOIN LATERAL (
                    SELECT c.resolution,c.resolved_at FROM storage.conflicts c
                    WHERE c.manifest_id=e.manifest_id AND c.path=e.path AND c.space_id=CAST(:space AS uuid)
                    ORDER BY c.created_at DESC,c.id DESC LIMIT 1
                ) choice ON true WHERE e.manifest_id=CAST(:manifest AS uuid) AND e.state='conflict'",
                [':manifest' => $manifestId, ':space' => $spaceId],
            );
            if ($conflicts === []) {
                throw new StorageError(409, 'unresolved_conflicts');
            }
            foreach ($conflicts as $conflict) {
                if ($conflict['resolution'] !== 'keep_server' || $conflict['resolvedAt'] === null) {
                    throw new StorageError(409, 'unresolved_conflicts');
                }
            }
            $nextVersion = 1 + (int) $this->db->createCommand('SELECT coalesce(max(version),0) FROM storage.manifests
                WHERE space_id=CAST(:space AS uuid)', [':space' => $spaceId])->queryScalar();
            if ($nextVersion > 999999) {
                throw new StorageError(409, 'manifest_version_exhausted');
            }
            if ($manifest['status'] === 'pending') {
                // "accepted" is the existing non-pending archive state. History and versions remain intact.
                $this->db->createCommand(
                    "UPDATE storage.manifests SET status='accepted' WHERE id=CAST(:manifest AS uuid)",
                    [':manifest' => $manifestId],
                )->execute();
                $this->expireManifestTickets($manifestId);
                $this->db->createCommand(
                    "UPDATE storage.sync_sessions SET status='failed',finished_at=now()
                    WHERE space_id=CAST(:space AS uuid) AND device_id=CAST(:device AS uuid) AND direction='out' AND status='running'",
                    [':space' => $spaceId, ':device' => $deviceId],
                )->execute();
            }
            return ['nextVersion' => $nextVersion, 'rebased' => true];
        });
    }

    public function confirmManifest(StorageScope $scope, int $version, array $snapshot, ?string $deviceId = null): array
    {
        $canonical = self::canonicalSnapshot($snapshot);
        $snapshotHash = self::snapshotHash($canonical);
        return $this->db->transaction(function () use ($scope, $version, $canonical, $snapshotHash, $deviceId): array {
            $space = $this->space($scope);
            $spaceId = (string) $space['id'];
            $this->lockSpace($spaceId);
            $requested = $this->one(
                'SELECT status FROM storage.manifests WHERE space_id=CAST(:space AS uuid) AND version=:version',
                [':space' => $spaceId, ':version' => $version],
            );
            if (($requested['status'] ?? null) === 'accepted') {
                throw new StorageError(409, 'manifest_not_submitted');
            }
            $latest = $this->one(
                "SELECT id::text,version,device_id::text AS \"deviceId\",confirmed_at::text AS \"confirmedAt\",snapshot_sha256 AS \"snapshotSha256\" FROM storage.manifests
                WHERE space_id=CAST(:space AS uuid) AND status='confirmed'
                ORDER BY version DESC LIMIT 1",
                [':space' => $spaceId],
            );
            $active = $deviceId === null ? null : $this->one("SELECT started_at::text AS started FROM device_management.device_sessions
                WHERE device_id=CAST(:device AS uuid) AND status<>'CLOSED' ORDER BY created_at DESC LIMIT 1", [':device' => $deviceId]);
            $pendingReuse = $this->one("SELECT id::text FROM storage.manifests WHERE space_id=CAST(:space AS uuid) AND status='pending' LIMIT 1", [':space' => $spaceId]);
            if ($latest !== null && $version === (int) $latest['version']
                && ($latest['deviceId'] ?? null) === $deviceId && $active !== null
                && new DateTimeImmutable((string) ($latest['confirmedAt'] ?? '1970-01-01')) >= new DateTimeImmutable((string) $active['started'])
                && $pendingReuse === null && $latest['snapshotSha256'] === $snapshotHash
                && self::snapshotHash(self::canonicalSnapshot($this->liveManifest($spaceId, true))) === $snapshotHash) {
                return ['manifestId' => $latest['id'], 'version' => (int) $latest['version'],
                    'confirmed' => true, 'verifiedEntries' => count($canonical)];
            }
            if ($version > (int) ($latest['version'] ?? 0)) {
                $manifest = $this->one(
                    'SELECT id::text,status,device_id::text AS "deviceId" FROM storage.manifests
                    WHERE space_id=CAST(:space AS uuid) AND version=:version FOR UPDATE',
                    [':space' => $spaceId, ':version' => $version],
                );
                if ($manifest === null || $manifest['status'] !== 'pending' || ($manifest['deviceId'] ?? null) !== $deviceId) {
                    throw new StorageError(409, 'manifest_not_submitted');
                }
                $manifestId = (string) $manifest['id'];
                $entries = $this->all(
                    'SELECT path,operation,sha256,size_bytes AS "sizeBytes",base_sha256 AS "baseSha256",state
                    FROM storage.manifest_entries WHERE manifest_id=CAST(:manifest AS uuid)',
                    [':manifest' => $manifestId],
                );
                $live = $this->liveManifest($spaceId, true);
                $byPath = [];
                foreach ($live as $file) {
                    $byPath[(string) $file['path']] = $file;
                }
                $snapshotByPath = [];
                foreach ($canonical as $entry) {
                    $snapshotByPath[$entry['path']] = $entry;
                }
                $deletePaths = [];
                foreach ($entries as $entry) {
                    $path = (string) $entry['path'];
                    if ($entry['state'] === 'conflict') {
                        throw new StorageError(409, 'version_conflict');
                    }
                    if (in_array($entry['operation'], ['add', 'update'], true)) {
                        $file = $byPath[$path] ?? null;
                        if ($file === null) {
                            throw new StorageError(409, 'upload_missing');
                        }
                        if (!hash_equals((string) $file['sha256'], (string) $entry['sha256'])
                            || (int) $file['sizeBytes'] !== (int) $entry['sizeBytes']
                            || (count($canonical) > 0 && (!isset($snapshotByPath[$path])
                                || !hash_equals($snapshotByPath[$path]['sha256'], (string) $entry['sha256'])
                                || $snapshotByPath[$path]['size'] !== (int) $entry['sizeBytes']))) {
                            throw new StorageError(409, 'checksum_mismatch');
                        }
                    } else {
                        if (count($canonical) > 0 && isset($snapshotByPath[$path])) {
                            throw new StorageError(409, 'checksum_mismatch');
                        }
                        $file = $byPath[$path] ?? null;
                        if ($file !== null && ($entry['baseSha256'] === null
                            || !hash_equals((string) $file['sha256'], (string) $entry['baseSha256']))) {
                            throw new StorageError(409, 'concurrent_change');
                        }
                        if ($file !== null) {
                            $deletePaths[$path] = (string) $file['fileId'];
                        }
                    }
                }
                $effective = array_values(array_filter(
                    $live,
                    static fn(array $file): bool => !isset($deletePaths[(string) $file['path']]),
                ));
                if (count($canonical) > 0) {
                    if (self::snapshotHash(self::canonicalSnapshot($effective)) !== $snapshotHash) {
                        throw new StorageError(409, 'manifest_stale');
                    }
                } else {
                    $canonical = self::canonicalSnapshot($effective);
                    $snapshotHash = self::snapshotHash($canonical);
                }
                foreach ($deletePaths as $path => $fileId) {
                    $this->db->createCommand(
                        'UPDATE storage.files SET deleted_at=now(),deleted_by_manifest_id=CAST(:manifest AS uuid)
                        WHERE id=CAST(:file AS uuid) AND deleted_at IS NULL',
                        [':manifest' => $manifestId, ':file' => $fileId],
                    )->execute();
                    $this->db->createCommand(
                        "UPDATE storage.manifest_entries SET state='deleted',updated_at=now()
                        WHERE manifest_id=CAST(:manifest AS uuid) AND path=:path",
                        [':manifest' => $manifestId, ':path' => $path],
                    )->execute();
                }
                foreach ($entries as $entry) {
                    if (in_array($entry['operation'], ['add', 'update'], true)) {
                        $this->db->createCommand(
                            "UPDATE storage.manifest_entries SET state='verified',updated_at=now()
                            WHERE manifest_id=CAST(:manifest AS uuid) AND path=:path AND state<>'conflict'",
                            [':manifest' => $manifestId, ':path' => $entry['path']],
                        )->execute();
                    }
                }
                $this->db->createCommand(
                    "UPDATE storage.manifests SET status='confirmed',confirmed_at=now(),
                        snapshot=CAST(:snapshot AS jsonb),snapshot_sha256=:hash WHERE id=CAST(:id AS uuid)",
                    [':snapshot' => json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        ':hash' => $snapshotHash, ':id' => $manifestId],
                )->execute();
                $this->expireManifestTickets($manifestId);
                $this->completeSync($scope, 'out', $deviceId ?? (is_string($manifest['deviceId'] ?? null) ? (string) $manifest['deviceId'] : null), 'done');
                return ['manifestId' => $manifestId, 'version' => $version, 'confirmed' => true,
                    'verifiedEntries' => count($canonical)];
            }

            $pending = $this->one(
                "SELECT id::text FROM storage.manifests WHERE space_id=CAST(:space AS uuid) AND status='pending'",
                [':space' => $spaceId],
            );
            if ($pending !== null) {
                throw new StorageError(409, 'sync_in_progress');
            }
            $live = $this->liveManifest($spaceId, true);
            if (self::snapshotHash(self::canonicalSnapshot($live)) !== $snapshotHash) {
                throw new StorageError(409, 'manifest_stale');
            }
            $next = (int) ($latest['version'] ?? 0) + 1;
            $checkpoint = $this->one(
                "INSERT INTO storage.manifests(space_id,version,entries,entries_sha256,status,device_id,
                    base_version,snapshot,snapshot_sha256,confirmed_at)
                VALUES(CAST(:space AS uuid),:version,'[]',:entries_hash,'confirmed',
                    CAST(:device AS uuid),:base,
                    CAST(:snapshot AS jsonb),:hash,now())
                RETURNING id::text",
                [':space' => $spaceId, ':version' => $next,
                    ':entries_hash' => hash('sha256', '[]'),
                    ':device' => $deviceId, ':base' => (int) ($latest['version'] ?? 0),
                    ':snapshot' => json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ':hash' => $snapshotHash],
            ) ?? throw new StorageError(409, 'write_not_confirmed');
            $this->recordSync($scope, 'out', $deviceId, 'done');
            return ['manifestId' => $checkpoint['id'], 'version' => $next, 'confirmed' => true,
                'verifiedEntries' => count($canonical)];
        });
    }

    public function confirmedManifest(StorageScope $scope): array
    {
        $space = $this->space($scope);
        $latest = $this->one(
            "SELECT coalesce(max(version),0) AS version FROM storage.manifests
            WHERE space_id=CAST(:space AS uuid) AND status='confirmed'",
            [':space' => $space['id']],
        );
        return ['version' => (int) ($latest['version'] ?? 0), 'entries' => $this->liveManifest((string) $space['id'], false)];
    }

    public function verifyManifestUpload(string $ticketId, string $path, string $sha256, int $size, string $versionId): void
    {
        $this->db->createCommand(
            "UPDATE storage.manifest_entries e SET state='verified',verified_sha256=:sha,verified_size=:size,
                verified_version_id=CAST(:version AS uuid),updated_at=now()
            FROM storage.tickets t
            WHERE e.ticket_id=t.id AND t.id=CAST(:ticket AS uuid) AND e.path=:path
                AND e.sha256=:sha AND e.size_bytes=:size AND e.operation<>'delete'",
            [':ticket' => $ticketId, ':path' => $path, ':sha' => $sha256, ':size' => $size, ':version' => $versionId],
        )->execute();
    }

    public function recordSync(StorageScope $scope, string $direction, ?string $deviceId, string $status): void
    {
        if ($deviceId === null) {
            throw new StorageError(422, 'invalid_input');
        }
        $space = $this->space($scope);
        $this->db->createCommand(
            'INSERT INTO storage.sync_sessions(space_id,device_id,direction,status,finished_at)
            VALUES(CAST(:space AS uuid),CAST(:device AS uuid),:direction,:status,
                CASE WHEN :finished = \'done\' THEN now() ELSE NULL END)',
            [':space' => $space['id'], ':device' => $deviceId,
                ':direction' => $direction, ':status' => $status, ':finished' => $status],
        )->execute();
    }

    public function completeSync(StorageScope $scope, string $direction, ?string $deviceId, string $status): void
    {
        $space = $this->space($scope);
        $this->db->createCommand(
            "UPDATE storage.sync_sessions SET status=:status,finished_at=now()
            WHERE id=(SELECT id FROM storage.sync_sessions WHERE space_id=CAST(:space AS uuid)
                AND direction=:direction AND status='running'
                ORDER BY created_at DESC LIMIT 1)",
            [':space' => $space['id'], ':direction' => $direction, ':status' => $status],
        )->execute();
    }

    public function markTicketUsed(string $ticketId): void
    {
        $this->db->createCommand(
            'UPDATE storage.tickets SET used_at=now() WHERE id=CAST(:id AS uuid) AND used_at IS NULL',
            [':id' => $ticketId],
        )->execute();
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<array{path:string,op:string,sha256:?string,size:int,baseSha256:?string}>
     */
    private static function canonicalEntries(array $entries): array
    {
        /** @var list<array{path:string,op:string,sha256:?string,size:int,baseSha256:?string}> $result */
        $result = [];
        $seen = [];
        foreach ($entries as $entry) {
            $rawPath = $entry['path'] ?? null;
            $path = is_string($rawPath) ? $rawPath : '';
            $key = strtolower($path);
            if ($path === '' || isset($seen[$key])) {
                throw new StorageError(422, 'invalid_input');
            }
            $seen[$key] = true;
            $operation = is_string($entry['op'] ?? null) ? (string) $entry['op'] : '';
            if (!in_array($operation, ['add', 'update', 'delete'], true)) {
                throw new StorageError(422, 'invalid_input');
            }
            $rawSha = $entry['sha256'] ?? null;
            $sha256 = empty($rawSha) || !is_string($rawSha) ? null : strtolower($rawSha);
            $rawBase = $entry['baseSha256'] ?? null;
            $base = empty($rawBase) || !is_string($rawBase) ? null : strtolower($rawBase);
            $rawSize = $entry['size'] ?? 0;
            $size = is_int($rawSize) ? $rawSize : (is_numeric($rawSize) ? (int) $rawSize : 0);
            if ($operation === 'delete') {
                $sha256 = null;
                $size = 0;
            } elseif ($sha256 === null || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
                || $size < 0 || $size > self::MAX_FILE_SIZE) {
                throw new StorageError(422, 'invalid_input');
            }
            if ($base !== null && preg_match('/^[0-9a-f]{64}$/', $base) !== 1) {
                throw new StorageError(422, 'invalid_input');
            }
            $result[] = ['path' => $path, 'op' => $operation, 'sha256' => $sha256,
                'size' => $size, 'baseSha256' => $base];
        }
        usort($result, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $snapshot
     * @return list<array{path:string,sha256:string,size:int}>
     */
    private static function canonicalSnapshot(array $snapshot): array
    {
        /** @var list<array{path:string,sha256:string,size:int}> $result */
        $result = [];
        $seen = [];
        foreach ($snapshot as $entry) {
            $rawPath = $entry['path'] ?? null;
            $path = is_string($rawPath) ? $rawPath : '';
            $key = strtolower($path);
            $rawSha = $entry['sha256'] ?? null;
            $sha256 = is_string($rawSha) ? strtolower($rawSha) : '';
            $rawSize = $entry['size'] ?? ($entry['sizeBytes'] ?? -1);
            $size = is_int($rawSize) ? $rawSize : (is_numeric($rawSize) ? (int) $rawSize : -1);
            if ($path === '' || isset($seen[$key]) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
                || $size < 0 || $size > self::MAX_FILE_SIZE) {
                throw new StorageError(422, 'invalid_input');
            }
            $seen[$key] = true;
            $result[] = ['path' => $path, 'sha256' => $sha256, 'size' => $size];
        }
        usort($result, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
        return $result;
    }

    /** @param list<array<string,mixed>> $entries */
    private static function entriesHash(array $entries): string
    {
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param list<array<string,mixed>> $snapshot */
    private static function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array<string,mixed>> */
    private function liveManifest(string $spaceId, bool $lock): array
    {
        return $this->all(
            'SELECT f.id::text AS "fileId",f.path,v.id::text AS "versionId",v.version,
                v.size_bytes AS "sizeBytes",v.sha256
            FROM storage.files f JOIN storage.versions v ON v.id=f.current_version_id
            WHERE f.space_id=CAST(:space AS uuid) AND f.deleted_at IS NULL
            ORDER BY f.path' . ($lock ? ' FOR UPDATE OF f' : ''),
            [':space' => $spaceId],
        );
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $canonical
     * @return array<string,mixed>
     */
    private function manifestResponse(
        string $spaceId,
        array $manifest,
        array $canonical,
        ?string $deviceId = null,
    ): array {
        $manifestId = (string) $manifest['id'];
        $rows = $this->all(
            'SELECT e.path,e.operation,e.sha256,e.size_bytes AS "sizeBytes",e.base_sha256 AS "baseSha256",e.state,
                e.ticket_id::text AS "ticketId",choice.resolution,choice.resolved_at::text AS "resolvedAt"
            FROM storage.manifest_entries e LEFT JOIN LATERAL (SELECT c.resolution,c.resolved_at FROM storage.conflicts c
                WHERE c.manifest_id=e.manifest_id AND c.path=e.path ORDER BY c.created_at DESC,c.id DESC LIMIT 1) choice ON true
            WHERE e.manifest_id=CAST(:manifest AS uuid) ORDER BY e.path',
            [':manifest' => $manifestId],
        );
        $uploads = [];
        $conflicts = [];
        $upToDate = [];
        foreach ($rows as $row) {
            $state = (string) $row['state'];
            if ($state === 'conflict') {
                $file = $this->one(
                    'SELECT v.version,v.sha256 FROM storage.files f JOIN storage.versions v ON v.id=f.current_version_id
                    WHERE f.space_id=CAST(:space AS uuid) AND f.path=:path AND f.deleted_at IS NULL',
                    [':space' => $spaceId, ':path' => $row['path']],
                );
                $conflicts[] = ['path' => $row['path'],
                    'serverSha256' => (string) ($file['sha256'] ?? str_repeat('0', 64)),
                    'serverVersion' => (int) ($file['version'] ?? 0),
                    'deviceSha256' => (string) ($row['sha256'] ?? ''),
                    'resolution' => $row['resolvedAt'] === null ? null : $row['resolution'], 'serverDeleted' => $file === null];
                continue;
            }
            if (in_array($state, ['verified', 'skipped'], true)) {
                $upToDate[] = ['path' => $row['path'], 'version' => 0];
                continue;
            }
            if ($row['operation'] === 'delete') {
                continue;
            }
            if ($manifest['status'] !== 'pending') {
                continue;
            } // Never renew tickets for a confirmed or rebased archive.
            $ticket = null;
            if ($row['ticketId'] !== null) {
                $prior = $this->one("SELECT t.id::text,t.used_at::text AS used,tr.status,tr.expires_at::text AS expires
                    FROM storage.tickets t LEFT JOIN storage.transfers tr ON tr.ticket_id=t.id
                    WHERE t.id=CAST(:id AS uuid)", [':id' => $row['ticketId']]);
                if ($prior !== null && $prior['used'] === null
                    && !in_array($prior['status'], ['aborted','expired'], true)
                    && ($prior['expires'] === null || new DateTimeImmutable($prior['expires']) > new DateTimeImmutable())) {
                    $token = bin2hex(random_bytes(24));
                    $this->db->createCommand("UPDATE storage.tickets SET token_hash=:hash,expires_at=now()+interval '10 minutes'
                        WHERE id=CAST(:id AS uuid) AND used_at IS NULL", [':hash' => hash('sha256', $token), ':id' => $prior['id']])->execute();
                    $ticket = ['id' => $prior['id'], 'token' => $token];
                }
            }
            $ticket ??= $this->createDeviceTicket(
                $spaceId,
                'upload',
                (string) $row['path'],
                (string) $row['sha256'],
                (int) $row['sizeBytes'],
                null,
                $deviceId,
            );
            $this->db->createCommand(
                'UPDATE storage.manifest_entries SET ticket_id=CAST(:ticket AS uuid),updated_at=now()
                WHERE manifest_id=CAST(:manifest AS uuid) AND path=:path',
                [':ticket' => $ticket['id'], ':manifest' => $manifestId, ':path' => $row['path']],
            )->execute();
            $uploads[] = ['path' => $row['path'], 'ticketId' => $ticket['id'], 'token' => $ticket['token'],
                'expectedSize' => (int) $row['sizeBytes'], 'expectedSha256' => (string) $row['sha256']];
        }
        if ($manifest['status'] !== 'pending') {
            $uploads = [];
        }
        return ['manifestId' => $manifestId, 'version' => (int) $manifest['version'],
            'uploads' => $uploads, 'conflicts' => $conflicts, 'upToDate' => $upToDate,
            'confirmed' => $manifest['status'] === 'confirmed',
            'acceptedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM)];
    }

    private function recordConflict(string $spaceId, string $manifestId, string $path, array $file, ?string $deviceSha): void
    {
        $this->db->createCommand(
            'INSERT INTO storage.conflicts(space_id,manifest_id,path,server_version,device_version,server_sha256,
                device_sha256,resolution,resolved_at)
            VALUES(CAST(:space AS uuid),CAST(:manifest AS uuid),:path,:server,NULL,:serverHash,:deviceHash,NULL,NULL)',
            [':space' => $spaceId, ':manifest' => $manifestId, ':path' => $path,
                ':server' => (int) $file['version'], ':serverHash' => (string) $file['sha256'],
                ':deviceHash' => $deviceSha ?? ''],
        )->execute();
    }

    private function expireManifestTickets(string $manifestId): void
    {
        $this->db->createCommand(
            'UPDATE storage.tickets SET expires_at=now()
            WHERE id IN (SELECT ticket_id FROM storage.manifest_entries
                WHERE manifest_id=CAST(:manifest AS uuid) AND ticket_id IS NOT NULL)',
            [':manifest' => $manifestId],
        )->execute();
    }
    public function renameFile(StorageScope $scope, string $path, string $newPath): array
    {
        return $this->db->transaction(function () use ($scope, $path, $newPath): array {
            $space = $this->space($scope);
            $this->lockSpace((string) $space['id']);
            $file = $this->fileByPath($scope, $path) ?? throw new StorageError(404, 'not_found');
            if ($path === $newPath) {
                return array_intersect_key($file, array_flip(['id','path','version','sizeBytes','sha256','createdAt']));
            }
            $existing = $this->one(
                'SELECT id::text FROM storage.files WHERE space_id=CAST(:space AS uuid) AND lower(path)=lower(:path)',
                [':space' => $space['id'], ':path' => $newPath],
            );
            if ($existing !== null) {
                throw new StorageError(409, 'path_conflict');
            }
            $this->db->createCommand(
                'UPDATE storage.files SET path=:new WHERE id=CAST(:id AS uuid)',
                [':new' => $newPath, ':id' => $file['id']],
            )->execute();
            // Preserve an old-path tombstone so a stale device cannot recreate the renamed file silently.
            $this->db->createCommand(
                'INSERT INTO storage.files(space_id,path,deleted_at) VALUES(CAST(:space AS uuid),:path,now())',
                [':space' => $space['id'], ':path' => $path],
            )->execute();
            $renamed = $this->fileByPath($scope, $newPath) ?? throw new StorageError(409, 'write_not_confirmed');
            return array_intersect_key($renamed, array_flip(['id','path','version','sizeBytes','sha256','createdAt']));
        });
    }

    public function conflicts(StorageScope $scope, int $offset): array
    {
        $space = $this->space($scope);
        return $this->all(
            'SELECT id::text,path,server_version AS "serverVersion",device_version AS "deviceVersion",
            server_sha256 AS "serverSha256",device_sha256 AS "deviceSha256",resolution,
            resolved_at::text AS "resolvedAt",created_at::text AS "createdAt" FROM storage.conflicts
            WHERE space_id=CAST(:space AS uuid) ORDER BY created_at DESC,id DESC LIMIT 51 OFFSET :offset',
            [':space' => $space['id'], ':offset' => $offset],
        );
    }

    public function resolveConflict(StorageScope $scope, string $id, string $resolution): array
    {
        return $this->db->transaction(function () use ($scope, $id, $resolution): array {
            $space = $this->space($scope);
            $this->lockSpace((string) $space['id']);
            if ($resolution !== 'keep_server') {
                throw new StorageError(422, 'invalid_input');
            }
            $conflict = $this->one(
                'UPDATE storage.conflicts SET resolution=:resolution,resolved_at=coalesce(resolved_at,now())
                WHERE id=CAST(:id AS uuid) AND space_id=CAST(:space AS uuid)
                RETURNING id::text,path,server_version AS "serverVersion",device_version AS "deviceVersion",
                    server_sha256 AS "serverSha256",device_sha256 AS "deviceSha256",resolution,
                    resolved_at::text AS "resolvedAt",created_at::text AS "createdAt",manifest_id::text AS "manifestId"',
                [':id' => $id, ':space' => $space['id'], ':resolution' => $resolution],
            ) ?? throw new StorageError(404, 'not_found');
            // Resolution records a server choice. Local unique bytes remain protected in the agent recovery store.
            // No confirmation is fabricated and no files are deleted here.
            return $conflict;
        });
    }

}
