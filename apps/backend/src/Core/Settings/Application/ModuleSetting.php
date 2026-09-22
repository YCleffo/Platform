<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

final readonly class ModuleSetting
{
    public function __construct(
        public string $id,
        public string $moduleId,
        public string $moduleCode,
        public string $key,
        public mixed $value,
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
            'key' => $this->key,
            'value' => $this->value,
            'description' => $this->description,
            'updatedByAccountId' => $this->updatedByAccountId,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
