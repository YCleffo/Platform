<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

final readonly class AccountSettings
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public array $settings,
        public int $schemaVersion,
    ) {}

    /** @return array{settings: array<string, mixed>, schema_version: int} */
    public function toArray(): array
    {
        return ['settings' => $this->settings, 'schema_version' => $this->schemaVersion];
    }
}
