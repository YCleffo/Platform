<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

interface AccountPreferencesRepository
{
    public function find(string $accountId): ?AccountInternationalPreferences;

    public function save(
        string $accountId,
        ?string $locale,
        ?string $timezone,
    ): ?AccountInternationalPreferences;
}
