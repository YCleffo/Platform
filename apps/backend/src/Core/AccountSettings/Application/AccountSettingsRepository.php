<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

interface AccountSettingsRepository
{
    public function find(string $accountId): ?StoredAccountSettings;

    /** Locks the owning account row so lazy creation is race-safe. */
    public function findForUpdate(string $accountId): ?StoredAccountSettings;

    /** @param array<string, mixed> $settings */
    public function save(string $accountId, array $settings, int $schemaVersion): void;
}
