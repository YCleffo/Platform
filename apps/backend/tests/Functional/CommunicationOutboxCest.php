<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AccountSecurityNotifier;
use App\Core\Communication\Application\EmailEventType;
use App\Core\Communication\Application\EmailOutboxPolicy;
use App\Core\Communication\Application\EmailOutboxProcessor;
use App\Core\Communication\Application\EmailSenderInterface;
use App\Core\Communication\Domain\EmailDeliveryResult;
use App\Core\Communication\Domain\EmailMessage;
use App\Core\Communication\Infrastructure\FileEmailTemplateRenderer;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Messaging\Application\OutboxEventCommand;
use App\Core\Messaging\Application\OutboxRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class CommunicationOutboxCest
{
    public function successfulEventIsDeliveredOnlyOnce(FunctionalTester $tester): void
    {
        [$db, $outbox] = $this->dependencies();
        $transaction = $db->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(6));
            $event = $outbox->enqueue($this->emailEvent($suffix));
            $other = $outbox->enqueue(new OutboxEventCommand(
                producerModule: 'core',
                eventType: 'core.not-an-email',
                payload: ['suffix' => $suffix],
            ));
            $sender = new SequenceEmailSender([
                EmailDeliveryResult::success('provider-id'),
            ]);
            $processor = $this->processor($outbox, $sender);

            $first = $processor->process('communication-success-' . $suffix);
            $second = $processor->process('communication-success-' . $suffix);

            assertSame(1, $first->claimed);
            assertSame(1, $first->sent);
            assertSame(0, $second->claimed);
            assertCount(1, $sender->messages);
            assertSame('published', $this->status($db, $event->id));
            assertSame('pending', $this->status($db, $other->id));
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    public function transportFailureIsRetriedWithTheSameMessageId(FunctionalTester $tester): void
    {
        [$db, $outbox] = $this->dependencies();
        $transaction = $db->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(6));
            $event = $outbox->enqueue($this->emailEvent($suffix));
            $sender = new SequenceEmailSender([
                EmailDeliveryResult::failure('SMTP temporarily unavailable.'),
                EmailDeliveryResult::success('provider-id'),
            ]);
            $processor = $this->processor($outbox, $sender);

            $failed = $processor->process('communication-retry-' . $suffix);
            assertSame(1, $failed->retrying);
            assertSame('failed', $this->status($db, $event->id));
            $availableInFuture = (bool) $db->createCommand(
                'SELECT available_at > now() FROM core.outbox_events WHERE id = :id',
                [':id' => $event->id],
            )->queryScalar();
            assertTrue($availableInFuture);

            $db->createCommand(
                'UPDATE core.outbox_events SET available_at = now() WHERE id = :id',
                [':id' => $event->id],
            )->execute();
            $retried = $processor->process('communication-retry-' . $suffix);
            $afterSuccess = $processor->process('communication-retry-' . $suffix);

            assertSame(1, $retried->sent);
            assertSame(0, $afterSuccess->claimed);
            assertCount(2, $sender->messages);
            assertSame($sender->messages[0]->messageId, $sender->messages[1]->messageId);
            assertSame('published', $this->status($db, $event->id));
            assertSame(2, (int) $db->createCommand(
                'SELECT attempts FROM core.outbox_events WHERE id = :id',
                [':id' => $event->id],
            )->queryScalar());
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    public function deadEmailPayloadIsScrubbed(FunctionalTester $tester): void
    {
        [$db, $outbox] = $this->dependencies();
        $transaction = $db->beginTransaction();
        try {
            $event = $outbox->enqueue($this->emailEvent(bin2hex(random_bytes(6))));
            $sender = new SequenceEmailSender([
                EmailDeliveryResult::failure('secret'),
                EmailDeliveryResult::failure('secret'),
                EmailDeliveryResult::failure('secret'),
            ]);
            $processor = $this->processor($outbox, $sender);
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $processor->process('communication-dead-' . $event->id);
                if ($attempt < 2) {
                    $db->createCommand('UPDATE core.outbox_events SET available_at = now() WHERE id = :id',
                        [':id' => $event->id])->execute();
                }
            }
            assertSame('dead', $this->status($db, $event->id));
            assertSame('{}', (string) $db->createCommand(
                'SELECT payload::text FROM core.outbox_events WHERE id = :id',
                [':id' => $event->id])->queryScalar());
            assertSame('{}', (string) $db->createCommand(
                'SELECT headers::text FROM core.outbox_events WHERE id = :id',
                [':id' => $event->id])->queryScalar());
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    public function backgroundLocaleAndQueuedEmailAreDeterministic(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $outbox = $container->get(OutboxRepository::class);
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand(
                "UPDATE core.outbox_events SET available_at = now() + interval '1 day' WHERE status IN ('pending', 'failed')",
            )->execute();
            $notifier = $container->get(AccountSecurityNotifier::class);
            $recipients = [];
            foreach ([
                ['en-US', 'en-US'],
                ['ru-RU', 'ru-RU'],
                [null, 'ru-RU'],
            ] as $index => [$savedLocale, $expectedLocale]) {
                $suffix = bin2hex(random_bytes(5));
                $email = sprintf('background-locale-%d-%s@example.test', $index, $suffix);
                $accountId = $db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.accounts (account_type, status, label, locale)
                    VALUES ('service', 'active', :label, :locale)
                    RETURNING id::text
                    SQL,
                    [':label' => 'Background locale ' . $suffix, ':locale' => $savedLocale],
                )->queryScalar();
                assertTrue(is_string($accountId));
                $db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.auth_identifiers (
                        account_id, identifier_type, value, normalized_value,
                        is_primary, can_login, can_recover, verified_at
                    )
                    VALUES (CAST(:account_id AS uuid), 'email', :email, :email, true, true, true, now())
                    SQL,
                    [':account_id' => $accountId, ':email' => $email],
                )->execute();

                $notifier->notify($accountId, 'auth.totp.enabled');
                $queuedLocale = $db->createCommand(
                    <<<'SQL'
                    SELECT payload #>> '{locale}'
                    FROM core.outbox_events
                    WHERE payload #>> '{recipient,address}' = :email
                    ORDER BY id DESC
                    LIMIT 1
                    SQL,
                    [':email' => $email],
                )->queryScalar();
                assertSame($expectedLocale, $queuedLocale);
                $recipients[$email] = $expectedLocale;

                if ($index === 0) {
                    $db->createCommand(
                        "UPDATE core.accounts SET locale = 'ru-RU' WHERE id = CAST(:id AS uuid)",
                        [':id' => $accountId],
                    )->execute();
                }
            }

            $sender = new SequenceEmailSender([
                EmailDeliveryResult::success('background-en'),
                EmailDeliveryResult::success('background-ru'),
                EmailDeliveryResult::success('background-default'),
            ]);
            $report = $this->processor($outbox, $sender)->process('background-locale-worker', 3);
            assertSame(3, $report->sent);
            assertCount(3, $sender->messages);
            foreach ($sender->messages as $message) {
                $expectedLocale = $recipients[$message->recipientAddress];
                assertSame(
                    $expectedLocale === 'en-US'
                        ? 'Authenticator protection enabled'
                        : 'Защита приложением-аутентификатором включена',
                    $message->subject,
                );
            }
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    /** @return array{ConnectionInterface, OutboxRepository} */
    private function dependencies(): array
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();

        return [
            $container->get(ConnectionInterface::class),
            $container->get(OutboxRepository::class),
        ];
    }

    private function processor(
        OutboxRepository $outbox,
        EmailSenderInterface $sender,
    ): EmailOutboxProcessor {
        $locales = new SupportedLocales(new LocaleNormalizer(), ['ru-RU', 'en-US'], 'ru-RU');

        return new EmailOutboxProcessor(
            $outbox,
            new FileEmailTemplateRenderer(
                dirname(__DIR__, 2) . '/resources/email',
                $locales,
            ),
            $sender,
            new EmailOutboxPolicy(60, 3, 30, 300, 'platform.test'),
            $locales,
        );
    }

    private function emailEvent(string $suffix): OutboxEventCommand
    {
        return new OutboxEventCommand(
            producerModule: 'auth',
            eventType: EmailEventType::EMAIL_VERIFICATION_REQUESTED,
            payload: [
                'schemaVersion' => 1,
                'recipient' => [
                    'address' => 'integration-' . $suffix . '@example.test',
                    'name' => 'Integration User',
                ],
                'locale' => 'en',
                'variables' => [
                    'displayName' => 'Integration User',
                    'actionUrl' => 'https://example.test/verify/' . $suffix,
                ],
            ],
        );
    }

    private function status(ConnectionInterface $db, int $id): string
    {
        return (string) $db->createCommand(
            'SELECT status::text FROM core.outbox_events WHERE id = :id',
            [':id' => $id],
        )->queryScalar();
    }
}

final class SequenceEmailSender implements EmailSenderInterface
{
    /** @var list<EmailMessage> */
    public array $messages = [];

    /** @param non-empty-list<EmailDeliveryResult> $results */
    public function __construct(private array $results) {}

    public function send(EmailMessage $message): EmailDeliveryResult
    {
        $this->messages[] = $message;
        return array_shift($this->results)
            ?? EmailDeliveryResult::failure('No fake delivery result configured.');
    }
}
