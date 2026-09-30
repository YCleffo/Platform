<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Console\ProcessOutboxEmailCommand;
use ControlPlane\Infrastructure\Outbox\EmailTransport;
use ControlPlane\Infrastructure\Outbox\VerificationEmailProcessor;
use ControlPlane\Infrastructure\Scheduler\BackgroundJobStatusRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class OutboxWorkerStatusTest extends TestCase
{
    public function testEmptyBatchUpdatesWorkerHeartbeatAndSuccess(): void
    {
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $db->method('transactional')->willReturnCallback(static fn(callable $work): mixed => $work($db));
        $db->method('fetchAssociative')->willReturn(false);
        $processor = new VerificationEmailProcessor($db,
            new TokenCodec(str_repeat('x', 40)), $this->createMock(EmailTransport::class),
            'https://platform.example/verify', 'platform.example');
        $tester = new CommandTester(new ProcessOutboxEmailCommand($processor,
            new BackgroundJobStatusRecorder($db)));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 email event', $tester->getDisplay());
        self::assertTrue((bool) array_filter($writes, static fn(array $write): bool =>
            str_contains($write[0], "last_result = 'started'")
            && in_array('control_outbox_worker', $write[1], true)));
        self::assertTrue((bool) array_filter($writes, static fn(array $write): bool =>
            str_contains($write[0], "last_result = 'succeeded'")
            && in_array('control_outbox_worker', $write[1], true)));
    }

    public function testFailurePersistsOnlyMachineCodeAndStopsOneShotWorker(): void
    {
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $db->method('transactional')->willThrowException(new \RuntimeException('private recipient and SQL'));
        $processor = new VerificationEmailProcessor($db,
            new TokenCodec(str_repeat('x', 40)), $this->createMock(EmailTransport::class),
            'https://platform.example/verify', 'platform.example');
        $tester = new CommandTester(new ProcessOutboxEmailCommand($processor,
            new BackgroundJobStatusRecorder($db)));
        self::assertSame(1, $tester->execute([]));
        self::assertSame(['control_outbox_worker', 'outbox_batch_failed'], $writes[1][1]);
        self::assertStringNotContainsString('private recipient', $tester->getDisplay());
        self::assertStringNotContainsString('private recipient', json_encode($writes));
    }
}
