<?php

declare(strict_types=1);

namespace App\Core\Time\Infrastructure;

use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
