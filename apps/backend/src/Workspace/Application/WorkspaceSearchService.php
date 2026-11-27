<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Realtime\Application\RealtimeService;
use App\Workspace\Domain\WorkspaceAccess;
use App\Workspace\Infrastructure\Search\SearchSqlHelper;

final readonly class WorkspaceSearchService
{
    public function __construct(
        private WorkspaceAccess $access,
        private SearchProviderRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $queryParams
     * @return array{
     *     query: string,
     *     scope: string,
     *     category: ?string,
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     offset: int,
     *     facets: array<string, int>
     * }
     */
    public function search(AuthenticatedIdentity $identity, string $organizationId, array $queryParams): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rawQParam = $queryParams['q'] ?? null;
        $rawQ = is_string($rawQParam) ? trim($rawQParam) : '';
        $rawCatParam = $queryParams['category'] ?? null;
        $rawCategory = is_string($rawCatParam) && trim($rawCatParam) !== '' && trim($rawCatParam) !== 'all'
            ? trim($rawCatParam)
            : null;
        $categoryMap = [
            'users' => 'people',
            'orgs' => 'organizations',
            'tests' => 'activities',
            'files' => 'documents',
            'students' => 'education',
            'teachers' => 'education',
            'groups' => 'education',
            'lessons' => 'education',
            'homework' => 'education',
        ];
        $providerCategory = $rawCategory !== null ? ($categoryMap[$rawCategory] ?? $rawCategory) : null;
        $rawScope = $queryParams['scope'] ?? 'organization';
        $scope = in_array($rawScope, ['global', 'all'], true) ? 'global' : 'organization';
        $limit = max(1, min(50, isset($queryParams['limit']) && is_numeric($queryParams['limit']) ? (int) $queryParams['limit'] : 20));
        $offset = max(0, isset($queryParams['offset']) && is_numeric($queryParams['offset']) ? (int) $queryParams['offset'] : 0);

        if ($rawQ === '') {
            return [
                'query' => '',
                'scope' => $scope,
                'category' => $rawCategory,
                'items' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'facets' => [],
            ];
        }

        $qText = mb_substr($rawQ, 0, 200);
        $permittedOrgIds = $scope === 'global'
            ? $this->access->permittedOrganizationIds($personId)
            : [$orgId];
        if (!in_array($orgId, $permittedOrgIds, true)) {
            $permittedOrgIds[] = $orgId;
        }

        $searchQuery = new SearchQuery(
            query: $qText,
            likePattern: SearchSqlHelper::escapeLike($qText),
            category: $providerCategory,
            scope: $scope,
            limit: $limit,
            offset: $offset,
            identity: $identity,
            organizationId: $orgId,
            permittedOrgIds: $permittedOrgIds,
            accountId: $identity->accountId,
            personId: $personId,
            permissions: $this->access->availablePermissionCodes($identity),
            isSuperadmin: $this->access->isSuperadmin($identity),
            isOrgAdmin: $this->access->isOrgAdmin($identity),
            enabledModules: $this->access->enabledModulesMap(),
            locale: $identity->locale ?? 'ru-RU',
        );

        $allItems = [];
        $facets = [];
        $entityFilter = match ($rawCategory) {
            'students' => 'education.student',
            'teachers' => 'education.teacher',
            'groups' => 'education.group',
            'lessons' => 'education.lesson',
            'homework' => 'education.homework',
            'files' => 'storage.file',
            default => null,
        };

        foreach ($this->registry->all() as $provider) {
            $moduleCode = $provider->moduleCode();
            if ($moduleCode !== null && !$searchQuery->isModuleEnabled($moduleCode)) {
                continue;
            }
            if (!$provider->isAvailable($searchQuery)) {
                continue;
            }
            $result = $provider->search($searchQuery);
            foreach ($result['totalsByCategory'] as $cat => $count) {
                $facets[$cat] = ($facets[$cat] ?? 0) + $count;
            }
            foreach ($result['items'] as $item) {
                $itemCat = (string) ($item['category'] ?? '');
                $itemEntity = (string) ($item['entityType'] ?? '');
                if ($entityFilter !== null && $itemEntity !== $entityFilter) {
                    continue;
                }
                if ($providerCategory !== null && $itemCat !== $providerCategory) {
                    continue;
                }
                $allItems[] = $item;
            }
        }

        usort($allItems, static function (array $a, array $b): int {
            $scoreCmp = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            return strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? ''));
        });

        $total = $entityFilter !== null
            ? count($allItems)
            : ($providerCategory !== null
                ? (int) ($facets[$providerCategory] ?? 0)
                : array_sum($facets));

        $pagedItems = array_slice($allItems, $offset, $limit);
        ksort($facets);

        return [
            'query' => $qText,
            'scope' => $scope,
            'category' => $rawCategory,
            'items' => $pagedItems,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'facets' => $facets,
        ];
    }
}
