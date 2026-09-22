<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface CredentialLifecycleRepository
{
    public function findPrimaryEmail(string $accountId): ?CredentialTarget;

    public function findRecoverableEmail(string $normalizedEmail): ?CredentialTarget;

    public function ensurePrimaryEmail(string $accountId, string $email, string $normalizedEmail): ?CredentialTarget;

    public function completeActivation(string $secretHashHex, string $passwordHash, string $algorithm): ?string;

    public function completePasswordReset(string $secretHashHex, string $passwordHash, string $algorithm): ?CredentialTarget;
}
