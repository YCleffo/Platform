<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class CreateAccountCommand
{
    public function __construct(
        public string $accountType,
        public ?string $personId,
        public ?string $label,
    ) {}
}
