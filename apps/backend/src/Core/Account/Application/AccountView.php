<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public ?string $personId,
        public string $accountType,
        public string $status,
        public ?string $label,
        public string $createdAt,
        public string $updatedAt,
        public ?string $disabledAt,
        public ?string $archivedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'personId' => $this->personId,
            'accountType' => $this->accountType,
            'status' => $this->status,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'disabledAt' => $this->disabledAt,
            'archivedAt' => $this->archivedAt,
        ];
    }
}
