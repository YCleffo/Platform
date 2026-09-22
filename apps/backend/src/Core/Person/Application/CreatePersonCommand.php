<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class CreatePersonCommand
{
    public function __construct(
        public string $givenName,
        public string $familyName,
        public ?string $middleName,
        public ?string $displayName,
        public ?string $birthDate,
    ) {}
}
