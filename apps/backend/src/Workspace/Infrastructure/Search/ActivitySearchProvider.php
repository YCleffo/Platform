<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityPayload;
use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ActivitySearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private ActivityDomainPolicyInterface $domainPolicy,
    ) {}

    public function categories(): array
    {
        return ['activities'];
    }

    public function moduleCode(): ?string
    {
        return 'activity';
    }

    public function isAvailable(SearchQuery $query): bool
    {
        if ($query->category !== null && $query->category !== 'activities') {
            return false;
        }
        return $query->hasPermission('activity.use');
    }

    public function search(SearchQuery $query): array
    {
        $expr = "i.title || ' ' || COALESCE(i.description, '')";
        $matchExpr = SearchSqlHelper::matchExpr($expr);
        $scoreExpr = SearchSqlHelper::scoreExpr($expr);

        $rows = $this->db->createCommand(
            "SELECT i.id::text, i.organization_id::text AS \"organizationId\",
                    i.plugin_code AS \"pluginCode\", i.author_person_id::text AS \"authorPersonId\",
                    i.title, i.description, i.status, i.visibility,
                    i.updated_at::text AS \"updatedAt\",
                    v.config::text AS \"configJson\",
                    {$scoreExpr} AS score
             FROM activity.items i
             LEFT JOIN activity.versions v ON v.id = i.active_version_id
             WHERE i.organization_id = ANY(CAST(:orgs AS uuid[]))
               AND i.deleted_at IS NULL
               AND i.status <> 'ARCHIVED'
               AND {$matchExpr}
             ORDER BY score DESC, i.updated_at DESC
             LIMIT 250",
            [
                ':orgs' => SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds),
                ':q' => $query->query,
                ':likeQ' => $query->likePattern,
            ],
        )->queryAll();

        $items = [];
        $total = 0;

        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $r['config'] = ActivityPayload::decodeObject((string) ($r['configJson'] ?? '{}'));
            if (!$this->domainPolicy->canAccessActivity($query->identity, (string) $r['organizationId'], $r)) {
                continue;
            }
            ++$total;
            if (count($items) >= $query->fetchLimit()) {
                continue;
            }
            $desc = (string) ($r['description'] ?? '');
            $items[] = SearchSqlHelper::item(
                entityType: 'activity.definition',
                entityId: (string) $r['id'],
                category: 'activities',
                title: (string) $r['title'],
                subtitle: sprintf('%s · %s', (string) $r['pluginCode'], (string) $r['status']),
                snippet: SearchSqlHelper::snippet($desc !== '' ? $desc : (string) $r['title'], $query->query),
                route: '/activities/' . (string) $r['id'],
                module: 'activity',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        return ['items' => $items, 'totalsByCategory' => ['activities' => $total]];
    }
}
