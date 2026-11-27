<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DeviceSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function categories(): array
    {
        return ['devices'];
    }

    public function moduleCode(): ?string
    {
        return 'device_management';
    }

    public function isAvailable(SearchQuery $query): bool
    {
        if ($query->category !== null && $query->category !== 'devices') {
            return false;
        }
        return $query->hasPermission('education.devices.manage')
            && $this->access->tableExists('device_management.devices');
    }

    public function search(SearchQuery $query): array
    {
        $expr = "d.name || ' ' || d.number";
        $matchExpr = SearchSqlHelper::matchExpr($expr);
        $scoreExpr = SearchSqlHelper::scoreExpr($expr);

        $where = "d.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$matchExpr}";
        $params = [
            ':orgs' => SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds),
            ':q' => $query->query,
            ':likeQ' => $query->likePattern,
        ];
        if (!$query->isOrgAdmin) {
            $where .= ' AND d.owner_account_id = CAST(:account AS uuid)';
            $params[':account'] = $query->accountId;
        }

        $total = (int) ($this->db->createCommand("SELECT count(*) FROM device_management.devices d WHERE {$where}", $params)->queryScalar() ?: 0);

        $rows = $this->db->createCommand(
            "SELECT d.id::text, d.organization_id::text AS \"organizationId\",
                    d.name, d.number, d.status, d.created_at::text AS \"updatedAt\",
                    c.name AS \"classroomName\",
                    {$scoreExpr} AS score
             FROM device_management.devices d
             LEFT JOIN device_management.classrooms c ON c.id = d.classroom_id
             WHERE {$where}
             ORDER BY score DESC, d.created_at DESC
             LIMIT :limit",
            [...$params, ':limit' => $query->fetchLimit()],
        )->queryAll();

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'device_management.device',
                entityId: (string) $r['id'],
                category: 'devices',
                title: (string) $r['name'] !== '' ? (string) $r['name'] : ('Device #' . (string) $r['number']),
                subtitle: sprintf('%s · %s', (string) $r['status'], (string) ($r['classroomName'] ?? 'Unassigned')),
                snippet: trim((string) $r['name'] . ' #' . (string) $r['number']),
                route: '/devices/' . (string) $r['id'],
                module: 'device_management',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        return ['items' => $items, 'totalsByCategory' => ['devices' => $total]];
    }
}
