<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthCleanupReport
{
    public function __construct(
        public int $expiredChallenges,
        public int $consumedChallenges,
        public int $cancelledChallenges,
        public int $recoveryCodes,
        public int $pendingTotpFactors,
        public int $serviceCredentials = 0,
    ) {}

    public function total(): int
    {
        return $this->expiredChallenges
            + $this->consumedChallenges
            + $this->cancelledChallenges
            + $this->recoveryCodes
            + $this->pendingTotpFactors
            + $this->serviceCredentials;
    }
}
