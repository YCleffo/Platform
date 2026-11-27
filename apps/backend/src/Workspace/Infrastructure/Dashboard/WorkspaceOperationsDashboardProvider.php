<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspaceOperationsDashboardProvider implements DashboardProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'workspace';
    }

    public function moduleCode(): ?string
    {
        return 'workspace';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->hasPermission('workspace.use');
    }

    public function provide(DashboardContext $context): array
    {
        $trashWhere = "organization_id = CAST(:org AS uuid) AND restore_status = 'trashed'";
        $trashParams = [':org' => $context->organizationId];
        if (!$context->isOrgAdmin) {
            $trashWhere .= ' AND (deleted_by_account_id = CAST(:account AS uuid) OR deleted_by_person_id = CAST(:person AS uuid))';
            $trashParams[':account'] = $context->accountId;
            $trashParams[':person'] = $context->personId;
        }
        $trashCount = (int) ($this->db->createCommand("SELECT count(*) FROM workspace.trash_items WHERE {$trashWhere}", $trashParams)->queryScalar() ?: 0);

        $operations = $this->db->createCommand(
            'SELECT id::text, action_key AS "actionKey", entity_type AS "entityType",
                    entity_id::text AS "entityId", mode, status, revision,
                    undo_deadline::text AS "undoDeadline", execute_at::text AS "executeAt",
                    created_at::text AS "createdAt", updated_at::text AS "updatedAt"
             FROM workspace.operations
             WHERE organization_id = CAST(:org AS uuid)
               AND account_id = CAST(:account AS uuid)
             ORDER BY created_at DESC, id DESC
             LIMIT 10',
            [':org' => $context->organizationId, ':account' => $context->accountId],
        )->queryAll();

        $activeOpsCount = 0;
        $recentActivity = [];
        /** @var array<string, mixed> $op */
        foreach ($operations as $op) {
            if (in_array($op['status'], ['pending', 'running', 'undoable'], true)) {
                ++$activeOpsCount;
            }
            $recentActivity[] = [
                'type' => 'operation',
                'id' => (string) $op['id'],
                'title' => (string) $op['actionKey'],
                'status' => (string) $op['status'],
                'module' => 'workspace',
                'occurredAt' => (string) $op['updatedAt'],
                'route' => '/workspace/operations',
            ];
        }

        return [
            'summary' => [
                'trashCount' => $trashCount,
                'activeOperations' => $activeOpsCount,
            ],
            'widgets' => [
                [
                    'id' => 'workspace.operations',
                    'module' => 'workspace',
                    'title' => 'Recent & Active Operations',
                    'data' => [
                        'activeCount' => $activeOpsCount,
                        'items' => $operations,
                    ],
                ],
                [
                    'id' => 'workspace.trash',
                    'module' => 'workspace',
                    'title' => 'Trash',
                    'data' => ['count' => $trashCount],
                ],
            ],
            'quickActions' => [[
                'key' => 'workspace.trash',
                'label' => 'Open Trash',
                'route' => '/workspace/trash',
                'module' => 'workspace',
            ]],
            'recentActivity' => $recentActivity,
        ];
    }
}
