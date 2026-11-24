<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaKind;
use App\Media\Domain\MediaStatus;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MediaService
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * Reserves an upload slot, verifies quotas and MIME allowlists, and returns presigned staging credentials.
     *
     * @return array{id: string, storageKey: string, stagingKey: string, uploadUrl: string, expiresAt: string}
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

        $allowedMimes = $mediaKind->allowedMimeTypes();
        if (!in_array(strtolower($mime), $allowedMimes, true)) {
            throw new MediaError(422, 'unsupported_mime_type');
        }

        return $this->db->transaction(function () use ($scope, $mediaKind, $cleanedName, $mime, $sizeBytes, $sha256): array {
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
                    ':mime' => strtolower($mime),
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

            return [
                'id' => $assetId,
                'storageKey' => $storageKey,
                'stagingKey' => $stagingKey,
                'uploadUrl' => '/api/media/upload/' . $assetId,
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
                'SELECT id::text, status, kind, original_mime, size_bytes, sha256, storage_key::text AS "storageKey"
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

            if (!is_array($asset)) {
                throw new MediaError(404, 'not_found');
            }

            if ($asset['status'] === MediaStatus::Ready->value) {
                return $this->asset($scope, $assetId) ?? throw new MediaError(500, 'database_error');
            }

            $duration = isset($metadata['durationSeconds']) && is_numeric($metadata['durationSeconds'])
                ? (float) $metadata['durationSeconds']
                : null;
            $width = isset($metadata['width']) && is_int($metadata['width']) ? $metadata['width'] : null;
            $height = isset($metadata['height']) && is_int($metadata['height']) ? $metadata['height'] : null;
            $codec = isset($metadata['codec']) && is_string($metadata['codec']) ? $metadata['codec'] : null;

            // In Media Foundation: verified_mime matches the validated upload mime
            $verifiedMime = $asset['original_mime'];

            $this->db->createCommand(
                'UPDATE media.assets SET
                    status = :status,
                    verified_mime = :verifiedMime,
                    duration_seconds = :duration,
                    width = :width,
                    height = :height,
                    codec = :codec,
                    metadata = CAST(:meta AS jsonb),
                    updated_at = now()
                WHERE id = CAST(:id AS uuid)',
                [
                    ':id' => $assetId,
                    ':status' => MediaStatus::Ready->value,
                    ':verifiedMime' => $verifiedMime,
                    ':duration' => $duration,
                    ':width' => $width,
                    ':height' => $height,
                    ':codec' => $codec,
                    ':meta' => json_encode($metadata ?? [], JSON_THROW_ON_ERROR),
                ],
            )->execute();

            // Clean up session
            $this->db->createCommand(
                'DELETE FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
                [':id' => $assetId],
            )->execute();

            return $this->asset($scope, $assetId) ?? throw new MediaError(500, 'database_error');
        });
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
            AND a.organization_id = CAST(:org AS uuid)',
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

        $expiresAt = (new DateTimeImmutable('+2 hours'))->format('Y-m-d\TH:i:s.uP');

        return [
            'url' => sprintf('/api/media/stream/%s?key=%s', $assetId, $storageKey),
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
                a.size_bytes AS \"sizeBytes\", a.duration_seconds AS \"durationSeconds\",
                a.width, a.height, a.codec, a.status, a.created_at::text AS \"createdAt\"
            FROM media.assets a
            WHERE a.organization_id = CAST(:org AS uuid)
            AND a.owner_person_id = CAST(:person AS uuid)
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
            return $r;
        }, $rows);

        return [
            'items' => $items,
            'limit' => 50,
            'offset' => $offset,
        ];
    }
}
