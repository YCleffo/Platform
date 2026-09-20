<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class PersonView
{
    public function __construct(
        public string $id,
        public string $status,
        public string $givenName,
        public string $familyName,
        public ?string $middleName,
        public ?string $displayName,
        public ?string $birthDate,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'givenName' => $this->givenName,
            'familyName' => $this->familyName,
            'middleName' => $this->middleName,
            'displayName' => $this->displayName,
            'birthDate' => $this->birthDate,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'archivedAt' => $this->archivedAt,
        ];
    }
}
