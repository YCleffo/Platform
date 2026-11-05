<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class ProcessingInformationRegistry
{
    /** @var array<string, ProcessingInformationDefinition> */
    private array $definitions;

    /** @param iterable<ProcessingInformationDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->purposeCode])) {
                throw new InvalidArgumentException('Duplicate processing information purpose.');
            }
            $indexed[$definition->purposeCode] = $definition;
        }
        $this->definitions = $indexed;
    }

    public function get(string $purposeCode): ?ProcessingInformationDefinition
    {
        return $this->definitions[$purposeCode] ?? null;
    }

    /** @param list<string> $purposeCodes */
    public function coversAll(array $purposeCodes): bool
    {
        foreach ($purposeCodes as $code) {
            if (!isset($this->definitions[$code])) {
                return false;
            }
        }
        return $purposeCodes !== [];
    }
}
