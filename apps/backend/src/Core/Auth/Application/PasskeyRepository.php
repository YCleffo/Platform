<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface PasskeyRepository
{
    public function findById(string $id): ?Passkey;

    public function findByCredentialIdHex(string $credentialIdHex): ?Passkey;

    /** @return list<Passkey> */
    public function findActivePasskeysByAccountId(string $accountId): array;

    /** @return list<Passkey> */
    public function findAllByAccountId(string $accountId): array;

    /** @param list<string> $transports */
    public function create(
        string $accountId,
        string $credentialIdHex,
        string $publicKeyHex,
        int $signCount,
        ?string $aaguid,
        array $transports,
        ?string $label,
        bool $backupEligible,
        bool $backupState,
    ): Passkey;

    public function updateLabel(string $id, string $accountId, ?string $label): ?Passkey;

    public function recordUsage(string $id, int $signCount, bool $backupState): void;

    public function revoke(string $id, string $accountId): bool;
}
