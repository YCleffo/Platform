<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;

final readonly class SearchQuery
{
    /**
     * @param list<string> $permittedOrgIds
     * @param list<string> $permissions
     * @param array<string, bool> $enabledModules
     */
    public function __construct(
        public string $query,
        public string $likePattern,
        public ?string $category,
        public string $scope,
        public int $limit,
        public int $offset,
        public AuthenticatedIdentity $identity,
        public string $organizationId,
        public array $permittedOrgIds,
        public string $accountId,
        public string $personId,
        public array $permissions,
        public bool $isSuperadmin,
        public bool $isOrgAdmin,
        public array $enabledModules,
        public string $locale,
    ) {}

    public function hasPermission(string $code): bool
    {
        return $this->isSuperadmin || in_array($code, $this->permissions, true);
    }

    public function isModuleEnabled(string $code): bool
    {
        return ($this->enabledModules[$code] ?? false) === true;
    }

    public function fetchLimit(): int
    {
        return min(200, $this->offset + $this->limit);
    }
}
