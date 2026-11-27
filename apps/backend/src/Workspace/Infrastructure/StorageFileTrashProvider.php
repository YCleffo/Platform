<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use App\Workspace\Application\TrashProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class StorageFileTrashProvider implements TrashProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function entityType(): string
    {
        return 'storage.file';
    }

    public function moduleCode(): string
    {
        return 'storage';
    }

    public function trash(AuthenticatedIdentity $identity, string $organizationId, string $entityId): array
    {
        if (!$this->access->isModuleEnabled('storage')) {
            throw new HttpError(404, 'module_unavailable');
        }
        $personId = $identity->personId ?? '';

        $row = $this->db->createCommand(
            'SELECT f.id::text, f.space_id::text AS "spaceId", f.path, f.deleted_at::text AS "deletedAt",
                    s.subject_person_id::text AS "subjectPersonId", s.account_id::text AS "accountId",
                    v.size_bytes AS "sizeBytes", v.sha256
             FROM storage.files f
             JOIN storage.spaces s ON s.id = f.space_id
             LEFT JOIN storage.versions v ON v.id = f.current_version_id
             WHERE f.id = CAST(:id AS uuid)
               AND s.organization_id = CAST(:org AS uuid)
             FOR UPDATE OF f',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'not_found');
        }
        if (!$this->access->isOrgAdmin($identity)
            && strcasecmp((string) ($row['subjectPersonId'] ?? ''), $personId) !== 0
            && strcasecmp((string) ($row['accountId'] ?? ''), $identity->accountId) !== 0) {
            throw new HttpError(403, 'access_denied');
        }
        if ($row['deletedAt'] !== null) {
            throw new HttpError(409, 'already_trashed');
        }

        $originalPath = (string) $row['path'];
        $parkedPath = self::parkedPath($entityId, $originalPath);

        $this->db->createCommand(
            'UPDATE storage.files
             SET deleted_at = now(), path = :parkedPath, deleted_by_manifest_id = NULL
             WHERE id = CAST(:id AS uuid)',
            [':parkedPath' => $parkedPath, ':id' => $entityId],
        )->execute();

        $baseName = basename(str_replace('\\', '/', $originalPath));

        return [
            'title' => $baseName !== '' ? $baseName : $originalPath,
            'subtitle' => $originalPath,
            'metadata' => [
                'spaceId' => (string) $row['spaceId'],
                'sizeBytes' => isset($row['sizeBytes']) ? (int) $row['sizeBytes'] : 0,
                'sha256' => $row['sha256'] ?? null,
            ],
            'payload' => [
                'spaceId' => (string) $row['spaceId'],
                'path' => $originalPath,
                'parkedPath' => $parkedPath,
            ],
        ];
    }

    public function restore(string $organizationId, array $trashItem, bool $renameOnConflict): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($trashItem['payload'] ?? null) ? $trashItem['payload'] : [];
        $spaceId = (string) ($payload['spaceId'] ?? '');
        $originalPath = (string) ($payload['path'] ?? $trashItem['subtitle'] ?? '');
        if ($originalPath === '' || $spaceId === '') {
            throw new HttpError(422, 'invalid_trash_payload');
        }

        $file = $this->db->createCommand(
            'SELECT f.id::text, f.space_id::text AS "spaceId"
             FROM storage.files f
             JOIN storage.spaces s ON s.id = f.space_id
             WHERE f.id = CAST(:id AS uuid) AND s.organization_id = CAST(:org AS uuid)
             FOR UPDATE OF f',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($file)) {
            throw new HttpError(404, 'source_entity_missing');
        }

        $conflictId = $this->db->createCommand(
            'SELECT id::text FROM storage.files
             WHERE space_id = CAST(:space AS uuid)
               AND lower(path) = lower(:path)
               AND id <> CAST(:id AS uuid)
             LIMIT 1',
            [':space' => $spaceId, ':path' => $originalPath, ':id' => $entityId],
        )->queryScalar();

        $targetPath = $originalPath;
        $renamed = false;

        if (is_string($conflictId) && $conflictId !== '') {
            $suggested = $this->uniquePath($spaceId, $entityId, $originalPath);
            if (!$renameOnConflict) {
                throw new HttpError(409, 'restore_conflict', 'File path conflict on restore.', [
                    'conflictField' => 'path',
                    'existingId' => $conflictId,
                    'suggestedName' => $suggested,
                ]);
            }
            $targetPath = $suggested;
            $renamed = true;
        }

        $this->db->createCommand(
            'UPDATE storage.files SET path = :path, deleted_at = NULL WHERE id = CAST(:id AS uuid)',
            [':path' => $targetPath, ':id' => $entityId],
        )->execute();

        return [
            'entityId' => $entityId,
            'restoredTitle' => basename(str_replace('\\', '/', $targetPath)),
            'renamed' => $renamed,
        ];
    }

    public function purge(string $organizationId, array $trashItem): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        $exists = $this->db->createCommand(
            'SELECT f.id::text FROM storage.files f
             JOIN storage.spaces s ON s.id = f.space_id
             WHERE f.id = CAST(:id AS uuid) AND s.organization_id = CAST(:org AS uuid)
             FOR UPDATE OF f',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryScalar();

        if (!is_string($exists)) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = $this->db->createCommand(
            'SELECT storage_key::text FROM storage.versions WHERE file_id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->queryColumn();

        $this->db->createCommand(
            'UPDATE storage.files SET current_version_id = NULL WHERE id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        $this->db->createCommand(
            'DELETE FROM storage.versions WHERE file_id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        $this->db->createCommand(
            'DELETE FROM storage.files WHERE id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        $uniqueKeys = array_values(array_unique(array_filter($keys, static fn(string $k): bool => $k !== '')));
        $unreferencedKeys = [];
        foreach ($uniqueKeys as $k) {
            $stillUsed = $this->db->createCommand(
                'SELECT 1 FROM storage.versions WHERE storage_key::text = :key
                 UNION ALL
                 SELECT 1 FROM media.assets WHERE storage_key::text = :key
                 UNION ALL
                 SELECT 1 FROM media.variants WHERE storage_key::text = :key
                 LIMIT 1',
                [':key' => $k],
            )->queryScalar();
            if ($stillUsed === false || $stillUsed === null) {
                $unreferencedKeys[] = $k;
            }
        }

        return $unreferencedKeys;
    }

    private static function parkedPath(string $entityId, string $originalPath): string
    {
        $safe = str_replace('..', '_', $originalPath);
        $candidate = '.trashed/' . $entityId . '/' . ltrim($safe, '/');
        return mb_substr($candidate, 0, 1024);
    }

    private function uniquePath(string $spaceId, string $excludeId, string $originalPath): string
    {
        $info = pathinfo($originalPath);
        $dir = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'] . '/' : '';
        $name = $info['filename'] ?? 'file';
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

        for ($i = 1; $i <= 50; ++$i) {
            $suffix = $i === 1 ? ' (restored)' : " (restored {$i})";
            $candidate = mb_substr($dir . $name . $suffix . $ext, 0, 1024);
            $taken = $this->db->createCommand(
                'SELECT 1 FROM storage.files
                 WHERE space_id = CAST(:space AS uuid) AND lower(path) = lower(:path) AND id <> CAST(:id AS uuid)
                 LIMIT 1',
                [':space' => $spaceId, ':path' => $candidate, ':id' => $excludeId],
            )->queryScalar();
            if ($taken === false) {
                return $candidate;
            }
        }

        return mb_substr($dir . $name . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . $ext, 0, 1024);
    }
}
