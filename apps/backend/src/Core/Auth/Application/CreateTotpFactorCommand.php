<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class CreateTotpFactorCommand
{
    public function __construct(
        public string $accountId,
        public ?string $label,
        public string $secretEncryptedHex,
        public string $algorithm,
        public int $digits,
        public int $periodSeconds,
        public int $secretKeyVersion,
        public string $setupExpiresAt,
    ) {}
}
