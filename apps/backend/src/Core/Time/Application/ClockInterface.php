<?php

declare(strict_types=1);

namespace App\Core\Time\Application;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
