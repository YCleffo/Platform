<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

final readonly class OrganizationMembership
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $personId,
        public string $status,
        public string $joinedAt,
        public ?string $leftAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'organizationId' => $this->organizationId,
            'personId' => $this->personId, 'status' => $this->status,
            'joinedAt' => $this->joinedAt, 'leftAt' => $this->leftAt,
            'createdAt' => $this->createdAt, 'updatedAt' => $this->updatedAt];
    }
}
