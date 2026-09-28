<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface InstallationRepository
{
    /** @return array<string, mixed>|null */
    public function find(string $installationId): ?array;
}
