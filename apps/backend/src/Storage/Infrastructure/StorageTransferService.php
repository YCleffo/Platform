<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Rbac\Application\PermissionService;
use App\DeviceManagement\Application\DeviceStore;
use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageStore;
use Aws\Exception\AwsException;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** S3 transfers publish through the existing version and manifest transaction. */
final readonly class StorageTransferService
{
    public function __construct(
        private ConnectionInterface $db,
        private StorageStore $store,
        private S3ClientFactory $clients,
        private S3Config $config,
        private S3Objects $objects,
        private DeviceStore $devices,
        private AuditWriter $audit,
        private PermissionService $permissions,
    ) {}

    public function start(StorageScope $scope, array $body, ?string $deviceId = null): array
    {
        self::only($body, ['ticketId', 'token']);
        $ticketId = self::uuid($body['ticketId'] ?? null);
        $token = $body['token'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{48}$/D', $token) !== 1) {
            throw new StorageError(404, 'not_found');
        }
        $row = $this->db->transaction(function () use ($scope, $ticketId, $token, $deviceId): array {
            $space = $this->store->space($scope);
            $this->lockSpace($space['id']);
            $space = $this->store->space($scope);
            $ticket = $this->one(
                'SELECT id::text,space_id::text AS "spaceId",purpose,path,
                used_at::text AS "usedAt",expires_at::text AS "expiresAt",expected_sha256 AS "expectedSha256",
                expected_size AS "expectedSize",file_version_id::text AS "fileVersionId",
                device_id::text AS "deviceId",actor_account_id::text AS "actorAccountId"
                FROM storage.tickets WHERE id=CAST(:id AS uuid) AND token_hash=:hash FOR UPDATE',
                [':id' => $ticketId, ':hash' => hash('sha256', $token)],
            );
            if ($ticket === null || $ticket['spaceId'] !== $space['id']
                || $ticket['deviceId'] !== $deviceId
                || ($deviceId === null && $ticket['actorAccountId'] !== $scope->accountId)) {
                throw new StorageError(404, 'not_found');
            }
            $existing = $this->byTicket($ticketId);
            if ($existing !== null) {
                $this->authorize($scope, $existing, $deviceId);
                return $existing;
            }
            if ($ticket['usedAt'] !== null || new DateTimeImmutable($ticket['expiresAt']) <= new DateTimeImmutable()) {
                throw new StorageError(409, 'ticket_expired');
            }
            $size = $ticket['expectedSize'];
            $sha = $ticket['expectedSha256'];
            if ($size === null || $sha === null || (int) $size > $this->config->uploadMaxBytes) {
                throw new StorageError(422, 'transfer_metadata_required');
            }
            $reservedBytes = 0;
            $reservedFiles = 0;
            if ($ticket['purpose'] === 'upload') {
                [$reservedBytes, $reservedFiles] = $this->reserve($scope, $space, $ticket['path'], (int) $size);
            }
            $id = $this->newId();
            $multipart = $ticket['purpose'] === 'upload' && (int) $size > $this->config->multipartPartSize;
            $partSize = $multipart ? max($this->config->multipartPartSize, (int) ceil((int) $size / 10000 / 1048576) * 1048576) : null;
            $this->db->createCommand(
                'INSERT INTO storage.transfers
                (id,ticket_id,space_id,actor_account_id,device_id,direction,path,mode,expected_size,expected_sha256,
                 staging_key,final_storage_key,part_size,total_parts,reserved_bytes,reserved_files)
                VALUES(CAST(:id AS uuid),CAST(:ticket AS uuid),CAST(:space AS uuid),CAST(:actor AS uuid),CAST(:device AS uuid),
                    :direction,:path,:mode,:size,:sha,:staging,CAST(:final AS uuid),:part,:parts,:bytes,:files)',
                [':id' => $id, ':ticket' => $ticketId, ':space' => $space['id'],
                    ':actor' => $deviceId === null ? $scope->accountId : null, ':device' => $deviceId,
                    ':direction' => $ticket['purpose'], ':path' => $ticket['path'], ':mode' => $multipart ? 'multipart' : 'single',
                    ':size' => $size, ':sha' => $sha, ':staging' => 'staging/' . $id . '/' . bin2hex(random_bytes(16)),
                    ':final' => $this->newId(), ':part' => $partSize,
                    ':parts' => $partSize === null ? null : (int) ceil((int) $size / $partSize),
                    ':bytes' => $reservedBytes, ':files' => $reservedFiles],
            )->execute();
            $row = $this->row($id);
            $this->authorize($scope, $row, $deviceId);
            $this->writeAudit($scope, 'transfer_start', $id);
            return $row;
        });
        $this->initializeMultipart($scope, $row['id'], $deviceId);
        return $this->read($scope, $row['id'], $deviceId);
    }

    public function read(StorageScope $scope, string $id, ?string $deviceId = null): array
    {
        $row = $this->row(self::uuid($id));
        $this->authorize($scope, $row, $deviceId);
        return $this->view($scope, $row);
    }

    public function refresh(StorageScope $scope, string $id, array $body, ?string $deviceId = null): array
    {
        self::only($body, []);
        return $this->read($scope, $id, $deviceId);
    }

    public function parts(StorageScope $scope, string $id, array $body, ?string $deviceId = null): array
    {
        self::only($body, ['parts']);
        $parts = $body['parts'] ?? null;
        if (!is_array($parts) || !array_is_list($parts) || count($parts) < 1 || count($parts) > 64) {
            throw new StorageError(422, 'invalid_parts');
        }
        return $this->db->transaction(function () use ($scope, $id, $parts, $deviceId): array {
            $row = $this->row(self::uuid($id), true);
            $this->authorize($scope, $row, $deviceId);
            $this->assertOpen($row);
            if ($row['direction'] !== 'upload' || $row['mode'] !== 'multipart' || $row['uploadId'] === null) {
                throw new StorageError(409, 'multipart_unavailable');
            }
            $items = [];
            $seen = [];
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    throw new StorageError(422, 'invalid_parts');
                }
                self::only($part, ['partNumber', 'sha256', 'sizeBytes']);
                $number = $part['partNumber'] ?? null;
                $sha = $part['sha256'] ?? null;
                if (!is_int($number) || $number < 1 || $number > $row['totalParts'] || isset($seen[$number])
                    || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
                    throw new StorageError(422, 'invalid_parts');
                }
                $seen[$number] = true;
                $expectedSize = $number === $row['totalParts']
                    ? $row['expectedSize'] - ($number - 1) * $row['partSize'] : $row['partSize'];
                if (($part['sizeBytes'] ?? null) !== $expectedSize) {
                    throw new StorageError(422, 'part_size_mismatch');
                }
                $this->db->createCommand(
                    'INSERT INTO storage.transfer_parts(transfer_id,part_number,sha256,size_bytes)
                    VALUES(CAST(:id AS uuid),:number,:sha,:size) ON CONFLICT(transfer_id,part_number) DO NOTHING',
                    [':id' => $row['id'], ':number' => $number, ':sha' => $sha, ':size' => $expectedSize],
                )->execute();
                $saved = $this->one('SELECT sha256,size_bytes::bigint AS size FROM storage.transfer_parts
                    WHERE transfer_id=CAST(:id AS uuid) AND part_number=:number', [':id' => $row['id'], ':number' => $number]);
                if ($saved === null || !hash_equals($saved['sha256'], $sha) || (int) $saved['size'] !== $expectedSize) {
                    throw new StorageError(409, 'part_conflict');
                }
                $items[] = ['partNumber' => $number, 'request' => $this->sign('UploadPart', [
                    'Bucket' => $this->config->bucketStorage, 'Key' => $row['stagingKey'],
                    'UploadId' => $row['uploadId'], 'PartNumber' => $number,
                    'ContentLength' => $expectedSize, 'ChecksumSHA256' => base64_encode(hex2bin($sha)),
                ])];
            }
            return ['items' => $items];
        });
    }

    public function complete(StorageScope $scope, string $id, array $body, ?string $deviceId = null): array
    {
        self::only($body, []);
        return $this->db->transaction(function () use ($scope, $id, $deviceId): array {
            $space = $this->store->space($scope);
            $this->lockSpace($space['id']);
            $row = $this->row(self::uuid($id), true);
            $this->authorize($scope, $row, $deviceId);
            if ($row['status'] === 'confirmed') {
                return $this->view($scope, $row);
            }
            $this->assertOpen($row);
            if ($row['direction'] === 'download') {
                $file = $this->downloadVersion($scope, $row);
                $receipt = ['path' => $row['path'], 'version' => (int) $file['version'],
                    'sha256' => $row['expectedSha256'], 'size' => $row['expectedSize']];
            } else {
                $receipt = $this->publish($scope, $row, $deviceId);
            }
            $this->db->createCommand(
                "UPDATE storage.transfers SET status='confirmed',result=CAST(:result AS jsonb),
                verified_at=now(),reserved_bytes=0,reserved_files=0,updated_at=now() WHERE id=CAST(:id AS uuid)",
                [':id' => $row['id'], ':result' => json_encode($receipt, JSON_THROW_ON_ERROR)],
            )->execute();
            if ($row['direction'] === 'upload') {
                $this->queueStaging($row);
            }
            $this->writeAudit($scope, 'transfer_complete', $row['id']);
            return $this->view($scope, $this->row($row['id']));
        });
    }

    public function abort(StorageScope $scope, string $id, array $body, ?string $deviceId = null): array
    {
        self::only($body, []);
        return $this->db->transaction(function () use ($scope, $id, $deviceId): array {
            $row = $this->row(self::uuid($id), true);
            $this->authorize($scope, $row, $deviceId);
            if ($row['status'] === 'confirmed') {
                return $this->view($scope, $row);
            }
            $this->db->createCommand("UPDATE storage.transfers SET status='aborted',reserved_bytes=0,reserved_files=0,
                updated_at=now() WHERE id=CAST(:id AS uuid)", [':id' => $row['id']])->execute();
            if ($row['direction'] === 'upload') {
                $this->queueStaging($row);
                if ($row['uploadId'] !== null) {
                    try {
                        $this->clients->internal()->abortMultipartUpload(['Bucket' => $this->config->bucketStorage,
                            'Key' => $row['stagingKey'], 'UploadId' => $row['uploadId']]);
                    } catch (AwsException) {
                        // The durable outbox retries cancellation when S3 is reachable.
                    }
                }
                $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key) VALUES(CAST(:key AS uuid))
                    ON CONFLICT(storage_key) DO NOTHING', [':key' => $row['finalStorageKey']])->execute();
            }
            $this->writeAudit($scope, 'transfer_abort', $row['id']);
            return $this->view($scope, $this->row($row['id']));
        });
    }

    private function publish(StorageScope $scope, array $row, ?string $deviceId): array
    {
        $bucket = $this->config->bucketStorage;
        $this->clients->assertBucket($bucket);
        $this->db->createCommand("UPDATE storage.transfers SET status='verifying',updated_at=now()
            WHERE id=CAST(:id AS uuid)", [':id' => $row['id']])->execute();
        if ($row['mode'] === 'multipart' && $this->objects->head($bucket, $row['stagingKey']) === null) {
            $parts = $this->remoteParts($row);
            if (count($parts) !== $row['totalParts']) {
                throw new StorageError(409, 'upload_incomplete');
            }
            foreach ($parts as $index => $part) {
                $expected = $index + 1 === $row['totalParts']
                    ? $row['expectedSize'] - $index * $row['partSize'] : $row['partSize'];
                if ($part['partNumber'] !== $index + 1 || $part['sizeBytes'] !== $expected) {
                    throw new StorageError(422, 'part_size_mismatch');
                }
            }
            $this->s3(function () use ($row, $parts, $bucket): void {
                $this->clients->internal()->completeMultipartUpload([
                    'Bucket' => $bucket, 'Key' => $row['stagingKey'], 'UploadId' => $row['uploadId'],
                    'MultipartUpload' => ['Parts' => array_map(static function (array $part): array {
                        $item = ['PartNumber' => $part['partNumber'], 'ETag' => $part['etag']];
                        if (isset($part['checksum'])) {
                            $item['ChecksumSHA256'] = $part['checksum'];
                        }
                        return $item;
                    }, $parts)],
                ]);
            });
        }
        $head = $this->objects->head($bucket, $row['stagingKey']);
        if ($head === null) {
            throw new StorageError(409, 'upload_missing');
        }
        if ($head['sizeBytes'] !== $row['expectedSize']) {
            throw new StorageError(422, 'size_mismatch');
        }
        $referenced = $this->db->createCommand(
            'SELECT 1 FROM storage.versions WHERE storage_key=CAST(:key AS uuid)',
            [':key' => $row['finalStorageKey']],
        )->queryScalar();
        if ($referenced !== false) {
            throw new StorageError(409, 'publication_conflict');
        }
        $finalKey = S3Objects::objectKey($row['finalStorageKey']);
        // Only staging keys receive signed PUTs. Verify the final copy so a PUT
        // racing CopyObject cannot publish bytes different from the receipt.
        $this->objects->copy($bucket, $row['stagingKey'], $finalKey);
        $verified = $this->objects->verify($bucket, $finalKey, $row['expectedSize']);
        if ($verified['sizeBytes'] !== $row['expectedSize']) {
            throw new StorageError(422, 'size_mismatch');
        }
        if (!hash_equals($row['expectedSha256'], $verified['sha256'])) {
            throw new StorageError(422, 'checksum_mismatch');
        }
        $this->authorize($scope, $row, $deviceId);
        // A durable transfer can outlive its short ticket. The transaction has
        // authenticated the current actor and holds the space and transfer locks.
        $this->db->createCommand('UPDATE storage.tickets SET expires_at=GREATEST(expires_at,now()+interval \'1 minute\')
            WHERE id=CAST(:id AS uuid) AND used_at IS NULL', [':id' => $row['ticketId']])->execute();
        $result = $this->store->commitUpload(
            $row['ticketId'],
            $scope,
            $row['path'],
            $verified['sizeBytes'],
            $verified['sha256'],
            $row['finalStorageKey'],
        );
        if ($result['reused'] ?? false) {
            $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key) VALUES(CAST(:key AS uuid))
                ON CONFLICT(storage_key) DO NOTHING', [':key' => $row['finalStorageKey']])->execute();
        }
        return ['path' => $row['path'], 'version' => (int) $result['version'],
            'sha256' => $verified['sha256'], 'size' => $verified['sizeBytes']];
    }

    private function initializeMultipart(StorageScope $scope, string $id, ?string $deviceId): void
    {
        $this->db->transaction(function () use ($scope, $id, $deviceId): void {
            $row = $this->row($id, true);
            $this->authorize($scope, $row, $deviceId);
            if ($row['direction'] !== 'upload' || $row['mode'] !== 'multipart'
                || $row['uploadId'] !== null || !in_array($row['status'], ['pending', 'uploading'], true)) {
                return;
            }
            $this->assertOpen($row);
            $result = $this->s3(fn() => $this->clients->internal()->createMultipartUpload([
                'Bucket' => $this->config->bucketStorage, 'Key' => $row['stagingKey'],
                'ContentType' => 'application/octet-stream', 'ChecksumAlgorithm' => 'SHA256',
            ]));
            $this->db->createCommand("UPDATE storage.transfers SET multipart_upload_id=:upload,status='uploading',updated_at=now()
                WHERE id=CAST(:id AS uuid)", [':id' => $id, ':upload' => (string) $result['UploadId']])->execute();
        });
    }

    private function view(StorageScope $scope, array $row): array
    {
        $active = in_array($row['status'], ['pending', 'uploading', 'verifying'], true)
            && new DateTimeImmutable($row['expiresAt']) > new DateTimeImmutable();
        $status = !$active && in_array($row['status'], ['pending', 'uploading', 'verifying'], true) ? 'expired' : $row['status'];
        $request = null;
        $parts = [];
        if ($active && $row['direction'] === 'download') {
            $file = $this->downloadVersion($scope, $row);
            $request = $this->sign('GetObject', ['Bucket' => $this->config->bucketStorage,
                'Key' => S3Objects::objectKey($file['storageKey']),
                'ResponseContentType' => 'application/octet-stream',
                'ResponseContentDisposition' => "attachment; filename*=UTF-8''" . rawurlencode(basename(str_replace('\\', '/', $row['path']))),
            ]);
        } elseif ($active && $row['mode'] === 'single') {
            $request = $this->sign('PutObject', ['Bucket' => $this->config->bucketStorage, 'Key' => $row['stagingKey'],
                'ContentType' => 'application/octet-stream', 'ContentLength' => $row['expectedSize'],
                'ChecksumSHA256' => base64_encode(hex2bin($row['expectedSha256']))]);
        } elseif ($active && $row['uploadId'] !== null) {
            try {
                $parts = $this->remoteParts($row);
            } catch (StorageError $error) {
                if ($error->reason !== 'multipart_finished') {
                    throw $error;
                }
                $status = 'verifying';
            }
        }
        return ['id' => $row['id'], 'ticketId' => $row['ticketId'], 'direction' => $row['direction'],
            'status' => $status, 'mode' => $row['mode'], 'expiresAt' => $row['expiresAt'],
            'expectedSize' => $row['expectedSize'], 'expectedSha256' => $row['expectedSha256'],
            'partSize' => $row['partSize'], 'totalParts' => $row['totalParts'], 'request' => $request,
            'completedParts' => array_map(static function (array $part): array {
                unset($part['checksum']);
                return $part;
            }, $parts),
            'result' => $row['result']];
    }

    private function downloadVersion(StorageScope $scope, array $row): array
    {
        $ticket = $this->one(
            'SELECT file_version_id::text AS version FROM storage.tickets WHERE id=CAST(:id AS uuid)',
            [':id' => $row['ticketId']],
        );
        $file = $ticket === null || $ticket['version'] === null ? null : $this->store->versionById($scope, $ticket['version']);
        if ($file === null || $file['path'] !== $row['path'] || !hash_equals($file['sha256'], $row['expectedSha256'])
            || (int) $file['sizeBytes'] !== $row['expectedSize']) {
            throw new StorageError(409, 'ticket_stale');
        }
        $head = $this->objects->head($this->config->bucketStorage, S3Objects::objectKey($file['storageKey']));
        if ($head === null || $head['sizeBytes'] !== $row['expectedSize']) {
            throw new StorageError(409, 'object_missing');
        }
        return $file;
    }

    private function sign(string $operation, array $parameters): array
    {
        return $this->s3(function () use ($operation, $parameters): array {
            $expires = new DateTimeImmutable('+' . $this->config->presignTtlSeconds . ' seconds');
            $command = $this->clients->public()->getCommand($operation, $parameters);
            $request = $this->clients->public()->createPresignedRequest($command, $expires);
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), ['host', 'content-length', 'user-agent'], true)) {
                    continue;
                }
                $headers[$name] = implode(', ', $values);
            }
            return ['url' => (string) $request->getUri(), 'method' => $request->getMethod(),
                'headers' => (object) $headers, 'expiresAt' => $expires->format(DATE_ATOM)];
        });
    }

    /** @return list<array{partNumber:int,sizeBytes:int,etag:string,sha256?:string,checksum?:string}> */
    private function remoteParts(array $row): array
    {
        try {
            $items = [];
            $saved = $this->db->createCommand('SELECT part_number AS number,sha256 FROM storage.transfer_parts
                WHERE transfer_id=CAST(:id AS uuid)', [':id' => $row['id']])->queryAll();
            $hashes = array_column($saved, 'sha256', 'number');
            $pages = $this->clients->internal()->getPaginator('ListParts', ['Bucket' => $this->config->bucketStorage,
                'Key' => $row['stagingKey'], 'UploadId' => $row['uploadId'], 'MaxParts' => 1000]);
            foreach ($pages as $page) {
                foreach ($page['Parts'] ?? [] as $part) {
                    $number = (int) $part['PartNumber'];
                    $item = ['partNumber' => $number, 'sizeBytes' => (int) $part['Size'], 'etag' => (string) $part['ETag']];
                    if (isset($hashes[$number])) {
                        $item['sha256'] = $hashes[$number];
                    }
                    if (isset($part['ChecksumSHA256'])) {
                        $item['checksum'] = (string) $part['ChecksumSHA256'];
                    } elseif (isset($hashes[$number])) {
                        // SeaweedFS can omit checksums from ListParts although
                        // checksum-enabled CompleteMultipartUpload requires them.
                        // This is the digest bound to the signed UploadPart;
                        // publication still verifies the entire final object.
                        $item['checksum'] = base64_encode(hex2bin($hashes[$number]));
                    }
                    $items[] = $item;
                }
            }
            return $items;
        } catch (AwsException $error) {
            if ($error->getAwsErrorCode() === 'NoSuchUpload'
                && $this->objects->head($this->config->bucketStorage, $row['stagingKey']) !== null) {
                throw new StorageError(409, 'multipart_finished');
            }
            throw new StorageError(503, 's3_unavailable');
        }
    }

    private function authorize(StorageScope $scope, array $row, ?string $deviceId): void
    {
        $space = $this->store->space($scope);
        if ($row['spaceId'] !== $space['id'] || $row['deviceId'] !== $deviceId
            || ($deviceId === null && $row['actorAccountId'] !== $scope->accountId)) {
            throw new StorageError(404, 'not_found');
        }
        $active = $this->db->createCommand(
            "SELECT 1 FROM education.students s
            JOIN core.persons p ON p.id=s.person_id AND p.status='active'
            JOIN core.organization_memberships m ON m.organization_id=s.organization_id AND m.person_id=s.person_id AND m.status='active'
            WHERE s.id=CAST(:student AS uuid) AND s.organization_id=CAST(:org AS uuid)
            AND EXISTS(SELECT 1 FROM core.modules WHERE code='storage' AND enabled=true)",
            [':student' => $scope->studentId, ':org' => $scope->organizationId],
        )->queryScalar();
        if ($active === false) {
            throw new StorageError(403, 'access_revoked');
        }
        if ($deviceId !== null) {
            $session = $this->devices->activeSession($deviceId);
            if ($session === null || $session['studentId'] !== $scope->studentId
                || $session['organizationId'] !== $scope->organizationId) {
                throw new StorageError(403, 'access_revoked');
            }
        } else {
            $account = $this->db->createCommand(
                "SELECT 1 FROM core.accounts a JOIN education.students s ON s.person_id=a.person_id
                JOIN education.student_accounts sa ON sa.student_id=s.id AND sa.account_id=a.id AND sa.revoked_at IS NULL
                WHERE a.id=CAST(:actor AS uuid) AND a.status='active' AND a.archived_at IS NULL AND a.disabled_at IS NULL
                AND s.id=CAST(:student AS uuid) AND s.organization_id=CAST(:org AS uuid)",
                [':actor' => $scope->accountId, ':student' => $scope->studentId, ':org' => $scope->organizationId],
            )->queryScalar();
            if ($account === false || !$this->permissions->hasPermission($scope->accountId, 'education.student.use')) {
                throw new StorageError(403, 'access_revoked');
            }
        }
    }

    /** @return array{int,int} */
    private function reserve(StorageScope $scope, array $space, string $path, int $size): array
    {
        $pending = $this->one(
            "SELECT id::text FROM storage.transfers WHERE space_id=CAST(:space AS uuid) AND path=:path
            AND direction='upload' AND status IN ('pending','uploading','verifying') AND expires_at>now()",
            [':space' => $space['id'], ':path' => $path],
        );
        if ($pending !== null) {
            throw new StorageError(409, 'transfer_in_progress');
        }
        $current = $this->store->fileByPath($scope, $path);
        $bytes = max(0, $size - (int) ($current['sizeBytes'] ?? 0));
        $files = $current === null ? 1 : 0;
        $reserved = $this->one("SELECT coalesce(sum(reserved_bytes),0) AS bytes,coalesce(sum(reserved_files),0) AS files
            FROM storage.transfers WHERE space_id=CAST(:space AS uuid) AND direction='upload'
            AND status IN ('pending','uploading','verifying') AND expires_at>now()", [':space' => $space['id']]);
        if ((int) $space['totalBytes'] + (int) $reserved['bytes'] + $bytes > (int) $space['maxBytes']
            || (int) $space['fileCount'] + (int) $reserved['files'] + $files > (int) $space['maxFiles']) {
            throw new StorageError(409, 'quota_exceeded');
        }
        return [$bytes, $files];
    }

    private function queueStaging(array $row): void
    {
        $this->db->createCommand('INSERT INTO storage.object_cleanup(object_key,multipart_upload_id,transfer_id,not_before)
            VALUES(:key,:upload,CAST(:id AS uuid),now()+CAST(:seconds AS int)*interval \'1 second\')
            ON CONFLICT(object_key) DO NOTHING', [':key' => $row['stagingKey'], ':upload' => $row['uploadId'],
            ':id' => $row['id'], ':seconds' => 960])->execute();
    }

    private function row(string $id, bool $lock = false): array
    {
        return $this->one('SELECT id::text,ticket_id::text AS "ticketId",space_id::text AS "spaceId",
            actor_account_id::text AS "actorAccountId",device_id::text AS "deviceId",direction,path,status,mode,
            expected_size AS "expectedSize",expected_sha256 AS "expectedSha256",staging_key AS "stagingKey",
            final_storage_key::text AS "finalStorageKey",multipart_upload_id AS "uploadId",part_size AS "partSize",
            total_parts AS "totalParts",expires_at::text AS "expiresAt",result FROM storage.transfers
            WHERE id=CAST(:id AS uuid)' . ($lock ? ' FOR UPDATE' : ''), [':id' => $id]) ?? throw new StorageError(404, 'not_found');
    }

    private function byTicket(string $id): ?array
    {
        $found = $this->db->createCommand(
            'SELECT id::text FROM storage.transfers WHERE ticket_id=CAST(:id AS uuid)',
            [':id' => $id],
        )->queryScalar();
        return is_string($found) ? $this->row($found) : null;
    }

    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->db->createCommand($sql, $parameters)->queryOne();
        if (!is_array($row)) {
            return null;
        }
        foreach (['expectedSize', 'partSize', 'totalParts'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }
        if (isset($row['result']) && is_string($row['result'])) {
            $row['result'] = json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);
        }
        if (isset($row['expiresAt'])) {
            $row['expiresAt'] = (new DateTimeImmutable($row['expiresAt']))->format(DATE_ATOM);
        }
        return $row;
    }

    private function assertOpen(array $row): void
    {
        if (!in_array($row['status'], ['pending', 'uploading', 'verifying'], true)) {
            throw new StorageError(409, 'transfer_closed');
        }
        if (new DateTimeImmutable($row['expiresAt']) <= new DateTimeImmutable()) {
            throw new StorageError(409, 'transfer_expired');
        }
    }

    private function lockSpace(string $id): void
    {
        $this->db->createCommand('SELECT id FROM storage.spaces WHERE id=CAST(:id AS uuid) FOR UPDATE', [':id' => $id])->queryScalar();
    }

    private function writeAudit(StorageScope $scope, string $operation, string $id): void
    {
        $this->audit->write(new WriteAuditEventCommand(
            actorType: $scope->accountId === '' ? 'system' : 'account',
            actorAccountId: $scope->accountId === '' ? null : $scope->accountId,
            action: 'storage.' . $operation,
            resourceType: 'storage.transfer',
            resourceId: $id,
            metadata: ['organizationId' => $scope->organizationId],
        ));
    }

    private function s3(callable $action): mixed
    {
        try {
            return $action();
        } catch (StorageError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new StorageError(503, 's3_unavailable');
        }
    }

    private function newId(): string
    {
        return (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new StorageError(422, 'invalid_input');
        }
        return $value;
    }

    private static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new StorageError(422, 'invalid_input');
        }
    }
}
