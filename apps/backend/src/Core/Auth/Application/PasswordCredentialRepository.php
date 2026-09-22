<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface PasswordCredentialRepository
{
    public function findByAccountId(string $accountId): ?PasswordCredential;

    public function saveHash(
        string $accountId,
        string $passwordHash,
        string $algorithm,
        bool $mustChangePassword = false,
    ): void;
}
