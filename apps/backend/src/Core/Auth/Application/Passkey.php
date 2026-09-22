<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Credential material must not be passed to public presenters. */
final readonly class Passkey
{
    /** @param list<string> $transports */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $credentialIdHex,
        public string $publicKeyHex,
        public int $signCount,
        public ?string $aaguid,
        public array $transports,
        public ?string $label,
        public string $createdAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
        public bool $backupEligible = false,
        public bool $backupState = false,
    ) {}

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}
