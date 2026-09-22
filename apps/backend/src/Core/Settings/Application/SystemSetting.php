<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

final readonly class SystemSetting
{
    public function __construct(
        public string $id,
        public string $key,
        public mixed $value,
        public bool $isPublic,
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
            'key' => $this->key,
            'value' => $this->value,
            'isPublic' => $this->isPublic,
            'description' => $this->description,
            'updatedByAccountId' => $this->updatedByAccountId,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
