<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use InvalidArgumentException;

final readonly class CrossBorderTransferRegistry
{
    /** @var list<CrossBorderTransferDefinition> */
    private array $definitions;

    /** @param iterable<CrossBorderTransferDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            $key = $definition->providerCode . ':' . $definition->country . ':' . $definition->purposeCode;
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Duplicate cross-border transfer definition.');
            }
            $indexed[$key] = $definition;
        }
        $this->definitions = array_values($indexed);
    }

    public function find(
        string $providerCode,
        string $country,
        string $purposeCode,
    ): ?CrossBorderTransferDefinition {
        foreach ($this->definitions as $definition) {
            if ($definition->providerCode === $providerCode && $definition->country === $country
                && $definition->purposeCode === $purposeCode) {
                return $definition;
            }
        }
        return null;
    }

    public function all(): array
    {
        return $this->definitions;
    }
}
