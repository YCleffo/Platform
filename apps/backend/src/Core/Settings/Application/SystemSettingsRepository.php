<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

interface SystemSettingsRepository
{
    /** @return list<SystemSetting> */
    public function findAll(): array;

    public function findByKey(string $key): ?SystemSetting;

    /** @return list<SystemSetting> */
    public function findPublic(): array;

    public function upsert(
        string $key,
        mixed $value,
        bool $isPublic,
        ?string $description,
        string $updatedByAccountId,
    ): SystemSetting;

    public function delete(string $key): ?SystemSetting;
}
