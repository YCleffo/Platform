<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use InvalidArgumentException;

final class RetentionPolicyRegistry
{
    /** @var array<string, RetentionPolicyDefinition> */
    private array $definitions = [];

    /** @param list<RetentionPolicyDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->code])) {
                throw new InvalidArgumentException('Duplicate retention policy definition.');
            }
            $this->definitions[$definition->code] = $definition;
        }
    }

    public function get(string $code): ?RetentionPolicyDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    /** @return list<RetentionPolicyDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
