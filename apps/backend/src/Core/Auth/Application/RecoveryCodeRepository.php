<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface RecoveryCodeRepository
{
    public function findUnusedByHashHex(string $codeHashHex): ?RecoveryCode;

    /** @param list<string> $codeHashHexes */
    public function store(string $accountId, array $codeHashHexes): void;

    public function consumeCode(string $accountId, string $codeHashHex): ?RecoveryCode;

    public function revokeAllCodes(string $accountId): int;

    public function countAvailable(string $accountId): int;
}
