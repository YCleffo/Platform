<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

final readonly class StoredAccountSettings
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $accountId,
        public array $settings,
        public int $schemaVersion,
    ) {}
}
