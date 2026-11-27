<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use App\Workspace\Application\TrashProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ActivityItemTrashProvider implements TrashProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function entityType(): string
    {
        return 'activity.definition';
    }

    public function moduleCode(): string
    {
        return 'activity';
    }

    public function trash(AuthenticatedIdentity $identity, string $organizationId, string $entityId): array
    {
        $personId = $identity->personId ?? '';
        $row = $this->db->createCommand(
            'SELECT id::text, plugin_code AS "pluginCode", author_person_id::text AS "authorPersonId",
                    author_account_id::text AS "authorAccountId", title, description, status, visibility,
                    deleted_at::text AS "deletedAt"
             FROM activity.items
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'not_found');
        }
        if (!$this->access->isOrgAdmin($identity)
            && strcasecmp((string) $row['authorPersonId'], $personId) !== 0
            && strcasecmp((string) ($row['authorAccountId'] ?? ''), $identity->accountId) !== 0) {
            throw new HttpError(403, 'access_denied');
        }
        if ($row['deletedAt'] !== null) {
            throw new HttpError(409, 'already_trashed');
        }

        if ($row['status'] === 'PUBLISHED') {
            $activeAssignments = $this->db->createCommand(
                "SELECT 1 FROM activity.assignments
                 WHERE activity_id = CAST(:id AS uuid) AND status = 'ACTIVE' AND revoked_at IS NULL
                 LIMIT 1",
                [':id' => $entityId],
            )->queryScalar();
            if ($activeAssignments !== false) {
                throw new HttpError(409, 'activity_active_cannot_trash');
            }
        }

        $this->db->createCommand(
            'UPDATE activity.items SET deleted_at = now(), updated_at = now() WHERE id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        return [
            'title' => (string) $row['title'],
            'subtitle' => sprintf('%s · %s', (string) $row['pluginCode'], (string) $row['status']),
            'metadata' => [
                'pluginCode' => (string) $row['pluginCode'],
                'status' => (string) $row['status'],
                'visibility' => (string) $row['visibility'],
            ],
            'payload' => [
                'title' => (string) $row['title'],
                'previousStatus' => (string) $row['status'],
            ],
        ];
    }

    public function restore(string $organizationId, array $trashItem, bool $renameOnConflict): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($trashItem['payload'] ?? null) ? $trashItem['payload'] : [];

        $row = $this->db->createCommand(
            'SELECT id::text, title FROM activity.items
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'source_entity_missing');
        }

        $originalTitle = (string) ($payload['title'] ?? $row['title']);
        $conflictId = $this->db->createCommand(
            "SELECT id::text FROM activity.items
             WHERE organization_id = CAST(:org AS uuid)
               AND lower(title) = lower(:title)
               AND deleted_at IS NULL
               AND status <> 'ARCHIVED'
               AND id <> CAST(:id AS uuid)
             LIMIT 1",
            [':org' => $organizationId, ':title' => $originalTitle, ':id' => $entityId],
        )->queryScalar();

        $targetTitle = $originalTitle;
        $renamed = false;

        if (is_string($conflictId) && $conflictId !== '') {
            $suggested = $this->uniqueTitle($organizationId, $entityId, $originalTitle);
            if (!$renameOnConflict) {
                throw new HttpError(409, 'restore_conflict', 'Activity title conflict on restore.', [
                    'conflictField' => 'title',
                    'existingId' => $conflictId,
                    'suggestedName' => $suggested,
                ]);
            }
            $targetTitle = $suggested;
            $renamed = true;
        }

        $this->db->createCommand(
            'UPDATE activity.items SET title = :title, deleted_at = NULL, updated_at = now() WHERE id = CAST(:id AS uuid)',
            [':title' => $targetTitle, ':id' => $entityId],
        )->execute();

        return [
            'entityId' => $entityId,
            'restoredTitle' => $targetTitle,
            'renamed' => $renamed,
        ];
    }

    public function purge(string $organizationId, array $trashItem): array
    {
        $entityId = (string) ($trashItem['entityId'] ?? '');
        $this->db->createCommand(
            'DELETE FROM activity.items WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $entityId, ':org' => $organizationId],
        )->execute();

        return [];
    }

    private function uniqueTitle(string $orgId, string $excludeId, string $originalTitle): string
    {
        for ($i = 1; $i <= 50; ++$i) {
            $suffix = $i === 1 ? ' (restored)' : " (restored {$i})";
            $candidate = mb_substr($originalTitle . $suffix, 0, 200);
            $taken = $this->db->createCommand(
                "SELECT 1 FROM activity.items
                 WHERE organization_id = CAST(:org AS uuid)
                   AND lower(title) = lower(:title)
                   AND deleted_at IS NULL
                   AND status <> 'ARCHIVED'
                   AND id <> CAST(:id AS uuid)
                 LIMIT 1",
                [':org' => $orgId, ':title' => $candidate, ':id' => $excludeId],
            )->queryScalar();
            if ($taken === false) {
                return $candidate;
            }
        }

        return mb_substr($originalTitle . '-' . substr(bin2hex(random_bytes(4)), 0, 6), 0, 200);
    }
}
