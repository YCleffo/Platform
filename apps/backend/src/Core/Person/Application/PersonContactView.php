<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class PersonContactView
{
    public function __construct(
        public string $id,
        public string $personId,
        public string $contactType,
        public string $value,
        public string $normalizedValue,
        public ?string $label,
        public bool $isPrimary,
        public ?string $verifiedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'personId' => $this->personId,
            'contactType' => $this->contactType,
            'value' => $this->value,
            'normalizedValue' => $this->normalizedValue,
            'label' => $this->label,
            'isPrimary' => $this->isPrimary,
            'verifiedAt' => $this->verifiedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
