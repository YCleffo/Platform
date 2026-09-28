<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use InvalidArgumentException;

final class ProcessingPurposeRegistry
{
    /** @var array<string, ProcessingPurposeDefinition> */
    private array $definitions = [];

    /** @param list<ProcessingPurposeDefinition> $definitions */
    public function __construct(
        array $definitions,
        DataCategoryRegistry $categories,
        RetentionPolicyRegistry $retention,
    ) {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->code])) {
                throw new InvalidArgumentException('Duplicate processing purpose definition.');
            }
            if ($retention->get($definition->retentionPolicyCode) === null) {
                throw new InvalidArgumentException('Processing purpose refers to unknown retention policy.');
            }
            foreach ($definition->dataCategories as $category) {
                if ($categories->get($category) === null) {
                    throw new InvalidArgumentException('Processing purpose refers to unknown data category.');
                }
            }
            $this->definitions[$definition->code] = $definition;
        }
    }

    public function get(string $code): ?ProcessingPurposeDefinition
    {
        return $this->definitions[$code] ?? null;
    }
}
