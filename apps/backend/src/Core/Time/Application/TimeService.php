<?php

declare(strict_types=1);

namespace App\Core\Time\Application;

use DateTimeImmutable;

final readonly class TimeService
{
    public function __construct(
        private ClockInterface $clock,
        private DateTimeFormatter $formatter,
    ) {}

    public function nowUtc(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function nowRfc3339(): string
    {
        return $this->formatter->rfc3339Utc($this->clock->now());
    }
}
