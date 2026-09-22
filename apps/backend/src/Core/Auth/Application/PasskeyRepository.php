<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface PasskeyRepository
{
    public function findByCredentialIdHex(string $credentialIdHex): ?Passkey;

    /** @return list<Passkey> */
    public function findActivePasskeysByAccountId(string $accountId): array;
}
