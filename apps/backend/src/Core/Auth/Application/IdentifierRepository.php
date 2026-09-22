<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface IdentifierRepository
{
    public function findByNormalized(string $type, string $normalizedValue): ?AuthIdentifier;

    public function findRecoveryEmailByAccount(string $accountId): ?AuthIdentifier;
}
