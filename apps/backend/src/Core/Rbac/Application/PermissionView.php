<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class PermissionView
{
    public function __construct(
        public string $id,
        public ?string $moduleId,
        public string $code,
        public string $name,
        public ?string $description,
        public bool $isSystem,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'moduleId' => $this->moduleId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'isSystem' => $this->isSystem,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
