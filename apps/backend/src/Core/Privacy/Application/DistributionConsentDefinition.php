<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use InvalidArgumentException;

final readonly class DistributionConsentDefinition
{
    /**
     * @param list<string> $allowedCategories
     * @param list<string> $allowedFields
     * @param list<string> $allowedConditions
     * @param list<string> $allowedRestrictions
     */
    public function __construct(
        public string $purposeCode,
        public string $documentVersion,
        public string $documentSha256,
        public array $allowedCategories,
        public array $allowedFields,
        public array $allowedConditions = [],
        public array $allowedRestrictions = [],
    ) {
        MachineCode::assert($purposeCode);
        foreach ([...$allowedCategories, ...$allowedFields, ...$allowedConditions, ...$allowedRestrictions] as $code) {
            MachineCode::assert($code);
        }
        if ($documentVersion === '' || strlen($documentVersion) > 80
            || preg_match('/^[a-f0-9]{64}$/D', $documentSha256) !== 1
            || $allowedCategories === [] || $allowedFields === []) {
            throw new InvalidArgumentException('Invalid distribution consent definition.');
        }
        foreach ([$allowedCategories, $allowedFields, $allowedConditions, $allowedRestrictions] as $codes) {
            if (count($codes) !== count(array_unique($codes))) {
                throw new InvalidArgumentException('Duplicate distribution consent definition code.');
            }
        }
    }
}
