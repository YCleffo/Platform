<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Shared\Application\HttpError;

final class TrashProviderRegistry
{
    /** @var array<string, TrashProviderInterface> */
    private array $providers = [];

    /** @param iterable<TrashProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->entityType()] = $provider;
        }
    }

    public function get(string $entityType): TrashProviderInterface
    {
        return $this->providers[$entityType]
            ?? throw new HttpError(422, 'unsupported_trash_entity_type');
    }

    /** @return list<string> */
    public function entityTypes(): array
    {
        return array_keys($this->providers);
    }
}
