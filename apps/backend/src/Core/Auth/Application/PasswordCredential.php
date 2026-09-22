<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Never expose through an ordinary API presenter. */
final readonly class PasswordCredential
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $passwordHash,
        public string $passwordAlgorithm,
        public bool $mustChangePassword,
        public string $passwordChangedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
