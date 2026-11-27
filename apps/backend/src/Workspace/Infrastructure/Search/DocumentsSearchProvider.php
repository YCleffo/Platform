<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Media\Application\MediaAccessAuthorizer;
use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DocumentsSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private ?MediaAccessAuthorizer $mediaAuthorizer = null,
    ) {}

    public function categories(): array
    {
        return ['documents'];
    }

    public function moduleCode(): ?string
    {
        return null;
    }

    public function isAvailable(SearchQuery $query): bool
    {
        if ($query->category !== null && $query->category !== 'documents') {
            return false;
        }
        return true;
    }

    public function search(SearchQuery $query): array
    {
        $orgsArray = SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds);
        $items = [];
        $total = 0;

        // 1. Storage files (if module enabled)
        if ($query->isModuleEnabled('storage')) {
            $matchExpr = SearchSqlHelper::matchExpr('f.path');
            $scoreExpr = SearchSqlHelper::scoreExpr('f.path');
            $acl = $query->isSuperadmin
                ? 'true'
                : '(s.subject_person_id = CAST(:person AS uuid) OR s.account_id = CAST(:account AS uuid))';

            $params = [
                ':orgs' => $orgsArray,
                ':q' => $query->query,
                ':likeQ' => $query->likePattern,
            ];
            if (!$query->isSuperadmin) {
                $params[':person'] = $query->personId;
                $params[':account'] = $query->accountId;
            }

            $total += (int) ($this->db->createCommand(
                "SELECT count(*)
                 FROM storage.files f
                 JOIN storage.spaces s ON s.id = f.space_id
                 WHERE s.organization_id = ANY(CAST(:orgs AS uuid[]))
                   AND f.deleted_at IS NULL
                   AND {$acl}
                   AND {$matchExpr}",
                $params,
            )->queryScalar() ?: 0);

            $fileRows = $this->db->createCommand(
                "SELECT f.id::text, s.organization_id::text AS \"organizationId\", f.path,
                        v.size_bytes AS \"sizeBytes\", COALESCE(v.created_at, f.created_at)::text AS \"updatedAt\",
                        {$scoreExpr} AS score
                 FROM storage.files f
                 JOIN storage.spaces s ON s.id = f.space_id
                 LEFT JOIN storage.versions v ON v.id = f.current_version_id
                 WHERE s.organization_id = ANY(CAST(:orgs AS uuid[]))
                   AND f.deleted_at IS NULL
                   AND {$acl}
                   AND {$matchExpr}
                 ORDER BY score DESC, f.created_at DESC
                 LIMIT :limit",
                [...$params, ':limit' => $query->fetchLimit()],
            )->queryAll();

            /** @var array<string, mixed> $r */
            foreach ($fileRows as $r) {
                $path = (string) $r['path'];
                $items[] = SearchSqlHelper::item(
                    entityType: 'storage.file',
                    entityId: (string) $r['id'],
                    category: 'documents',
                    title: basename(str_replace('\\', '/', $path)),
                    subtitle: $path,
                    snippet: $path,
                    route: '/files?path=' . rawurlencode($path),
                    module: 'storage',
                    organizationId: (string) $r['organizationId'],
                    updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                    score: (float) ($r['score'] ?? 0.0),
                );
            }
        }

        // 2. Media assets (exclude deleted_at IS NOT NULL and CANCELLED)
        $mediaMatch = SearchSqlHelper::matchExpr('a.original_name');
        $mediaScore = SearchSqlHelper::scoreExpr('a.original_name');
        $mediaRows = $this->db->createCommand(
            "SELECT a.id::text, a.organization_id::text AS \"organizationId\",
                    a.owner_person_id::text AS \"ownerPersonId\",
                    a.kind, a.original_name AS \"originalName\", a.original_mime AS \"originalMime\",
                    a.status, a.updated_at::text AS \"updatedAt\",
                    {$mediaScore} AS score
             FROM media.assets a
             WHERE a.organization_id = ANY(CAST(:orgs AS uuid[]))
               AND a.deleted_at IS NULL
               AND a.status <> 'CANCELLED'
               AND {$mediaMatch}
             ORDER BY score DESC, a.updated_at DESC
             LIMIT 200",
            [
                ':orgs' => $orgsArray,
                ':q' => $query->query,
                ':likeQ' => $query->likePattern,
            ],
        )->queryAll();

        /** @var array<string, mixed> $m */
        foreach ($mediaRows as $m) {
            $orgId = (string) $m['organizationId'];
            $isOwner = strcasecmp((string) $m['ownerPersonId'], $query->personId) === 0;
            if (!$isOwner && !$query->isOrgAdmin) {
                if ($this->mediaAuthorizer === null || !$this->mediaAuthorizer->canRead($orgId, $query->personId, (string) $m['id'])) {
                    continue;
                }
            }
            ++$total;
            if (count($items) >= $query->fetchLimit() * 2) {
                continue;
            }
            $items[] = SearchSqlHelper::item(
                entityType: 'media.asset',
                entityId: (string) $m['id'],
                category: 'documents',
                title: (string) $m['originalName'],
                subtitle: sprintf('%s · %s', (string) $m['kind'], (string) $m['originalMime']),
                snippet: (string) $m['originalName'],
                route: '/media/' . (string) $m['id'],
                module: 'media',
                organizationId: $orgId,
                updatedAt: isset($m['updatedAt']) ? (string) $m['updatedAt'] : null,
                score: (float) ($m['score'] ?? 0.0),
            );
        }

        return ['items' => $items, 'totalsByCategory' => ['documents' => $total]];
    }
}
