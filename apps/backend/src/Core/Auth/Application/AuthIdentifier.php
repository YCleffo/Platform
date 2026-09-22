<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthIdentifier
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $identifierType,
        public string $value,
        public string $normalizedValue,
        public bool $isPrimary,
        public bool $canLogin,
        public bool $canRecover,
        public ?string $verifiedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
