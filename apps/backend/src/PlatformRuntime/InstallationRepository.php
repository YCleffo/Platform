<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface InstallationRepository
{
    public function localId(): string;

    /** @return array<string, mixed>|null */
    public function find(string $installationId): ?array;
}
