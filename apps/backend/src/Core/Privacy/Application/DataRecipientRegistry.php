<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class DataRecipientRegistry
{
    /** @var array<string, DataRecipientDefinition> */
    private array $definitions;

    /** @param iterable<DataRecipientDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->code])) {
                throw new InvalidArgumentException('Duplicate recipient code.');
            }
            $indexed[$definition->code] = $definition;
        }
        $this->definitions = $indexed;
    }

    public function get(string $code): ?DataRecipientDefinition { return $this->definitions[$code] ?? null; }
    /** @return list<DataRecipientDefinition> */
    public function all(): array { return array_values($this->definitions); }
}
