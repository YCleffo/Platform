<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Scheduler\ScheduledJob;
use ControlPlane\Infrastructure\Scheduler\SchedulerConfig;
use ControlPlane\Infrastructure\Scheduler\SchedulerLoop;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SchedulerLoopTest extends TestCase
{
    public function testBoundedDueJobsContinueAfterFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(true);
        $calls = [];
        $logs = [];
        $loop = new SchedulerLoop($db, [
            new ScheduledJob('prepare_renewals', 10, true, 7,
                function (int $limit) use (&$calls): int { $calls[] = $limit; throw new \RuntimeException('private detail'); }),
            new ScheduledJob('advance_subscriptions', 10, true, 8,
                function (int $limit) use (&$calls): int { $calls[] = $limit; return 2; }),
            new ScheduledJob('disabled', 10, false, 1, static fn(): int => throw new \RuntimeException()),
        ], static function (string $line) use (&$logs): void { $logs[] = $line; });
        $loop->tick(1000.0);
        $loop->tick(1001.0);
        self::assertSame([7, 8], $calls);
        self::assertSame(['failed', 'ok'], array_map(
            static fn(string $line): string => json_decode($line, true)['status'], $logs));
        self::assertStringNotContainsString('private detail', implode('', $logs));
        $loop->tick(1010.0);
        self::assertSame([7, 8, 7, 8], $calls);
    }

    public function testInvalidIntervalFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScheduledJob('job', 0, true, 1, static fn(): int => 0);
    }
}
