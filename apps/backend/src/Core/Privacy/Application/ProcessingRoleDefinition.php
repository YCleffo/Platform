<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use InvalidArgumentException;

final readonly class ProcessingRoleDefinition
{
    /**
     * @param list<string> $dataCategories
     * @param list<string> $permittedOperations
     */
    public function __construct(public string $purposeCode, public string $platformRole,
        public string $operatorReference, public ?string $instructionReference,
        public array $dataCategories, public array $permittedOperations,
        public bool $securityRequirementsConfirmed,
        public bool $incidentNotificationObligation,
        public string $deletionOrReturnObligation)
    {
        MachineCode::assert($purposeCode);
        MachineCode::assert($deletionOrReturnObligation);
        foreach ([...$dataCategories, ...$permittedOperations] as $code) { MachineCode::assert($code); }
        if (!in_array($platformRole, ['operator', 'processor_by_instruction'], true)
            || trim($operatorReference) === '' || $dataCategories === []
            || $permittedOperations === []
            || ($platformRole === 'processor_by_instruction'
                && trim((string) $instructionReference) === '')) {
            throw new InvalidArgumentException('Invalid processing role definition.');
        }
    }
}
