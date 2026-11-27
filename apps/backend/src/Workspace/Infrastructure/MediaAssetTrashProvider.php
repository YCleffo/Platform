<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use App\Workspace\Application\TrashProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MediaAssetTrashProvider implements TrashProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function entityType(): string
    {
        return 'media.asset';
    }

    public function moduleCode(): string
    {
        return 'media';
    }

    public function trash(AuthenticatedIdentity $identity, string $organizationId, string $entityId): array
    {
        $personId = $identity->personId ?? '';
        $row = $this->db->createCommand(
            'SELECT id::text, owner_person_id::text AS "ownerPersonId", owner_account_id::text AS "ownerAccountId",
                    kind, original_name AS "originalName", original_mime AS "originalMime",
                    size_bytes AS "sizeBytes", status, storage_key::text AS "storageKey",
                    deleted_at::text AS "deletedAt"
             FROM media.assets
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'not_found');
        }
        if (!$this->access->isOrgAdmin($identity)
            && strcasecmp((string) $row['ownerPersonId'], $personId) !== 0
            && strcasecmp((string) $row['ownerAccountId'], $identity->accountId) !== 0) {
            throw new HttpError(403, 'access_denied');
        }
        if ($row['deletedAt'] !== null) {
            throw new HttpError(409, 'already_trashed');
        }

        $this->db->createCommand(
            'UPDATE media.assets SET deleted_at = now(), updated_at = now() WHERE id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        return [
            'title' => (string) $row['originalName'],
            'subtitle' => sprintf('%s (%s)', (string) $row['kind'], (string) $row['originalMime']),
            'metadata' => [
                'kind' => (string) $row['kind'],
                'mime' => (string) $row['originalMime'],
                'sizeBytes' => (int) $row['sizeBytes'],
                'status' => (string) $row['status'],
            ],
            'payload' => [
                'ownerPersonId' => (string) $row['ownerPersonId'],
                'originalName' => (string) $row['originalName'],
                'storageKey' => (string) $row['storageKey'],
            ],
        ];
    }

    public function restore(string $organizationId, array $trashItem, bool $renameOnConflict): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($trashItem['payload'] ?? null) ? $trashItem['payload'] : [];

        $row = $this->db->createCommand(
            'SELECT id::text, owner_person_id::text AS "ownerPersonId", original_name AS "originalName"
             FROM media.assets
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'source_entity_missing');
        }

        $ownerPersonId = (string) ($payload['ownerPersonId'] ?? $row['ownerPersonId']);
        $originalName = (string) ($payload['originalName'] ?? $row['originalName']);

        $conflictId = $this->db->createCommand(
            "SELECT id::text FROM media.assets
             WHERE organization_id = CAST(:org AS uuid)
               AND owner_person_id = CAST(:owner AS uuid)
               AND lower(original_name) = lower(:name)
               AND deleted_at IS NULL
               AND status <> 'CANCELLED'
               AND id <> CAST(:id AS uuid)
             LIMIT 1",
            [':org' => $organizationId, ':owner' => $ownerPersonId, ':name' => $originalName, ':id' => $entityId],
        )->queryScalar();

        $targetName = $originalName;
        $renamed = false;

        if (is_string($conflictId) && $conflictId !== '') {
            $suggested = $this->uniqueName($organizationId, $ownerPersonId, $entityId, $originalName);
            if (!$renameOnConflict) {
                throw new HttpError(409, 'restore_conflict', 'Media asset name conflict on restore.', [
                    'conflictField' => 'originalName',
                    'existingId' => $conflictId,
                    'suggestedName' => $suggested,
                ]);
            }
            $targetName = $suggested;
            $renamed = true;
        }

        $this->db->createCommand(
            'UPDATE media.assets SET original_name = :name, deleted_at = NULL, updated_at = now() WHERE id = CAST(:id AS uuid)',
            [':name' => $targetName, ':id' => $entityId],
        )->execute();

        return [
            'entityId' => $entityId,
            'restoredTitle' => $targetName,
            'renamed' => $renamed,
        ];
    }

    public function purge(string $organizationId, array $trashItem): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        $row = $this->db->createCommand(
            'SELECT id::text, storage_key::text AS "storageKey"
             FROM media.assets
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            return [];
        }

        $keys = [(string) $row['storageKey']];

        /** @var list<string> $variantKeys */
        $variantKeys = $this->db->createCommand(
            'SELECT storage_key::text FROM media.variants WHERE asset_id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->queryColumn();
        foreach ($variantKeys as $vk) {
            if ($vk !== '') {
                $keys[] = $vk;
            }
        }

        /** @var list<string> $stagingKeys */
        $stagingKeys = $this->db->createCommand(
            'SELECT staging_key FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->queryColumn();
        foreach ($stagingKeys as $sk) {
            if ($sk !== '') {
                $keys[] = $sk;
            }
        }

        $this->db->createCommand('DELETE FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)', [':id' => $entityId])->execute();
        $this->db->createCommand('DELETE FROM media.variants WHERE asset_id = CAST(:id AS uuid)', [':id' => $entityId])->execute();
        $this->db->createCommand('DELETE FROM media.assets WHERE id = CAST(:id AS uuid)', [':id' => $entityId])->execute();

        $uniqueKeys = array_values(array_unique(array_filter($keys, static fn(string $k): bool => $k !== '')));
        $unreferencedKeys = [];
        foreach ($uniqueKeys as $k) {
            $stillUsed = $this->db->createCommand(
                'SELECT 1 FROM media.assets WHERE storage_key::text = :key
                 UNION ALL
                 SELECT 1 FROM media.variants WHERE storage_key::text = :key
                 UNION ALL
                 SELECT 1 FROM storage.versions WHERE storage_key::text = :key
                 LIMIT 1',
                [':key' => $k],
            )->queryScalar();
            if ($stillUsed === false || $stillUsed === null) {
                $unreferencedKeys[] = $k;
            }
        }

        return $unreferencedKeys;
    }

    private function uniqueName(string $orgId, string $ownerPersonId, string $excludeId, string $originalName): string
    {
        $info = pathinfo($originalName);
        $name = $info['filename'] ?? 'media';
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

        for ($i = 1; $i <= 50; ++$i) {
            $suffix = $i === 1 ? ' (restored)' : " (restored {$i})";
            $candidate = mb_substr($name . $suffix . $ext, 0, 255);
            $taken = $this->db->createCommand(
                "SELECT 1 FROM media.assets
                 WHERE organization_id = CAST(:org AS uuid)
                   AND owner_person_id = CAST(:owner AS uuid)
                   AND lower(original_name) = lower(:name)
                   AND deleted_at IS NULL
                   AND status <> 'CANCELLED'
                   AND id <> CAST(:id AS uuid)
                 LIMIT 1",
                [':org' => $orgId, ':owner' => $ownerPersonId, ':name' => $candidate, ':id' => $excludeId],
            )->queryScalar();
            if ($taken === false) {
                return $candidate;
            }
        }

        return mb_substr($name . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . $ext, 0, 255);
    }
}
