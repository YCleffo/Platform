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

    /** Unconditional update — use only for synced/backup-eligible passkeys (counter stays 0). */
    public function recordUsage(string $id, int $signCount, bool $backupState): void;

    /**
     * Compare-and-set update for non-synced/hardware passkeys.
     * Only applies the update when the stored sign_count equals $expectedCounter.
     * Returns true if the row was updated (won the race), false if another concurrent
     * request already incremented the counter (stale update — treat as auth failure).
     */
    public function recordUsageCompareSet(
        string $id,
        int $expectedCounter,
        int $newCounter,
        bool $newBackupState,
    ): bool;

    public function revokePasskey(string $id, string $accountId): bool;

    /**
     * Count active (non-revoked) passkeys for a given account.
     * Intended for use inside a locked transaction (after SELECT FOR UPDATE on account row).
     */
    public function countActiveByAccountId(string $accountId): int;
}
