<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\PlatformRuntime\Scheduler\BackgroundJobStatusRecorder;
use App\PlatformRuntime\Scheduler\OperationalStatus;
use App\PlatformRuntime\Scheduler\ScheduledJob;
use App\PlatformRuntime\Scheduler\SchedulerLoop;
use App\PlatformRuntime\Scheduler\JobSkipped;
use App\Console\OpsStatusCommand;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class OperationalStatusTest extends TestCase
{
    public function testReadyRequiresFreshEnabledComponentsAndRedis(): void
    {
        putenv('CORE_SCHEDULER_ENABLED=true');
        putenv('CORE_EMAIL_WORKER_ENABLED=true');
        try {
            $age = 5;
            $schema = $this->createMock(CommandInterface::class);
            $schema->method('queryScalar')->willReturn(true);
            $heartbeat = $this->createMock(CommandInterface::class);
            $heartbeat->method('queryScalar')->willReturnCallback(static function () use (&$age): int { return $age; });
            $db = $this->createMock(ConnectionInterface::class);
            $db->method('createCommand')->willReturnCallback(static function (?string $sql) use ($schema, $heartbeat): CommandInterface {
                return str_contains($sql ?? '', 'to_regclass') ? $schema : $heartbeat;
            });
            $redis = $this->createMock(ClientInterface::class);
            $redis->method('__call')->willReturn('PONG');
            $status = new OperationalStatus($db, $redis);
            self::assertTrue($status->ready());
            $age = 180;
            self::assertFalse($status->ready());
            putenv('CORE_SCHEDULER_ENABLED=false');
            putenv('CORE_EMAIL_WORKER_ENABLED=false');
            self::assertTrue($status->ready());
        } finally {
            putenv('CORE_SCHEDULER_ENABLED');
            putenv('CORE_EMAIL_WORKER_ENABLED');
        }
    }

    public function testDatabaseFailureMakesReadyFalse(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willThrowException(new \RuntimeException('private DB detail'));
        $redis = $this->createMock(ClientInterface::class);
        self::assertFalse((new OperationalStatus($db, $redis))->ready());
    }

    public function testReadyRequiresRedisAndEachWorkerHeartbeat(): void
    {
        putenv('CORE_SCHEDULER_ENABLED=true');
        putenv('CORE_EMAIL_WORKER_ENABLED=true');
        try {
            $ages = ['__scheduler__' => null, 'backend_email_worker' => 1];
            $redisReply = 'PONG';
            $schema = $this->createMock(CommandInterface::class);
            $schema->method('queryScalar')->willReturn(true);
            $db = $this->createMock(ConnectionInterface::class);
            $db->method('createCommand')->willReturnCallback(
                function (?string $sql, array $params = []) use (&$ages, $schema): CommandInterface {
                    if (str_contains($sql ?? '', 'to_regclass')) { return $schema; }
                    $heartbeat = $this->createMock(CommandInterface::class);
                    $heartbeat->method('queryScalar')->willReturnCallback(
                        static fn() => $ages[$params[':code']] ?? null);
                    return $heartbeat;
                });
            $redis = $this->createMock(ClientInterface::class);
            $redis->method('__call')->willReturnCallback(static function () use (&$redisReply): string {
                return $redisReply;
            });
            $status = new OperationalStatus($db, $redis);
            self::assertFalse($status->ready()); // scheduler absent
            $ages['__scheduler__'] = 1;
            $ages['backend_email_worker'] = null;
            self::assertFalse($status->ready()); // email absent
            $ages['backend_email_worker'] = 1;
            $redisReply = 'DOWN';
            self::assertFalse($status->ready()); // Redis unavailable
            $redisReply = 'PONG';
            self::assertTrue($status->ready());
        } finally {
            putenv('CORE_SCHEDULER_ENABLED');
            putenv('CORE_EMAIL_WORKER_ENABLED');
        }
    }

    public function testRecorderRejectsUnsafeErrorBeforeDatabaseWrite(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::never())->method('createCommand');
        $this->expectException(\InvalidArgumentException::class);
        (new BackgroundJobStatusRecorder($db))->failed('runtime_entitlement_sync', 'secret from exception');
    }

    public function testRecorderUsesAtomicFailureIncrementAndSuccessReset(): void
    {
        $writes = [];
        $command = $this->createMock(CommandInterface::class);
        $command->method('execute')->willReturn(1);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(
            static function (?string $sql, array $params = []) use (&$writes, $command): CommandInterface {
                $writes[] = [$sql, $params];
                return $command;
            });
        $recorder = new BackgroundJobStatusRecorder($db);
        $recorder->failed('runtime_entitlement_sync', 'job_failed');
        $recorder->skipped('runtime_entitlement_sync', 'entitlement_sync_unconfigured');
        $recorder->succeeded('runtime_entitlement_sync', 8, 1);
        self::assertStringContainsString('consecutive_failures + 1', $writes[0][0]);
        self::assertStringNotContainsString('consecutive_failures', $writes[1][0]);
        self::assertStringContainsString('consecutive_failures = 0', $writes[2][0]);
        self::assertSame([':code' => 'runtime_entitlement_sync', ':error' => 'job_failed'], $writes[0][1]);
        self::assertSame([':code' => 'runtime_entitlement_sync', ':result' => 'skipped'], $writes[1][1]);
    }

    public function testSchedulerRecordsHeartbeatAndExpectedSkipWithoutFailure(): void
    {
        $writes = [];
        $command = $this->createMock(CommandInterface::class);
        $command->method('execute')->willReturn(1);
        $command->method('queryScalar')->willReturn(true);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(
            static function (?string $sql, array $params = []) use (&$writes, $command): CommandInterface {
                if (str_contains($sql ?? '', 'INSERT INTO platform_runtime.background_job_status')) {
                    $writes[] = [$sql, $params];
                }
                return $command;
            });
        $loop = new SchedulerLoop($db, [
            new ScheduledJob('successful', 10, true, 1, static fn(): int => 1),
            new ScheduledJob('skipped_job', 10, true, 1,
                static fn(): int => throw new JobSkipped('policy_missing')),
        ], static fn(string $line) => null, recorder: new BackgroundJobStatusRecorder($db));
        $loop->tick(1000.0);
        $loop->tick(1001.0);
        self::assertCount(5, $writes);
        self::assertSame('__scheduler__', $writes[0][1][':code']);
        self::assertStringContainsString('consecutive_failures = 0', $writes[2][0]);
        self::assertSame('skipped', $writes[4][1][':result']);
        self::assertStringNotContainsString('consecutive_failures', $writes[4][0]);
    }

    public function testOperatorCliShowsSafeEmailCountAndEntitlementSummary(): void
    {
        $rows = $this->createMock(CommandInterface::class);
        $rows->method('queryAll')->willReturn([['job_code' => 'runtime_entitlement_sync',
            'last_success' => '2026-09-30', 'last_failure' => null, 'consecutive_failures' => 0,
            'last_duration_ms' => 10, 'last_processed_count' => 1,
            'last_result' => 'succeeded', 'heartbeat_age_seconds' => 3]]);
        $backlog = $this->createMock(CommandInterface::class);
        $backlog->method('queryOne')->willReturn(['pending_email_count' => 2, 'oldest_age' => 12]);
        $entitlement = $this->createMock(CommandInterface::class);
        $entitlement->method('queryOne')->willReturn(['status' => 'active', 'sequence' => 7,
            'valid_until' => '2026-10-01']);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(
            static function (?string $sql) use ($rows, $backlog, $entitlement): CommandInterface {
                if (str_contains($sql ?? '', 'core.outbox_events')) { return $backlog; }
                if (str_contains($sql ?? '', 'platform_runtime.installations i')) { return $entitlement; }
                return $rows;
            });
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            new OpsStatusCommand(new OperationalStatus($db, $this->createMock(ClientInterface::class))));
        self::assertSame(0, $tester->execute([]));
        $output = $tester->getDisplay();
        self::assertStringContainsString('runtime_entitlement_sync', $output);
        self::assertStringContainsString('healthy', $output);
        self::assertStringContainsString('"pendingEmailCount":2', $output);
        self::assertStringContainsString('"entitlementSequence":7', $output);
        self::assertStringNotContainsString('recipient@example.test', $output);
    }
}
