<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class AccountInternationalPreferences
{
    public function __construct(
        public string $accountId,
        public ?string $locale,
        public ?string $timezone,
    ) {}
}
