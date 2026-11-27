<?php

declare(strict_types=1);

namespace App\Workspace\Application;

final class DashboardProviderRegistry
{
    /** @var list<DashboardProviderInterface> */
    private array $providers = [];

    /** @param iterable<DashboardProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    /** @return list<DashboardProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
