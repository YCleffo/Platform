<?php

declare(strict_types=1);

namespace App\Workspace\Application;

final class SearchProviderRegistry
{
    /** @var list<SearchProviderInterface> */
    private array $providers = [];

    /** @param iterable<SearchProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    /** @return list<SearchProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
