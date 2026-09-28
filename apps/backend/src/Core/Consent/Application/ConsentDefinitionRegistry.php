<?php

declare(strict_types=1);

namespace App\Core\Consent\Application;

use InvalidArgumentException;

final class ConsentDefinitionRegistry
{
    /** @var array<string, ConsentDefinition> */
    private array $definitions = [];

    /** @param list<ConsentDefinition> $definitions Registered by Core or installed modules. */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->code])) {
                throw new InvalidArgumentException('Duplicate consent definition.');
            }
            $this->definitions[$definition->code] = $definition;
        }
        ksort($this->definitions);
    }

    public function get(string $code): ?ConsentDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    /** @return list<ConsentDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
