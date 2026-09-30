<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Scheduler;

final readonly class ScheduledJob
{
    public function __construct(
        public string $code,
        public int $intervalSeconds,
        public bool $enabled,
        public int $batchLimit,
        public \Closure $run,
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $code)
            || $intervalSeconds < 1 || $intervalSeconds > 86400
            || $batchLimit < 1 || $batchLimit > 1000) {
            throw new \InvalidArgumentException('Invalid scheduled job configuration.');
        }
    }
}
