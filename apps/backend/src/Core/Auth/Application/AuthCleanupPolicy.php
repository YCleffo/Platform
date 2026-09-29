<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final readonly class AuthCleanupPolicy
{
    public function __construct(
        public int $challengeRetentionDays,
        public int $recoveryCodeRetentionDays,
        public int $batchSize,
        public int $serviceCredentialRetentionDays = 90,
    ) {
        if ($challengeRetentionDays < 1 || $recoveryCodeRetentionDays < 1 || $serviceCredentialRetentionDays < 1) {
            throw new InvalidArgumentException('Authentication cleanup retention must be at least one day.');
        }
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('Authentication cleanup batch size must be between 1 and 10000.');
        }
    }
}
