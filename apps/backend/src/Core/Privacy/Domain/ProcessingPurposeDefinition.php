<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

final readonly class ProcessingPurposeDefinition
{
    /**
     * @param list<string> $dataCategories
     * @param list<string> $subjectCategories
     */
    public function __construct(
        public string $code,
        public string $legalBasisCode,
        public bool $requiresConsent,
        public bool $representativeAllowed,
        public array $dataCategories,
        public array $subjectCategories,
        public string $retentionPolicyCode,
        public string $processingRole,
        public bool $distributionAllowed = false,
        public string $crossBorderPolicy = 'none',
        public string $description = '',
    ) {
        MachineCode::assert($code);
        MachineCode::assert($legalBasisCode);
        MachineCode::assert($retentionPolicyCode);
        MachineCode::assert($processingRole);
        foreach ([...$dataCategories, ...$subjectCategories] as $category) {
            MachineCode::assert($category);
        }
        if ($dataCategories === [] || $subjectCategories === []
            || count($dataCategories) !== count(array_unique($dataCategories))
            || count($subjectCategories) !== count(array_unique($subjectCategories))
            || !in_array($crossBorderPolicy, ['none', 'possible', 'explicitly_allowed'], true)
            || ($requiresConsent !== ($legalBasisCode === 'consent'))
            || !in_array($processingRole, ['operator', 'processor_by_instruction', 'joint_or_external_policy'], true)) {
            throw new InvalidArgumentException('Contradictory processing purpose definition.');
        }
    }
}
