<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use InvalidArgumentException;

final readonly class ProcessingInformationDefinition
{
    /**
     * @param list<string> $methodCodes
     * @param list<string> $sourceCodes
     * @param list<string> $complianceMethodCodes
     */
    public function __construct(
        public string $purposeCode,
        public array $methodCodes,
        public array $sourceCodes,
        public string $rightsProcedureReference,
        public array $complianceMethodCodes,
        public string $rightsProcedureText = '',
        public array $complianceMethodDescriptions = [],
    ) {
        MachineCode::assert($purposeCode);
        foreach ([...$methodCodes, ...$sourceCodes, ...$complianceMethodCodes] as $code) {
            MachineCode::assert($code);
        }
        if ($methodCodes === [] || $sourceCodes === [] || $complianceMethodCodes === []
            || trim($rightsProcedureReference) === '') {
            throw new InvalidArgumentException('Incomplete processing information definition.');
        }
        foreach ($complianceMethodDescriptions as $code => $description) {
            MachineCode::assert((string) $code);
            if (!is_string($description) || trim($description) === '') {
                throw new InvalidArgumentException('Invalid compliance method description.');
            }
        }
    }
}
