<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthProvider
{
    public function __construct(
        public string $id,
        public string $code,
        public string $protocol,
        public string $displayName,
        public bool $enabled,
        public bool $allowLogin,
        public bool $allowRegistration,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
