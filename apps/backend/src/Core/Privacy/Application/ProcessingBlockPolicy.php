<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

interface ProcessingBlockPolicy
{
    public function isBlocked(string $subjectPersonId, ?string $purposeCode = null, ?string $resourceType = null, ?string $resourceId = null): bool;
}
