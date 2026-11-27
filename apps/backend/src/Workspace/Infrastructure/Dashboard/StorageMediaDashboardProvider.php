<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class StorageMediaDashboardProvider implements DashboardProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'storage_media';
    }

    public function moduleCode(): ?string
    {
        return null;
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->isModuleEnabled('storage') || $context->isModuleEnabled('media');
    }

    public function provide(DashboardContext $context): array
    {
        $recentFiles = [];
        $recentMedia = [];
        $filesCount = 0;
        $mediaCount = 0;

        if ($context->isModuleEnabled('storage')) {
            $filesCount = (int) ($this->db->createCommand(
                'SELECT count(*)
                 FROM storage.files f
                 JOIN storage.spaces s ON s.id = f.space_id
                 WHERE s.organization_id = CAST(:org AS uuid)
                   AND (s.subject_person_id = CAST(:person AS uuid) OR s.account_id = CAST(:account AS uuid))
                   AND f.deleted_at IS NULL',
                [':org' => $context->organizationId, ':person' => $context->personId, ':account' => $context->accountId],
            )->queryScalar() ?: 0);

            $recentFiles = $this->db->createCommand(
                'SELECT f.id::text, f.path, v.size_bytes AS "sizeBytes", v.created_at::text AS "updatedAt"
                 FROM storage.files f
                 JOIN storage.spaces s ON s.id = f.space_id
                 LEFT JOIN storage.versions v ON v.id = f.current_version_id
                 WHERE s.organization_id = CAST(:org AS uuid)
                   AND (s.subject_person_id = CAST(:person AS uuid) OR s.account_id = CAST(:account AS uuid))
                   AND f.deleted_at IS NULL
                 ORDER BY f.created_at DESC, f.id DESC
                 LIMIT 5',
                [':org' => $context->organizationId, ':person' => $context->personId, ':account' => $context->accountId],
            )->queryAll();
        }

        $mediaWhere = "organization_id = CAST(:org AS uuid) AND deleted_at IS NULL AND status <> 'CANCELLED'";
        $mediaParams = [':org' => $context->organizationId];
        if (!$context->isOrgAdmin) {
            $mediaWhere .= ' AND owner_person_id = CAST(:person AS uuid)';
            $mediaParams[':person'] = $context->personId;
        }

        $mediaCount = (int) ($this->db->createCommand("SELECT count(*) FROM media.assets WHERE {$mediaWhere}", $mediaParams)->queryScalar() ?: 0);
        $recentMedia = $this->db->createCommand(
            "SELECT id::text, kind, original_name AS \"originalName\", status, size_bytes AS \"sizeBytes\", updated_at::text AS \"updatedAt\"
             FROM media.assets
             WHERE {$mediaWhere}
             ORDER BY updated_at DESC, id DESC
             LIMIT 5",
            $mediaParams,
        )->queryAll();

        $recentActivity = [];
        /** @var array<string, mixed> $f */
        foreach ($recentFiles as $f) {
            $recentActivity[] = [
                'type' => 'storage.file',
                'id' => (string) $f['id'],
                'title' => basename(str_replace('\\', '/', (string) $f['path'])),
                'module' => 'storage',
                'occurredAt' => (string) ($f['updatedAt'] ?? ''),
                'route' => '/files',
            ];
        }
        /** @var array<string, mixed> $m */
        foreach ($recentMedia as $m) {
            $recentActivity[] = [
                'type' => 'media.asset',
                'id' => (string) $m['id'],
                'title' => (string) $m['originalName'],
                'module' => 'media',
                'occurredAt' => (string) ($m['updatedAt'] ?? ''),
                'route' => '/media/' . (string) $m['id'],
            ];
        }

        return [
            'summary' => [
                'storageFilesCount' => $filesCount,
                'mediaAssetsCount' => $mediaCount,
            ],
            'widgets' => [[
                'id' => 'storage.recent_files',
                'module' => 'storage',
                'title' => 'Recent Files & Media',
                'data' => [
                    'files' => $recentFiles,
                    'media' => $recentMedia,
                ],
            ]],
            'recentActivity' => $recentActivity,
        ];
    }
}
