<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Challenge secrets and targets are never public API data. */
final readonly class AuthChallenge
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public string $id,
        public ?string $accountId,
        public string $challengeType,
        public string $secretHashHex,
        public ?string $targetNormalized,
        public array $payload,
        public int $attempts,
        public int $maxAttempts,
        public string $createdAt,
        public string $expiresAt,
        public ?string $consumedAt,
        public ?string $cancelledAt,
    ) {}
}
