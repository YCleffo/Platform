<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use InvalidArgumentException;

final readonly class ProcessingInformationDefinition
{
    /** @param list<string> $methodCodes @param list<string> $sourceCodes
     *  @param list<string> $complianceMethodCodes */
    public function __construct(public string $purposeCode, public array $methodCodes,
        public array $sourceCodes, public string $rightsProcedureReference,
        public array $complianceMethodCodes)
    {
        MachineCode::assert($purposeCode);
        foreach ([...$methodCodes, ...$sourceCodes, ...$complianceMethodCodes] as $code) {
            MachineCode::assert($code);
        }
        if ($methodCodes === [] || $sourceCodes === [] || $complianceMethodCodes === []
            || trim($rightsProcedureReference) === '') {
            throw new InvalidArgumentException('Incomplete processing information definition.');
        }
    }
}
