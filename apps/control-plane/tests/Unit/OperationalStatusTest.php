<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\HealthController;
use ControlPlane\Infrastructure\Console\OpsStatusCommand;
use ControlPlane\Infrastructure\Scheduler\BackgroundJobStatusRecorder;
use ControlPlane\Infrastructure\Scheduler\OperationalStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OperationalStatusTest extends TestCase
{
    public function testLiveNeedsNoDatabaseAndReadyIsSafe(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willThrowException(new \RuntimeException('private SQL detail'));
        $health = new HealthController();
        self::assertSame('{"status":"ok"}', $health->live()->getContent());
        $response = $health->ready(new OperationalStatus($db));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"not_ready"}', $response->getContent());
    }

    public function testEnabledHeartbeatMustBeFreshAndDisabledIsIgnored(): void
    {
        putenv('CONTROL_SCHEDULER_ENABLED=true');
        putenv('CONTROL_OUTBOX_WORKER_ENABLED=true');
        try {
            $db = $this->createMock(Connection::class);
            $db->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []): mixed {
                if (str_contains($sql, 'to_regclass')) { return true; }
                return $params[0] === '__scheduler__' ? 5 : 180;
            });
            $status = new OperationalStatus($db);
            self::assertFalse($status->ready());
            self::assertTrue($status->componentHealthy('control-scheduler'));
            self::assertFalse($status->componentHealthy('control-outbox-worker'));
            putenv('CONTROL_OUTBOX_WORKER_ENABLED=false');
            self::assertTrue($status->ready());
        } finally {
            putenv('CONTROL_SCHEDULER_ENABLED');
            putenv('CONTROL_OUTBOX_WORKER_ENABLED');
        }
    }

    public function testRecorderWritesOnlyMachineCodesAndAtomicCounters(): void
    {
        $sql = [];
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $statement, array $params = []) use (&$sql): int {
            $sql[] = [$statement, $params];
            return 1;
        });
        $recorder = new BackgroundJobStatusRecorder($db);
        $recorder->heartbeat('__scheduler__');
        $recorder->started('reconcile_yookassa_payments');
        $recorder->failed('reconcile_yookassa_payments', 'job_failed');
        $recorder->skipped('reconcile_yookassa_payments', 'lock_busy');
        $recorder->succeeded('reconcile_yookassa_payments', 12, 2);
        self::assertCount(5, $sql);
        self::assertStringContainsString('consecutive_failures + 1', $sql[2][0]);
        self::assertStringNotContainsString('consecutive_failures', $sql[3][0]);
        self::assertStringContainsString('consecutive_failures = 0', $sql[4][0]);
        self::assertSame(['reconcile_yookassa_payments', 'job_failed'], $sql[2][1]);
        try { $recorder->failed('reconcile_yookassa_payments', 'exception: private detail');
            self::fail('Exception text accepted.'); }
        catch (\InvalidArgumentException) { self::assertCount(5, $sql); }
    }

    public function testOperatorStatusShowsStatesAndCountsWithoutPayload(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([
            ['job_code' => '__scheduler__', 'last_success' => null, 'last_failure' => null,
                'consecutive_failures' => 0, 'last_duration_ms' => null, 'last_processed_count' => null,
                'last_result' => 'waiting', 'heartbeat_age_seconds' => 180],
            ['job_code' => 'reconcile_yookassa_payments', 'last_success' => '2026-09-30',
                'last_failure' => '2026-09-30', 'consecutive_failures' => 2,
                'last_duration_ms' => 12, 'last_processed_count' => 1,
                'last_result' => 'failed', 'heartbeat_age_seconds' => 5],
        ]);
        $db->method('fetchAssociative')->willReturnCallback(static function (string $sql): array {
            return str_contains($sql, 'subscription_plan_changes')
                ? ['paid_unapplied_count' => 4, 'recovery_required_count' => 2,
                    'pending_refund_count' => 1, 'oldest_pending_age' => 600]
                : ['pending_count' => 3, 'oldest_age' => 40];
        });
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            new OpsStatusCommand(new OperationalStatus($db)));
        self::assertSame(0, $tester->execute([]));
        $text = $tester->getDisplay();
        self::assertStringContainsString('stale', $text);
        self::assertStringContainsString('failed', $text);
        self::assertStringContainsString('"pendingOutboxCount":3', $text);
        self::assertStringContainsString('"recoveryRequiredCount":2', $text);
        self::assertStringNotContainsString('recipient@example.test', $text);
        self::assertStringNotContainsString('payload', $text);
    }

    public function testMissingBacklogRowFailsClosedWithoutDatabaseDetails(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([]);
        $db->method('fetchAssociative')->willReturn(false);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            new OpsStatusCommand(new OperationalStatus($db)),
        );

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Operational status unavailable.', $tester->getDisplay());
        self::assertStringNotContainsString('SQL', $tester->getDisplay());
    }
}
