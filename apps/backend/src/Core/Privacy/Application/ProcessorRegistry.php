<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class ProcessorRegistry
{
    /** @var array<string, ProcessorDefinition> */
    private array $definitions;

    /** @param iterable<ProcessorDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->code])) {
                throw new InvalidArgumentException('Duplicate processor code.');
            }
            $indexed[$definition->code] = $definition;
        }
        $this->definitions = $indexed;
    }

    public function get(string $code): ?ProcessorDefinition { return $this->definitions[$code] ?? null; }
    public function all(): array { return array_values($this->definitions); }
}
