<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Messaging\Application\InboxConsumerGuard;
use App\Core\Messaging\Application\OutboxEventCommand;
use App\Core\Messaging\Application\OutboxPublisher;
use App\Core\Messaging\Application\OutboxRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class AuditMessagingPersistenceCest
{
    public function auditOutboxAndInboxLifecycle(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(6));
            $receipt = $container->get(AuditService::class)->record(
                new WriteAuditEventCommand(
                    actorType: 'system',
                    actorAccountId: null,
                    action: 'core.integration.checked',
                    resourceType: 'core.test',
                    resourceId: $suffix,
                    metadata: ['suite' => 'functional'],
                ),
            );
            assertTrue($receipt->id > 0);

            $outbox = $container->get(OutboxRepository::class);
            $event = $outbox->enqueue(new OutboxEventCommand(
                producerModule: 'core',
                eventType: 'core.integration.occurred',
                payload: ['suffix' => $suffix],
                aggregateType: 'core.test',
                aggregateId: $suffix,
            ));
            assertSame('pending', $event->status);

            $publisher = $container->get(OutboxPublisher::class);
            $claimed = $publisher->claim('functional-worker-' . $suffix, 10, 30);
            $claimedEvent = array_values(array_filter(
                $claimed,
                static fn($item): bool => $item->id === $event->id,
            ));
            assertSame(1, count($claimedEvent));
            assertSame('processing', $claimedEvent[0]->status);
            assertSame(1, $claimedEvent[0]->attempts);
            assertTrue($publisher->published($event->id));

            $inbox = $container->get(InboxConsumerGuard::class);
            $first = $inbox->register($event->eventId, 'functional-consumer-' . $suffix);
            $second = $inbox->register($event->eventId, 'functional-consumer-' . $suffix);
            assertSame($first->id, $second->id);
            assertFalse($inbox->wasProcessed($event->eventId, $first->consumer));
            assertTrue($inbox->processed($event->eventId, $first->consumer));
            assertTrue($inbox->wasProcessed($event->eventId, $first->consumer));

            try {
                $db->transaction(static function () use ($outbox, $suffix): void {
                    $outbox->enqueue(new OutboxEventCommand(
                        producerModule: 'core',
                        eventType: 'core.rollback.test',
                        payload: ['suffix' => $suffix],
                    ));
                    throw new RuntimeException('Force rollback.');
                });
            } catch (RuntimeException) {
                // Expected: business write and outbox share the ambient transaction/savepoint.
            }
            $rolledBackCount = (int) $db->createCommand(
                "SELECT COUNT(*) FROM core.outbox_events WHERE event_type = 'core.rollback.test' "
                . "AND payload ->> 'suffix' = :suffix",
                [':suffix' => $suffix],
            )->queryScalar();
            assertSame(0, $rolledBackCount);
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }
}
