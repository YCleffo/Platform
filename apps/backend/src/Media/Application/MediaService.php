<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaKind;
use App\Media\Domain\MediaStatus;
use App\Realtime\Application\RealtimeService;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use App\Storage\Infrastructure\S3StorageFileAdapter;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MediaService
{
    public const SIGNING_SECRET = 'platform_media_playback_secret_key_v1';

    public function __construct(
        private ConnectionInterface $db,
        private ?StorageFileAdapter $storage = null,
        private ?RealtimeService $realtime = null,
        private ?S3Objects $s3Objects = null,
        private ?S3Config $s3Config = null,
    ) {}

    public static function generateTicket(string $assetId, string $organizationId, string $personId, int $ttlSeconds = 7200): string
    {
        $payload = json_encode([
            'assetId' => $assetId,
            'org' => $organizationId,
            'personId' => $personId,
            'exp' => time() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $payload, self::SIGNING_SECRET);
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . $sig;
    }

    /**
     * @return array{assetId: string, org: string, personId: string, exp: int}|null
     */
    public static function verifyTicket(string $ticket): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }
        $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $expectedSig = hash_hmac('sha256', $raw, self::SIGNING_SECRET);
        if (!hash_equals($expectedSig, $parts[1])) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (($data['exp'] ?? 0) < time()) {
            return null;
        }
        if (empty($data['assetId']) || empty($data['org']) || empty($data['personId'])) {
            return null;
        }
        return [
            'assetId' => (string) $data['assetId'],
            'org' => (string) $data['org'],
            'personId' => (string) $data['personId'],
            'exp' => (int) $data['exp'],
        ];
    }

    public static function baseMimeType(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    public static function isMimeAllowedForKind(MediaKind $kind, string $detectedMime, string $originalMime): bool
    {
        $allowed = $kind->allowedMimeTypes();
        $baseDetected = self::baseMimeType($detectedMime);
        $baseOriginal = self::baseMimeType($originalMime);

        if (!in_array($baseOriginal, $allowed, true)) {
            return false;
        }
        if (in_array($baseDetected, $allowed, true)) {
            return true;
        }
        // WebM audio chunks from MediaRecorder are often detected as video/webm or application/octet-stream
        if (in_array($baseDetected, ['application/octet-stream', 'video/webm'], true)
            && in_array($baseOriginal, ['audio/webm', 'video/webm'], true)) {
            return true;
        }
        if ($baseDetected === 'application/octet-stream') {
            return true;
        }
        return false;
    }

    /**
     * Resolves an asset row by ID across organizations and verifies tenant isolation + domain ACL.
     *
     * @return array<string, mixed>
     */
    public function assertReadAccess(
        string $organizationId,
        string $personId,
        string $assetId,
        ?MediaAccessAuthorizer $authorizer = null,
    ): array {
        $asset = $this->db->createCommand(
            'SELECT a.id::text, a.owner_person_id::text AS "ownerPersonId", a.organization_id::text AS "organizationId",
                    a.original_name AS "originalName",
                    a.status, a.verified_mime AS "verifiedMime", a.original_mime AS "originalMime",
                    a.size_bytes AS "sizeBytes", a.storage_key::text AS "storageKey",
                    a.deleted_at::text AS "deletedAt"
             FROM media.assets a
             WHERE a.id = CAST(:id AS uuid)',
            [':id' => $assetId],
        )->queryOne();

        if (!is_array($asset) || ($asset['deletedAt'] ?? null) !== null) {
            throw new MediaError(404, 'not_found');
        }
        if ((string) $asset['organizationId'] !== $organizationId) {
            throw new MediaError(403, 'access_denied');
        }
        if ((string) $asset['ownerPersonId'] !== $personId) {
            if ($authorizer === null || !$authorizer->canRead($organizationId, $personId, $assetId)) {
                throw new MediaError(403, 'access_denied');
            }
        }
        return $asset;
    }

    /**
     * Reserves an upload slot, verifies quotas and MIME allowlists, and returns presigned staging credentials.
     *
     * @return array{id: string, assetId: string, storageKey: string, stagingKey: string, uploadUrl: string, playbackUrl: string, expiresAt: string}
     */
    public function reserveUpload(
        MediaScope $scope,
        string $kind,
        string $name,
        string $mime,
        int $sizeBytes,
        string $sha256,
    ): array {
        $mediaKind = MediaKind::tryFrom($kind) ?? throw new MediaError(422, 'invalid_kind');

        if ($sizeBytes <= 0 || $sizeBytes > $mediaKind->maxSizeBytes()) {
            throw new MediaError(422, 'size_exceeded');
        }

        if (!preg_match('/^[0-9a-f]{64}$/i', $sha256)) {
            throw new MediaError(422, 'invalid_checksum');
        }

        $cleanedName = preg_replace('/[^\w\.\-\s]/u', '', trim($name));
        if ($cleanedName === '') {
            $cleanedName = 'media_' . $mediaKind->value;
        }

        $baseMime = self::baseMimeType($mime);
        $allowedMimes = $mediaKind->allowedMimeTypes();
        if (!in_array($baseMime, $allowedMimes, true)) {
            throw new MediaError(422, 'unsupported_mime_type');
        }

        return $this->db->transaction(function () use ($scope, $mediaKind, $cleanedName, $baseMime, $sizeBytes, $sha256): array {
            $storageKey = (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
            $stagingKey = sprintf('staging/%s/%s/%s', $scope->organizationId, $scope->personId, $storageKey);
            $tokenHash = hash('sha256', $stagingKey . microtime(true) . random_bytes(16));
            $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d\TH:i:s.uP');

            $asset = $this->db->createCommand(
                'INSERT INTO media.assets(
                    organization_id, owner_person_id, owner_account_id,
                    kind, original_name, original_mime, size_bytes, sha256,
                    storage_key, status
                ) VALUES (
                    CAST(:org AS uuid), CAST(:person AS uuid), CAST(:account AS uuid),
                    :kind, :name, :mime, :size, :sha256,
                    CAST(:storageKey AS uuid), :status
                ) RETURNING id::text, storage_key::text AS "storageKey"',
                [
                    ':org' => $scope->organizationId,
                    ':person' => $scope->personId,
                    ':account' => $scope->accountId,
                    ':kind' => $mediaKind->value,
                    ':name' => $cleanedName,
                    ':mime' => $baseMime,
                    ':size' => $sizeBytes,
                    ':sha256' => strtolower($sha256),
                    ':storageKey' => $storageKey,
                    ':status' => MediaStatus::Reserved->value,
                ],
            )->queryOne();

            if (!is_array($asset)) {
                throw new MediaError(500, 'database_error');
            }

            $assetId = (string) $asset['id'];

            $this->db->createCommand(
                'INSERT INTO media.upload_sessions(asset_id, staging_key, token_hash, expires_at)
                VALUES (CAST(:assetId AS uuid), :stagingKey, :tokenHash, CAST(:expiresAt AS timestamptz))',
                [
                    ':assetId' => $assetId,
                    ':stagingKey' => $stagingKey,
                    ':tokenHash' => $tokenHash,
                    ':expiresAt' => $expiresAt,
                ],
            )->execute();

            $ticket = self::generateTicket($assetId, $scope->organizationId, $scope->personId);

            return [
                'id' => $assetId,
                'assetId' => $assetId,
                'storageKey' => $storageKey,
                'stagingKey' => $stagingKey,
                'uploadUrl' => '/api/media/upload/' . $assetId,
                'playbackUrl' => sprintf('/api/media/stream/%s?ticket=%s', $assetId, $ticket),
                'expiresAt' => $expiresAt,
            ];
        });
    }

    /**
     * Confirms that binary upload completed into storage, validates metadata, and transitions status.
     *
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>
     */
    public function confirmUpload(
        MediaScope $scope,
        string $assetId,
        ?array $metadata = null,
    ): array {
        return $this->db->transaction(function () use ($scope, $assetId, $metadata): array {
            $asset = $this->db->createCommand(
                'SELECT id::text, status, kind, original_mime, size_bytes, sha256, storage_key::text AS "storageKey",
                        deleted_at::text AS "deletedAt"
                FROM media.assets
                WHERE id = CAST(:id AS uuid)
                AND organization_id = CAST(:org AS uuid)
                AND owner_person_id = CAST(:person AS uuid)',
                [
                    ':id' => $assetId,
                    ':org' => $scope->organizationId,
                    ':person' => $scope->personId,
                ],
            )->queryOne();

            if (!is_array($asset) || ($asset['deletedAt'] ?? null) !== null) {
                throw new MediaError(404, 'not_found');
            }

            if ($asset['status'] === MediaStatus::Cancelled->value) {
                throw new MediaError(409, 'upload_cancelled');
            }
            if ($asset['status'] === MediaStatus::Expired->value) {
                throw new MediaError(409, 'session_expired_or_invalid');
            }

            if ($asset['status'] === MediaStatus::Ready->value) {
                $storageKey = (string) $asset['storageKey'];
                if ($this->storage !== null && !$this->storage->exists($storageKey)) {
                    throw new MediaError(422, 'storage_object_missing');
                }
                $duration = isset($metadata['durationSeconds']) && is_numeric($metadata['durationSeconds'])
                    ? (float) $metadata['durationSeconds']
                    : null;
                $width = isset($metadata['width']) && is_int($metadata['width']) ? $metadata['width'] : null;
                $height = isset($metadata['height']) && is_int($metadata['height']) ? $metadata['height'] : null;
                $codec = isset($metadata['codec']) && is_string($metadata['codec']) ? $metadata['codec'] : null;
                if ($duration !== null || $width !== null || $height !== null || $codec !== null) {
                    $this->db->createCommand(
                        'UPDATE media.assets SET
                            duration_seconds = COALESCE(duration_seconds, :duration),
                            width = COALESCE(width, :width),
                            height = COALESCE(height, :height),
                            codec = COALESCE(codec, :codec),
                            updated_at = now()
                         WHERE id = CAST(:id AS uuid)',
                        [
                            ':id' => $assetId,
                            ':duration' => $duration,
                            ':width' => $width,
                            ':height' => $height,
                            ':codec' => $codec,
                        ],
                    )->execute();
                }
                return $this->asset($scope, $assetId) ?? throw new MediaError(500, 'database_error');
            }

            // Verify upload session exists and has not expired
            $session = $this->db->createCommand(
                'SELECT staging_key, expires_at FROM media.upload_sessions
                 WHERE asset_id = CAST(:id AS uuid) AND expires_at > now()',
                [':id' => $assetId],
            )->queryOne();

            if (!is_array($session)) {
                throw new MediaError(409, 'session_expired_or_invalid');
            }

            // Verify size and checksum if provided in metadata
            if (isset($metadata['sha256']) && is_string($metadata['sha256'])) {
                if (strtolower($metadata['sha256']) !== strtolower((string) $asset['sha256'])) {
                    throw new MediaError(422, 'checksum_mismatch');
                }
            }
            if (isset($metadata['sizeBytes']) && is_int($metadata['sizeBytes'])) {
                if ($metadata['sizeBytes'] !== (int) $asset['size_bytes']) {
                    throw new MediaError(422, 'size_mismatch');
                }
            }

            // Fail closed when storage adapter is unavailable — never synthesize READY
            if ($this->storage === null) {
                throw new MediaError(503, 'storage_unavailable');
            }

            $storageKey = (string) $asset['storageKey'];
            $stagingKey = (string) $session['staging_key'];
            $sourceKey = $this->storage->exists($storageKey)
                ? $storageKey
                : ($this->storage->exists($stagingKey) ? $stagingKey : null);
            if ($sourceKey === null) {
                throw new MediaError(422, 'storage_object_missing');
            }

            // Verify exact binary size, SHA-256 checksum, and detected MIME from real S3 object
            $stream = $this->storage->open($sourceKey);
            $actualBytes = 0;
            $hashCtx = hash_init('sha256');
            $sample = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if (strlen($sample) < 2048) {
                    $sample .= substr($chunk, 0, 2048 - strlen($sample));
                }
                $actualBytes += strlen($chunk);
                hash_update($hashCtx, $chunk);
            }
            $actualSha256 = hash_final($hashCtx);
            fclose($stream);

            if ($actualBytes !== (int) $asset['size_bytes']) {
                throw new MediaError(422, 'size_mismatch');
            }
            if (strtolower($actualSha256) !== strtolower((string) $asset['sha256'])) {
                throw new MediaError(422, 'checksum_mismatch');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $sample !== '' ? ($finfo->buffer($sample) ?: (string) $asset['original_mime']) : (string) $asset['original_mime'];
            $mediaKind = MediaKind::tryFrom((string) $asset['kind']);
            if ($mediaKind !== null && !self::isMimeAllowedForKind($mediaKind, $detectedMime, (string) $asset['original_mime'])) {
                throw new MediaError(422, 'unsupported_mime_type');
            }

            if ($sourceKey !== $storageKey) {
                $copyStream = $this->storage->open($stagingKey);
                $this->storage->write($storageKey, $copyStream);
                fclose($copyStream);
                if (!$this->storage->exists($storageKey)) {
                    throw new MediaError(503, 'storage_write_failed');
                }
                if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $stagingKey) === 1) {
                    $this->storage->delete($stagingKey);
                } elseif ($this->s3Objects !== null && $this->s3Config !== null) {
                    $this->s3Objects->delete($this->s3Config->bucketStorage, $stagingKey);
                }
            }

            $duration = isset($metadata['durationSeconds']) && is_numeric($metadata['durationSeconds'])
                ? (float) $metadata['durationSeconds']
                : null;
            $width = isset($metadata['width']) && is_int($metadata['width']) ? $metadata['width'] : null;
            $height = isset($metadata['height']) && is_int($metadata['height']) ? $metadata['height'] : null;
            $codec = isset($metadata['codec']) && is_string($metadata['codec']) ? $metadata['codec'] : null;

            $verifiedMime = $detectedMime === 'application/octet-stream'
                ? self::baseMimeType((string) $asset['original_mime'])
                : self::baseMimeType($detectedMime);

            $affected = $this->db->createCommand(
                'UPDATE media.assets SET
                    status = :status,
                    verified_mime = :verifiedMime,
                    duration_seconds = :duration,
                    width = :width,
                    height = :height,
                    codec = :codec,
                    metadata = CAST(:meta AS jsonb),
                    updated_at = now()
                WHERE id = CAST(:id AS uuid)
                  AND status IN (:reserved, :uploading, :uploaded, :processing)',
                [
                    ':id' => $assetId,
                    ':status' => MediaStatus::Ready->value,
                    ':verifiedMime' => $verifiedMime,
                    ':duration' => $duration,
                    ':width' => $width,
                    ':height' => $height,
                    ':codec' => $codec,
                    ':meta' => json_encode($metadata ?? [], JSON_THROW_ON_ERROR),
                    ':reserved' => MediaStatus::Reserved->value,
                    ':uploading' => MediaStatus::Uploading->value,
                    ':uploaded' => MediaStatus::Uploaded->value,
                    ':processing' => MediaStatus::Processing->value,
                ],
            )->execute();

            if ($affected === 0) {
                throw new MediaError(409, 'already_ready');
            }

            // Clean up session
            $this->db->createCommand(
                'DELETE FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
                [':id' => $assetId],
            )->execute();

            return $this->asset($scope, $assetId) ?? throw new MediaError(500, 'database_error');
        });
    }

    /**
     * Cancels an in-progress or reserved media upload, purges staged/uploaded S3 objects,
     * marks the asset CANCELLED, and updates any linked workspace.operations row.
     *
     * @return array{id: string, status: string, cancelled: bool, purgedKeys: list<string>}
     */
    public function cancelUpload(MediaScope $scope, string $assetId): array
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $assetId) !== 1) {
            throw new MediaError(422, 'invalid_uuid');
        }

        /** @var array{storageKey: string, stagingKeys: list<string>, cancelledOps: list<array<string, mixed>>} $outcome */
        $outcome = $this->db->transaction(function () use ($scope, $assetId): array {
            $asset = $this->db->createCommand(
                'SELECT id::text, organization_id::text AS "organizationId", owner_person_id::text AS "ownerPersonId",
                        status, storage_key::text AS "storageKey", deleted_at::text AS "deletedAt"
                 FROM media.assets
                 WHERE id = CAST(:id AS uuid)
                 FOR UPDATE',
                [':id' => $assetId],
            )->queryOne();

            if (!is_array($asset) || ($asset['deletedAt'] ?? null) !== null) {
                throw new MediaError(404, 'not_found');
            }
            if (strcasecmp((string) $asset['organizationId'], $scope->organizationId) !== 0
                || strcasecmp((string) $asset['ownerPersonId'], $scope->personId) !== 0) {
                throw new MediaError(403, 'access_denied');
            }
            if ($asset['status'] === MediaStatus::Ready->value) {
                throw new MediaError(409, 'already_ready');
            }

            $sessionRows = $this->db->createCommand(
                'SELECT staging_key FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
                [':id' => $assetId],
            )->queryAll();
            $stagingKeys = [];
            foreach ($sessionRows as $sr) {
                if (is_string($sr['staging_key'] ?? null) && $sr['staging_key'] !== '') {
                    $stagingKeys[] = $sr['staging_key'];
                }
            }

            if ($asset['status'] !== MediaStatus::Cancelled->value) {
                $this->db->createCommand(
                    "UPDATE media.assets
                     SET status = :cancelled, failure_code = 'cancelled_by_user', updated_at = now()
                     WHERE id = CAST(:id AS uuid)",
                    [':cancelled' => MediaStatus::Cancelled->value, ':id' => $assetId],
                )->execute();
            }

            $this->db->createCommand(
                'DELETE FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
                [':id' => $assetId],
            )->execute();

            $cancelledOps = [];
            $hasWorkspaceOps = $this->db->createCommand("SELECT to_regclass('workspace.operations') IS NOT NULL")->queryScalar();
            if (in_array($hasWorkspaceOps, [true, 1, '1', 't'], true)) {
                $opRows = $this->db->createCommand(
                    "UPDATE workspace.operations
                     SET status = 'cancelled',
                         error_code = COALESCE(error_code, 'upload_cancelled'),
                         finalized_at = COALESCE(finalized_at, now()),
                         updated_at = now(),
                         revision = revision + 1
                     WHERE entity_type = 'media.asset'
                       AND entity_id = CAST(:id AS uuid)
                       AND organization_id = CAST(:org AS uuid)
                       AND status IN ('pending', 'running', 'undoable')
                     RETURNING id::text, organization_id::text AS \"organizationId\", account_id::text AS \"accountId\",
                               person_id::text AS \"personId\", action_key AS \"actionKey\", entity_type AS \"entityType\",
                               entity_id::text AS \"entityId\", mode, status, revision, finalized_at::text AS \"finalizedAt\"",
                    [':id' => $assetId, ':org' => $scope->organizationId],
                )->queryAll();
                /** @var array<string, mixed> $opRow */
                foreach ($opRows as $opRow) {
                    $cancelledOps[] = $opRow;
                }
            }

            return [
                'storageKey' => (string) $asset['storageKey'],
                'stagingKeys' => $stagingKeys,
                'cancelledOps' => $cancelledOps,
            ];
        });

        $purgedKeys = [];
        $storageKey = $outcome['storageKey'];
        if ($this->storage !== null && $storageKey !== '' && !$this->isStorageKeyReferencedElsewhere($assetId, $storageKey)) {
            if ($this->storage->exists($storageKey)) {
                $this->storage->delete($storageKey);
                $purgedKeys[] = $storageKey;
            }
        }

        foreach ($outcome['stagingKeys'] as $stagingKey) {
            if ($this->isStagingKeyReferencedElsewhere($stagingKey)) {
                continue;
            }
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $stagingKey) === 1) {
                if ($this->storage !== null && $this->storage->exists($stagingKey)) {
                    $this->storage->delete($stagingKey);
                    $purgedKeys[] = $stagingKey;
                }
            } elseif ($this->s3Objects !== null && $this->s3Config !== null) {
                $this->s3Objects->delete($this->s3Config->bucketStorage, $stagingKey);
                $purgedKeys[] = $stagingKey;
            } elseif ($this->storage !== null && !$this->storage instanceof S3StorageFileAdapter && $this->storage->exists($stagingKey)) {
                $this->storage->delete($stagingKey);
                $purgedKeys[] = $stagingKey;
            }
        }

        if ($this->realtime !== null) {
            foreach ($outcome['cancelledOps'] as $op) {
                $rawTargetPerson = $op['personId'] ?? null;
                $targetPerson = is_string($rawTargetPerson) && $rawTargetPerson !== ''
                    ? $rawTargetPerson
                    : $scope->personId;
                $this->realtime->publish(
                    organizationId: $scope->organizationId,
                    channel: 'user:' . $targetPerson,
                    eventType: 'workspace.operation.updated',
                    payload: $op,
                    actorAccountId: $scope->accountId,
                    actorPersonId: $scope->personId,
                    targetPersonIds: [$targetPerson],
                    aggregateType: 'workspace_operation',
                    aggregateId: (string) $op['id'],
                );
            }
        }

        return [
            'id' => $assetId,
            'status' => MediaStatus::Cancelled->value,
            'cancelled' => true,
            'purgedKeys' => $purgedKeys,
        ];
    }

    private function isStorageKeyReferencedElsewhere(string $excludeAssetId, string $storageKey): bool
    {
        $found = $this->db->createCommand(
            'SELECT 1 FROM media.assets WHERE storage_key = CAST(:key AS uuid) AND id <> CAST(:id AS uuid)
             UNION ALL
             SELECT 1 FROM media.variants WHERE storage_key = CAST(:key AS uuid)
             UNION ALL
             SELECT 1 FROM storage.versions WHERE storage_key = CAST(:key AS uuid)
             LIMIT 1',
            [':key' => $storageKey, ':id' => $excludeAssetId],
        )->queryScalar();
        return $found !== false && $found !== null;
    }

    private function isStagingKeyReferencedElsewhere(string $stagingKey): bool
    {
        $found = $this->db->createCommand(
            'SELECT 1 FROM media.upload_sessions WHERE staging_key = :key LIMIT 1',
            [':key' => $stagingKey],
        )->queryScalar();
        return $found !== false && $found !== null;
    }

    /**
     * Resolves asset metadata by ID within organization scope.
     *
     * @return array<string,mixed>|null
     */
    public function asset(MediaScope $scope, string $assetId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT a.id::text, a.kind, a.original_name AS "originalName",
                a.original_mime AS "originalMime", a.verified_mime AS "verifiedMime",
                a.size_bytes AS "sizeBytes", a.sha256, a.storage_key::text AS "storageKey",
                a.duration_seconds AS "durationSeconds", a.width, a.height, a.codec,
                a.status, a.metadata, a.created_at::text AS "createdAt", a.updated_at::text AS "updatedAt"
            FROM media.assets a
            WHERE a.id = CAST(:id AS uuid)
            AND a.organization_id = CAST(:org AS uuid)
            AND a.deleted_at IS NULL',
            [
                ':id' => $assetId,
                ':org' => $scope->organizationId,
            ],
        )->queryOne();

        if (!is_array($row)) {
            return null;
        }

        $row['sizeBytes'] = (int) $row['sizeBytes'];
        $row['durationSeconds'] = $row['durationSeconds'] !== null ? (float) $row['durationSeconds'] : null;
        $row['width'] = $row['width'] !== null ? (int) $row['width'] : null;
        $row['height'] = $row['height'] !== null ? (int) $row['height'] : null;
        if (is_string($row['metadata'])) {
            $row['metadata'] = json_decode($row['metadata'], true) ?? [];
        }

        // Fetch variants
        $variants = $this->db->createCommand(
            'SELECT variant_key AS "variantKey", storage_key::text AS "storageKey",
                mime_type AS "mimeType", size_bytes AS "sizeBytes", width, height, metadata
            FROM media.variants WHERE asset_id = CAST(:id AS uuid)',
            [':id' => $assetId],
        )->queryAll();

        $row['variants'] = array_map(static function (array $v): array {
            $v['sizeBytes'] = (int) $v['sizeBytes'];
            $v['width'] = $v['width'] !== null ? (int) $v['width'] : null;
            $v['height'] = $v['height'] !== null ? (int) $v['height'] : null;
            if (is_string($v['metadata'])) {
                $v['metadata'] = json_decode($v['metadata'], true) ?? [];
            }
            return $v;
        }, $variants);

        return $row;
    }

    /**
     * Returns secure playback credentials / URL.
     *
     * @return array{url: string, mime: string, durationSeconds: ?float, expiresAt: string}
     */
    public function playbackUrl(MediaScope $scope, string $assetId, ?string $variant = null): array
    {
        $asset = $this->asset($scope, $assetId);
        if ($asset === null || $asset['status'] !== MediaStatus::Ready->value) {
            throw new MediaError(404, 'media_not_ready');
        }

        /** @var string $storageKey */
        $storageKey = (string) $asset['storageKey'];
        /** @var string $mime */
        $mime = (string) ($asset['verifiedMime'] ?? $asset['originalMime']);

        if ($variant !== null && is_iterable($asset['variants'])) {
            /** @var mixed $v */
            foreach ($asset['variants'] as $v) {
                if (is_array($v) && ($v['variantKey'] ?? null) === $variant) {
                    $storageKey = (string) ($v['storageKey'] ?? $storageKey);
                    $mime = (string) ($v['mimeType'] ?? $mime);
                    break;
                }
            }
        }

        $ticket = self::generateTicket($assetId, $scope->organizationId, $scope->personId);
        $expiresAt = (new DateTimeImmutable('+2 hours'))->format('Y-m-d\TH:i:s.uP');

        return [
            'url' => sprintf('/api/media/stream/%s?ticket=%s', $assetId, $ticket),
            'mime' => $mime,
            'durationSeconds' => $asset['durationSeconds'] !== null ? (float) $asset['durationSeconds'] : null,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Lists assets by kind within scope.
     *
     * @return array{items: list<array<string,mixed>>, limit: int, offset: int}
     */
    public function listAssets(MediaScope $scope, ?string $kind = null, int $offset = 0): array
    {
        $kindFilter = $kind !== null ? 'AND a.kind = :kind' : '';
        $params = [
            ':org' => $scope->organizationId,
            ':person' => $scope->personId,
            ':offset' => max(0, $offset),
        ];
        if ($kind !== null) {
            $params[':kind'] = $kind;
        }

        $rows = $this->db->createCommand(
            "SELECT a.id::text, a.kind, a.original_name AS \"originalName\",
                a.original_mime AS \"originalMime\", a.verified_mime AS \"verifiedMime\",
                a.size_bytes AS \"sizeBytes\", a.sha256, a.duration_seconds AS \"durationSeconds\",
                a.width, a.height, a.codec, a.status, a.metadata,
                a.created_at::text AS \"createdAt\", a.updated_at::text AS \"updatedAt\"
            FROM media.assets a
            WHERE a.organization_id = CAST(:org AS uuid)
            AND a.owner_person_id = CAST(:person AS uuid)
            AND a.deleted_at IS NULL
            $kindFilter
            ORDER BY a.created_at DESC
            LIMIT 50 OFFSET :offset",
            $params,
        )->queryAll();

        /** @var list<array<string, mixed>> $items */
        $items = array_map(static function (array $r): array {
            $r['sizeBytes'] = (int) $r['sizeBytes'];
            $r['durationSeconds'] = $r['durationSeconds'] !== null ? (float) $r['durationSeconds'] : null;
            $r['width'] = $r['width'] !== null ? (int) $r['width'] : null;
            $r['height'] = $r['height'] !== null ? (int) $r['height'] : null;
            $rawMeta = $r['metadata'] ?? null;
            if (is_string($rawMeta)) {
                $decoded = json_decode($rawMeta, true);
                $r['metadata'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($rawMeta)) {
                $r['metadata'] = [];
            }
            return $r;
        }, $rows);

        return [
            'items' => $items,
            'limit' => 50,
            'offset' => $offset,
        ];
    }

    /**
     * Renames an owned media asset.
     *
     * @return array<string, mixed>
     */
    public function renameAsset(MediaScope $scope, string $assetId, string $newName): array
    {
        $cleanName = trim($newName);
        if ($cleanName === '' || mb_strlen($cleanName) > 255) {
            throw new MediaError(422, 'invalid_name');
        }
        $cleanName = str_replace(['/', '\\', "\0"], '_', $cleanName);

        $updated = $this->db->createCommand(
            "UPDATE media.assets
             SET original_name = :name, updated_at = now()
             WHERE id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND owner_person_id = CAST(:person AS uuid)
               AND deleted_at IS NULL
               AND status <> 'CANCELLED'",
            [
                ':name' => $cleanName,
                ':id' => $assetId,
                ':org' => $scope->organizationId,
                ':person' => $scope->personId,
            ],
        )->execute();

        if ($updated === 0) {
            throw new MediaError(404, 'not_found');
        }

        return $this->asset($scope, $assetId) ?? throw new MediaError(404, 'not_found');
    }

    /**
     * Moves an owned media asset into a virtual folder path stored in metadata.
     *
     * @return array<string, mixed>
     */
    public function moveAsset(MediaScope $scope, string $assetId, string $folderPath): array
    {
        $normalized = self::normalizeFolderPath($folderPath);
        $asset = $this->asset($scope, $assetId);
        if ($asset === null) {
            throw new MediaError(404, 'not_found');
        }
        $ownerCheck = $this->db->createCommand(
            "SELECT 1 FROM media.assets
             WHERE id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND owner_person_id = CAST(:person AS uuid)
               AND deleted_at IS NULL
               AND status <> 'CANCELLED'",
            [':id' => $assetId, ':org' => $scope->organizationId, ':person' => $scope->personId],
        )->queryScalar();
        if ($ownerCheck === false) {
            throw new MediaError(403, 'access_denied');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($asset['metadata'] ?? null) ? $asset['metadata'] : [];
        if ($normalized === '') {
            unset($metadata['folderPath']);
        } else {
            $metadata['folderPath'] = $normalized;
        }

        $this->db->createCommand(
            'UPDATE media.assets SET metadata = CAST(:meta AS jsonb), updated_at = now() WHERE id = CAST(:id AS uuid)',
            [
                ':meta' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ':id' => $assetId,
            ],
        )->execute();

        return $this->asset($scope, $assetId) ?? throw new MediaError(404, 'not_found');
    }

    /**
     * Creates a reference-safe copy of an owned media asset (sharing the physical S3 object key).
     *
     * @return array<string, mixed>
     */
    public function copyAsset(MediaScope $scope, string $assetId, ?string $targetFolderPath = null): array
    {
        $row = $this->db->createCommand(
            "SELECT id::text, kind, original_name AS \"originalName\", original_mime AS \"originalMime\",
                    verified_mime AS \"verifiedMime\", size_bytes AS \"sizeBytes\", sha256,
                    storage_key::text AS \"storageKey\", duration_seconds AS \"durationSeconds\",
                    width, height, codec, status, metadata
             FROM media.assets
             WHERE id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND owner_person_id = CAST(:person AS uuid)
               AND deleted_at IS NULL
               AND status = 'READY'",
            [
                ':id' => $assetId,
                ':org' => $scope->organizationId,
                ':person' => $scope->personId,
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new MediaError(404, 'not_found');
        }

        $newId = self::uuidV4();
        $sourceStorageKey = (string) $row['storageKey'];
        $newStorageKey = self::uuidV4();
        if ($this->storage instanceof \App\Storage\Application\StorageObjectAdapter) {
            $this->storage->copy($sourceStorageKey, $newStorageKey);
        } elseif ($this->storage !== null) {
            $stream = $this->storage->open($sourceStorageKey);
            try {
                $this->storage->write($newStorageKey, $stream);
            } finally {
                fclose($stream);
            }
        } else {
            throw new MediaError(503, 'storage_unavailable');
        }

        $origName = (string) $row['originalName'];
        $info = pathinfo($origName);
        $base = $info['filename'] ?? 'file';
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
        $copyName = mb_substr($base . ' (copy)' . $ext, 0, 255);

        $rawCopyMeta = $row['metadata'] ?? null;
        $metadata = is_string($rawCopyMeta)
            ? (json_decode($rawCopyMeta, true) ?: [])
            : (is_array($rawCopyMeta) ? $rawCopyMeta : []);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        if ($targetFolderPath !== null) {
            $normalized = self::normalizeFolderPath($targetFolderPath);
            if ($normalized === '') {
                unset($metadata['folderPath']);
            } else {
                $metadata['folderPath'] = $normalized;
            }
        }

        $this->db->createCommand(
            'INSERT INTO media.assets(
                id, organization_id, owner_account_id, owner_person_id, kind,
                original_name, original_mime, verified_mime, size_bytes, sha256,
                storage_key, duration_seconds, width, height, codec, status, metadata
             ) VALUES (
                CAST(:id AS uuid), CAST(:org AS uuid), CAST(:acc AS uuid), CAST(:person AS uuid), :kind,
                :origName, :origMime, :verMime, :sizeBytes, :sha256,
                CAST(:storageKey AS uuid), :duration, :width, :height, :codec, :status, CAST(:meta AS jsonb)
             )',
            [
                ':id' => $newId,
                ':org' => $scope->organizationId,
                ':acc' => $scope->accountId,
                ':person' => $scope->personId,
                ':kind' => (string) $row['kind'],
                ':origName' => $copyName,
                ':origMime' => (string) $row['originalMime'],
                ':verMime' => $row['verifiedMime'],
                ':sizeBytes' => (int) $row['sizeBytes'],
                ':sha256' => (string) $row['sha256'],
                ':storageKey' => $newStorageKey,
                ':duration' => $row['durationSeconds'],
                ':width' => $row['width'],
                ':height' => $row['height'],
                ':codec' => $row['codec'],
                ':status' => MediaStatus::Ready->value,
                ':meta' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ],
        )->execute();

        return $this->asset($scope, $newId) ?? throw new MediaError(404, 'not_found');
    }

    private static function normalizeFolderPath(string $rawPath): string
    {
        $clean = trim(str_replace('\\', '/', $rawPath));
        $clean = trim($clean, '/');
        if ($clean === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $clean), static fn(string $s): bool => $s !== '' && $s !== '.'));
        foreach ($segments as $seg) {
            if ($seg === '..' || str_contains($seg, "\0")) {
                throw new MediaError(422, 'invalid_folder_path');
            }
        }
        $normalized = implode('/', $segments);
        if (mb_strlen($normalized) > 500) {
            throw new MediaError(422, 'invalid_folder_path');
        }
        return $normalized;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
