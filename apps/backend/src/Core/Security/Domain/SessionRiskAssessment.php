<?php

declare(strict_types=1);

namespace App\Core\Security\Domain;

final readonly class SessionRiskAssessment
{
    /** @param list<string> $signals */
    public function __construct(
        public SessionRiskLevel $level,
        public int $score,
        public array $signals,
    ) {}
}
