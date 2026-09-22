<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class CreateAuthChallengeCommand
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public ?string $accountId,
        public string $challengeType,
        public string $secretHashHex,
        public ?string $targetNormalized,
        public array $payload,
        public int $maxAttempts,
        public string $expiresAt,
    ) {}
}
