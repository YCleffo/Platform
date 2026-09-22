<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class LoginCommand
{
    public function __construct(
        public string $identifierType,
        public string $identifier,
        public string $password,
    ) {}
}
