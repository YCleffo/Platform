<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ExpireSubscription
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{id: string, status: 'expired'} */
    public function expire(string $accountId, string $subscriptionId, \DateTimeImmutable $effectiveInstant, string $correlationId): array
    {
        if (!Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($accountId, $subscriptionId, $effectiveInstant, $correlationId): array {
            $subscription = $connection->fetchAssociative('SELECT id, commercial_account_id, status, current_period_end FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $accountId]);
            if ($subscription === false) {
                throw new \DomainException('Subscription was not found.');
            }

            return $this->expireLocked($connection, $subscription, $effectiveInstant, $correlationId);
        });
    }

    public function expireDue(\DateTimeImmutable $effectiveInstant, int $batchSize, string $correlationId): int
    {
        if ($batchSize < 1 || $batchSize > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid expiration batch request.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($effectiveInstant, $batchSize, $correlationId): int {
            $rows = $connection->fetchAllAssociative(sprintf(<<<'SQL'
SELECT id, commercial_account_id, status, current_period_end
FROM commercial.subscriptions
WHERE status = 'active' AND current_period_end <= ?::timestamptz
ORDER BY current_period_end, id
LIMIT %d
FOR UPDATE SKIP LOCKED
SQL, $batchSize), [$this->timestamp($effectiveInstant)]);

            foreach ($rows as $subscription) {
                $this->expireLocked($connection, $subscription, $effectiveInstant, $correlationId);
            }

            return count($rows);
        });
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{id: string, status: 'expired'}
     */
    private function expireLocked(Connection $connection, array $subscription, \DateTimeImmutable $effectiveInstant, string $correlationId): array
    {
        if (!is_string($subscription['id']) || !is_string($subscription['commercial_account_id'])) {
            throw new \LogicException('Subscription identity is unavailable.');
        }
        if ($subscription['status'] === 'expired') {
            return ['id' => $subscription['id'], 'status' => 'expired'];
        }
        if ($subscription['status'] !== 'active') {
            throw new \DomainException('Subscription cannot expire from its current state.');
        }
        if (!is_string($subscription['current_period_end'])) {
            throw new \LogicException('Active subscription period is invalid.');
        }
        $periodEnd = new \DateTimeImmutable($subscription['current_period_end']);
        if ($periodEnd > $effectiveInstant) {
            throw new \DomainException('Subscription has not reached its current period end.');
        }

        $connection->executeStatement("UPDATE commercial.subscriptions SET status = 'expired', updated_at = now() WHERE id = ?", [$subscription['id']]);
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.expired', 'subscription', ?, ?, 'success')", [$subscription['commercial_account_id'], $subscription['id'], $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.expired', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_end', to_jsonb(?::timestamptz)))", [$subscription['id'], $subscription['id'], $subscription['commercial_account_id'], $this->timestamp($periodEnd)]);

        return ['id' => $subscription['id'], 'status' => 'expired'];
    }

    private function timestamp(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
