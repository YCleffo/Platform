<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class DistributionConsentDefinitionRegistry
{
    /** @var array<string, DistributionConsentDefinition> */
    private array $byPurpose;

    /** @param iterable<DistributionConsentDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->purposeCode])) {
                throw new InvalidArgumentException('Duplicate distribution consent purpose.');
            }
            $indexed[$definition->purposeCode] = $definition;
        }
        $this->byPurpose = $indexed;
    }

    public function get(string $purposeCode): ?DistributionConsentDefinition
    {
        return $this->byPurpose[$purposeCode] ?? null;
    }

    /** @return list<DistributionConsentDefinition> */
    public function all(): array
    {
        return array_values($this->byPurpose);
    }
}
