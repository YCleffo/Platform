<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\DataCategoryDefinition;
use InvalidArgumentException;

final class DataCategoryRegistry
{
    /** @var array<string, DataCategoryDefinition> */
    private array $definitions = [];

    /** @param list<DataCategoryDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach (['identity', 'contact', 'authentication', 'security', 'device', 'network', 'approximate_location', 'preferences'] as $code) {
            $this->add(new DataCategoryDefinition($code));
        }
        foreach ($definitions as $definition) {
            $this->add($definition);
        }
    }

    private function add(DataCategoryDefinition $definition): void
    {
        if (isset($this->definitions[$definition->code])) {
            throw new InvalidArgumentException('Duplicate data category definition.');
        }
        $this->definitions[$definition->code] = $definition;
    }

    public function get(string $code): ?DataCategoryDefinition
    {
        return $this->definitions[$code] ?? null;
    }
}
