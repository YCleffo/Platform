<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface AuthProviderRepository
{
    public function findByCode(string $code): ?AuthProvider;

    /** @return list<AuthProvider> */
    public function findEnabled(): array;
}
