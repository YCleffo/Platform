<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class UpdatePersonCommand
{
    public function __construct(
        public string $id,
        public bool $givenNameProvided,
        public ?string $givenName,
        public bool $familyNameProvided,
        public ?string $familyName,
        public bool $middleNameProvided,
        public ?string $middleName,
        public bool $displayNameProvided,
        public ?string $displayName,
        public bool $birthDateProvided,
        public ?string $birthDate,
    ) {}
}
