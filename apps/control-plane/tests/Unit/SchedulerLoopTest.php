<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Scheduler\ScheduledJob;
use ControlPlane\Infrastructure\Scheduler\SchedulerConfig;
use ControlPlane\Infrastructure\Scheduler\SchedulerLoop;
use ControlPlane\Infrastructure\Scheduler\BackgroundJobStatusRecorder;
use ControlPlane\Infrastructure\Scheduler\JobSkipped;
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

    public function testRecorderTracksHeartbeatSuccessFailureAndExpectedSkip(): void
    {
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(true);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $loop = new SchedulerLoop($db, [
            new ScheduledJob('healthy', 10, true, 1, static fn(): int => 2),
            new ScheduledJob('broken', 10, true, 1, static fn(): int => throw new \RuntimeException('private detail')),
            new ScheduledJob('expected_skip', 10, true, 1,
                static fn(): int => throw new JobSkipped('policy_missing')),
            new ScheduledJob('disabled_job', 10, false, 1, static fn(): int => 0),
        ], static fn(string $line) => null, recorder: new BackgroundJobStatusRecorder($db));
        $loop->tick(1000.0);
        $loop->tick(1001.0);
        self::assertCount(8, $writes);
        self::assertSame(['__scheduler__'], $writes[0][1]);
        self::assertSame(['healthy', 2], [$writes[2][1][0], $writes[2][1][2]]);
        self::assertSame(['broken', 'job_failed'], $writes[4][1]);
        self::assertSame(['expected_skip', 'skipped'], $writes[6][1]);
        self::assertSame(['disabled_job', 'disabled'], $writes[7][1]);
        self::assertStringNotContainsString('private detail', json_encode($writes, JSON_THROW_ON_ERROR));
    }
}
