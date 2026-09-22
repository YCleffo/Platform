<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Encrypted secret must not be passed to public presenters. */
final readonly class TotpFactor
{
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $label,
        public string $secretEncryptedHex,
        public string $algorithm,
        public int $digits,
        public int $periodSeconds,
        public ?string $verifiedAt,
        public string $createdAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
        public ?int $lastUsedCounter,
        public int $secretKeyVersion,
        public ?string $setupExpiresAt,
    ) {}
}
