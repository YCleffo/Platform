<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Console\ProcessEmailOutboxCommand;
use App\Core\Communication\Application\EmailOutboxPolicy;
use App\Core\Communication\Application\EmailOutboxProcessor;
use App\Core\Communication\Application\EmailSenderInterface;
use App\Core\Communication\Application\TemplateRendererInterface;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Messaging\Application\OutboxRepository;
use App\PlatformRuntime\Scheduler\BackgroundJobStatusRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class EmailWorkerStatusTest extends TestCase
{
    public function testEmptyBatchRecordsWorkerSuccessAndHeartbeat(): void
    {
        $outbox = $this->createMock(OutboxRepository::class);
        $outbox->method('claimPendingByTypes')->willReturn([]);
        $processor = new EmailOutboxProcessor(
            $outbox,
            $this->createMock(TemplateRendererInterface::class),
            $this->createMock(EmailSenderInterface::class),
            new EmailOutboxPolicy(30, 3, 5, 60, 'platform.example'),
            new SupportedLocales(new LocaleNormalizer(), ['en-US'], 'en-US'),
        );
        $writes = [];
        $command = $this->createMock(CommandInterface::class);
        $command->method('execute')->willReturn(1);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(
            static function (?string $sql, array $params = []) use (&$writes, $command): CommandInterface {
                $writes[] = [$sql, $params];
                return $command;
            },
        );
        $tester = new CommandTester(new ProcessEmailOutboxCommand(
            $processor,
            new BackgroundJobStatusRecorder($db),
        ));
        self::assertSame(0, $tester->execute([]));
        self::assertCount(2, $writes);
        self::assertStringContainsString("last_result = 'started'", $writes[0][0]);
        self::assertStringContainsString("last_result = 'succeeded'", $writes[1][0]);
        self::assertSame('backend_email_worker', $writes[1][1][':code']);
        self::assertSame(0, $writes[1][1][':processed']);
    }
}
