<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

interface ModuleSettingsRepository
{
    /** @return list<ModuleSetting> */
    public function findAll(string $moduleId): array;

    public function find(string $moduleId, string $key): ?ModuleSetting;

    public function upsert(
        string $moduleId,
        string $key,
        mixed $value,
        ?string $description,
        string $updatedByAccountId,
    ): ModuleSetting;

    public function delete(string $moduleId, string $key): ?ModuleSetting;
}
