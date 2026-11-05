<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\PlatformRuntime\Scheduler\ScheduledJob;
use App\PlatformRuntime\Scheduler\SchedulerConfig;
use PHPUnit\Framework\TestCase;

final class SchedulerConfigTest extends TestCase
{
    public function testInvalidIntervalAndLimitFailStartup(): void
    {
        foreach ([0, 86401] as $interval) {
            try {
                new ScheduledJob('test', $interval, true, 1, static fn() => 0);
                self::fail();
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            new ScheduledJob('test', 1, true, 1001, static fn() => 0);
            self::fail();
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        putenv('TEST_SCHEDULER_INTERVAL=0');
        try {
            $this->expectException(\InvalidArgumentException::class);
            SchedulerConfig::integer('TEST_SCHEDULER_INTERVAL', 10);
        } finally {
            putenv('TEST_SCHEDULER_INTERVAL');
        }
    }
}
