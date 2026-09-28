<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

final readonly class RetentionPolicyDefinition
{
    public function __construct(
        public string $code,
        public string $trigger,
        public ?int $durationDays,
        public string $action,
        public bool $legalHoldAllowed = true,
    ) {
        MachineCode::assert($code);
        MachineCode::assert($trigger);
        if ($durationDays !== null && $durationDays < 0) {
            throw new InvalidArgumentException('Retention duration cannot be negative.');
        }
        if (!in_array($action, ['delete', 'anonymize', 'pseudonymize', 'archive_then_delete', 'custom_handler'], true)) {
            throw new InvalidArgumentException('Unknown retention action.');
        }
    }
}
