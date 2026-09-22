<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class ExternalIdentity
{
    /** @param array<array-key, mixed> $claims */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $providerId,
        public string $providerCode,
        public string $providerSubject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $displayName,
        public ?string $avatarUrl,
        public array $claims,
        public string $linkedAt,
        public ?string $lastLoginAt,
    ) {}

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'provider' => $this->providerCode,
            'email' => $this->email,
            'emailVerified' => $this->emailVerified,
            'displayName' => $this->displayName,
            'avatarUrl' => $this->avatarUrl,
            'linkedAt' => $this->linkedAt,
            'lastLoginAt' => $this->lastLoginAt,
        ];
    }
}
