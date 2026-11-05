<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class ProcessingRoleRegistry
{
    /** @var array<string, ProcessingRoleDefinition> */
    private array $definitions;

    /** @param iterable<ProcessingRoleDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->purposeCode])) {
                throw new InvalidArgumentException('Duplicate processing role purpose.');
            }
            $indexed[$definition->purposeCode] = $definition;
        }
        $this->definitions = $indexed;
    }

    public function get(string $purposeCode): ?ProcessingRoleDefinition
    {
        return $this->definitions[$purposeCode] ?? null;
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }
}
