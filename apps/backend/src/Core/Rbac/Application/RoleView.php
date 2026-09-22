<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class RoleView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public bool $isSystem,
        public bool $isProtected,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'isSystem' => $this->isSystem,
            'isProtected' => $this->isProtected,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
