<?php

declare(strict_types=1);

namespace App\Core\Consent\Application;

use InvalidArgumentException;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;

final class ConsentDefinitionRegistry
{
    /** @var array<string, ConsentDefinition> */
    private array $definitions = [];

    /** @param list<ConsentDefinition> $definitions Registered by Core or installed modules. */
    public function __construct(array $definitions, ProcessingPurposeRegistry $purposes)
    {
        foreach ($definitions as $definition) {
            $purpose = $purposes->get($definition->purposeCode);
            if ($purpose === null || !$purpose->requiresConsent
                || $purpose->retentionPolicyCode !== $definition->retentionPolicyCode
                || ($definition->representativeAllowed && !$purpose->representativeAllowed)) {
                throw new InvalidArgumentException('Consent definition has no matching consent purpose.');
            }
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
