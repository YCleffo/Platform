<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use App\Workspace\Application\TrashProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class EducationHomeworkTrashProvider implements TrashProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function entityType(): string
    {
        return 'education.homework';
    }

    public function moduleCode(): string
    {
        return 'education';
    }

    public function trash(AuthenticatedIdentity $identity, string $organizationId, string $entityId): array
    {
        if (!$this->access->isModuleEnabled('education') || !$this->access->tableExists('education.homework_assignments')) {
            throw new HttpError(404, 'module_unavailable');
        }
        $personId = $identity->personId ?? '';

        $row = $this->db->createCommand(
            'SELECT id::text, author_account_id::text AS "authorAccountId", author_person_id::text AS "authorPersonId",
                    title, type, status, deleted_at::text AS "deletedAt"
             FROM education.homework_assignments
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'not_found');
        }
        if (!$this->access->isOrgAdmin($identity)
            && strcasecmp((string) $row['authorPersonId'], $personId) !== 0
            && strcasecmp((string) $row['authorAccountId'], $identity->accountId) !== 0) {
            throw new HttpError(403, 'access_denied');
        }
        if ($row['deletedAt'] !== null) {
            throw new HttpError(409, 'already_trashed');
        }
        if (!in_array((string) $row['status'], ['DRAFT', 'ARCHIVED'], true)) {
            throw new HttpError(409, 'homework_active_cannot_trash');
        }

        $this->db->createCommand(
            'UPDATE education.homework_assignments SET deleted_at = now() WHERE id = CAST(:id AS uuid)',
            [':id' => $entityId],
        )->execute();

        return [
            'title' => (string) $row['title'],
            'subtitle' => sprintf('Homework · %s (%s)', (string) $row['type'], (string) $row['status']),
            'metadata' => ['type' => (string) $row['type'], 'status' => (string) $row['status']],
            'payload' => ['title' => (string) $row['title']],
        ];
    }

    public function restore(string $organizationId, array $trashItem, bool $renameOnConflict): array
    {
        if (!$this->access->tableExists('education.homework_assignments')) {
            throw new HttpError(404, 'module_unavailable');
        }
        $entityId = (string) ($trashItem['entityId'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($trashItem['payload'] ?? null) ? $trashItem['payload'] : [];

        $row = $this->db->createCommand(
            'SELECT id::text, title FROM education.homework_assignments
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $entityId, ':org' => $organizationId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'source_entity_missing');
        }

        $originalTitle = (string) ($payload['title'] ?? $row['title']);
        $conflictId = $this->db->createCommand(
            "SELECT id::text FROM education.homework_assignments
             WHERE organization_id = CAST(:org AS uuid)
               AND lower(title) = lower(:title)
               AND deleted_at IS NULL
               AND archived_at IS NULL
               AND id <> CAST(:id AS uuid)
             LIMIT 1",
            [':org' => $organizationId, ':title' => $originalTitle, ':id' => $entityId],
        )->queryScalar();

        $targetTitle = $originalTitle;
        $renamed = false;

        if (is_string($conflictId) && $conflictId !== '') {
            $suggested = mb_substr($originalTitle . ' (restored)', 0, 160);
            if (!$renameOnConflict) {
                throw new HttpError(409, 'restore_conflict', 'Homework title conflict on restore.', [
                    'conflictField' => 'title',
                    'existingId' => $conflictId,
                    'suggestedName' => $suggested,
                ]);
            }
            $targetTitle = $suggested;
            $renamed = true;
        }

        $this->db->createCommand(
            'UPDATE education.homework_assignments SET title = :title, deleted_at = NULL WHERE id = CAST(:id AS uuid)',
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
        if (!$this->access->tableExists('education.homework_assignments')) {
            return [];
        }
        $entityId = (string) ($trashItem['entityId'] ?? '');
        $hasRevisionsOrRecipients = $this->db->createCommand(
            'SELECT 1 FROM education.homework_recipients WHERE assignment_id = CAST(:id AS uuid)
             UNION ALL
             SELECT 1 FROM education.homework_revisions WHERE assignment_id = CAST(:id AS uuid)
             LIMIT 1',
            [':id' => $entityId],
        )->queryScalar();

        if ($hasRevisionsOrRecipients !== false) {
            // Preserve immutable homework audit/submission history by keeping archived soft-deleted record
            $this->db->createCommand(
                "UPDATE education.homework_assignments
                 SET status = 'ARCHIVED', archived_at = COALESCE(archived_at, now()), deleted_at = COALESCE(deleted_at, now())
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)",
                [':id' => $entityId, ':org' => $organizationId],
            )->execute();
            return [];
        }

        $this->db->createCommand(
            'DELETE FROM education.homework_assignments WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $entityId, ':org' => $organizationId],
        )->execute();

        return [];
    }
}
