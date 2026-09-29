<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name,
            'status' => $this->status, 'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt, 'archivedAt' => $this->archivedAt];
    }
}
