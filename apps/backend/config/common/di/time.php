<?php

declare(strict_types=1);

use App\Core\Time\Application\ClockInterface;
use App\Core\Time\Application\TimeZoneResolver;
use App\Core\Time\Infrastructure\SystemClock;

/** @var array<string, mixed> $params */
$time = $params['time'];

return [
    ClockInterface::class => ['class' => SystemClock::class],
    TimeZoneResolver::class => [
        '__construct()' => [
            'defaultTimezone' => $time['defaultTimezone'],
        ],
    ],
];
