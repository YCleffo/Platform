<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

interface RateLimiterInterface
{
    /**
     * Atomically consumes one attempt. Returns false when the limit is exceeded.
     * Implementations must throw when the backing protection is unavailable.
     */
    public function consume(string $scope, string $subjectDigest, int $limit, int $windowSeconds): bool;
}
