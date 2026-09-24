<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

final class RateLimitExceeded extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Rate limit exceeded.');
    }
}
