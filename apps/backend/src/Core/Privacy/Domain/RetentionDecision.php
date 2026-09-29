<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RetentionDecision
{
    public const CODES = ['retain', 'not_yet_eligible', 'eligible_delete', 'eligible_anonymize',
        'eligible_pseudonymize', 'eligible_custom', 'blocked_by_legal_hold',
        'blocked_by_active_basis', 'blocked_by_missing_policy', 'blocked_by_unknown_basis',
        'blocked_by_dependency'];

    public function __construct(
        public RetentionCandidate $candidate,
        public string $code,
        public ?DateTimeImmutable $eligibleAt = null,
        public ?string $legalHoldId = null,
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException('Unknown retention decision.');
        }
    }
}
