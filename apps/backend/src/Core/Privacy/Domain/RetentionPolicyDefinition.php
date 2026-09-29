<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

final readonly class RetentionPolicyDefinition
{
    public function __construct(
        public string $code,
        public string $trigger,
        public ?int $duration,
        public string $action,
        public bool $legalHoldAllowed = true,
        public string $durationUnit = 'calendar_days',
    ) {
        MachineCode::assert($code);
        MachineCode::assert($trigger);
        if ($duration !== null && $duration < 0) {
            throw new InvalidArgumentException('Retention duration cannot be negative.');
        }
        if (!in_array($durationUnit, ['calendar_days', 'working_days', 'hours', 'event_only'], true)
            || ($durationUnit === 'event_only' && $duration !== null)) {
            throw new InvalidArgumentException('Invalid retention duration unit.');
        }
        if (!in_array($action, ['delete', 'anonymize', 'pseudonymize', 'archive_then_delete', 'custom_handler'], true)) {
            throw new InvalidArgumentException('Unknown retention action.');
        }
    }
}
