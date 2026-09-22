<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class CreatePersonContactCommand
{
    public function __construct(
        public string $personId,
        public string $contactType,
        public string $value,
        public ?string $label,
        public bool $isPrimary,
    ) {}
}
