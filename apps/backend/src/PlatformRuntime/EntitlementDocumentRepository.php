<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface EntitlementDocumentRepository
{
    /** @return array<string, mixed>|null */
    public function latestForInstallation(string $installationId): ?array;
}
