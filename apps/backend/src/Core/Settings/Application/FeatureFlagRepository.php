<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

interface FeatureFlagRepository
{
    /** @return list<FeatureFlag> */
    public function findAll(): array;

    public function findByCode(string $code): ?FeatureFlag;

    /** @param array<array-key, mixed> $config */
    public function upsert(
        ?string $moduleId,
        string $code,
        bool $enabled,
        int $rolloutPercent,
        array $config,
        ?string $description,
        string $updatedByAccountId,
    ): FeatureFlag;

    public function delete(string $code): ?FeatureFlag;
}
