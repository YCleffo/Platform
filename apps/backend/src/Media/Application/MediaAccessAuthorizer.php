<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Domain access control adapter for non-owner media asset reads/playback.
 */
interface MediaAccessAuthorizer
{
    public function canRead(string $organizationId, string $personId, string $assetId): bool;
}
