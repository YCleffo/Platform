<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

final readonly class FeatureFlag
{
    /** @param array<array-key, mixed> $config */
    public function __construct(
        public string $id,
        public ?string $moduleId,
        public ?string $moduleCode,
        public string $code,
        public bool $enabled,
        public int $rolloutPercent,
        public array $config,
        public ?string $description,
        public ?string $updatedByAccountId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'moduleCode' => $this->moduleCode,
            'code' => $this->code,
            'enabled' => $this->enabled,
            'rolloutPercent' => $this->rolloutPercent,
            'config' => $this->config,
            'description' => $this->description,
            'updatedByAccountId' => $this->updatedByAccountId,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
