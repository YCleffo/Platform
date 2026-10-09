<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Billing\Application\RenewalExecutionLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ExpireSubscription
{
    public function __construct(private Connection $connection, private ?AdvanceSubscriptionPeriod $advancement = null, private int $graceSeconds = 0)
    {
        if ($graceSeconds < 0 || $graceSeconds > 604800) {
            throw new \InvalidArgumentException('Renewal settlement grace must be between 0 and 604800 seconds.');
        }
    }

    /** @return array{id: string, status: 'expired'|'active'} */
    public function expire(string $accountId, string $subscriptionId, \DateTimeImmutable $effectiveInstant, string $correlationId): array
    {
        if (!Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }

        RenewalExecutionLock::acquire($this->connection, $subscriptionId);
        try {
            return $this->connection->transactional(fn (Connection $connection): array =>
                $this->expireInTransaction($connection, $accountId, $subscriptionId, $effectiveInstant, $correlationId));
        } finally {
            RenewalExecutionLock::release($this->connection, $subscriptionId);
        }
    }

    /** The caller owns the transaction and has locked this subscription before its renewal invoice.
     * @return array{id: string, status: 'expired'|'active'}
     */
    public function expireInTransaction(Connection $connection, string $accountId, string $subscriptionId, \DateTimeImmutable $effectiveInstant, string $correlationId): array
    {
        $subscription = $connection->fetchAssociative('SELECT id, commercial_account_id, status, billing_status, current_period_end, grace_until FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $accountId]);
        if ($subscription === false) {
            throw new \DomainException('Subscription was not found.');
        }

        return $this->expireLocked($connection, $subscription, $effectiveInstant, $correlationId);
    }

    public function expireDue(\DateTimeImmutable $effectiveInstant, int $batchSize, string $correlationId): int
    {
        if ($batchSize < 1 || $batchSize > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid expiration batch request.');
        }

        $rows = $this->connection->fetchAllAssociative(sprintf(<<<'SQL'
SELECT id, commercial_account_id
FROM commercial.subscriptions
WHERE status = 'active' AND (
 (billing_status='past_due' AND grace_until <= ?::timestamptz) OR
 (billing_status='current' AND current_period_end + (? * interval '1 second') <= ?::timestamptz
  AND NOT EXISTS (SELECT 1 FROM commercial.invoices i WHERE i.subscription_id=subscriptions.id AND i.billing_reason='subscription_renewal' AND i.status='issued')))
ORDER BY current_period_end, id
LIMIT %d
SQL, $batchSize), [$this->timestamp($effectiveInstant), $this->graceSeconds, $this->timestamp($effectiveInstant)]);
        $count = 0;
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['commercial_account_id'])) continue;
            RenewalExecutionLock::acquire($this->connection, $row['id']);
            try {
                try {
                    $count += $this->connection->transactional(function (Connection $connection) use ($row, $effectiveInstant, $correlationId): int {
                        $subscription = $connection->fetchAssociative('SELECT id, commercial_account_id, status, billing_status, current_period_end, grace_until FROM commercial.subscriptions WHERE id=? AND commercial_account_id=? AND status=? FOR UPDATE SKIP LOCKED', [$row['id'], $row['commercial_account_id'], 'active']);
                        if ($subscription === false) return 0;
                        $this->expireLocked($connection, $subscription, $effectiveInstant, $correlationId);
                        return 1;
                    });
                } catch (\DomainException) {
                    // A settlement or cancellation changed eligibility after selection.
                }
            } finally {
                RenewalExecutionLock::release($this->connection, $row['id']);
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{id: string, status: 'expired'|'active'}
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
        $expirationBoundary = $subscription['billing_status'] === 'past_due' && is_string($subscription['grace_until'] ?? null)
            ? new \DateTimeImmutable($subscription['grace_until'])
            : $periodEnd->modify(sprintf('+%d seconds', $this->graceSeconds));
        if ($expirationBoundary > $effectiveInstant) {
            throw new \DomainException('Subscription has not reached its current period end.');
        }

        $paidRenewal = $connection->fetchOne("SELECT id FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_renewal' AND status = 'paid' AND service_period_start = ?::timestamptz FOR UPDATE", [$subscription['id'], $subscription['current_period_end']]);
        if (is_string($paidRenewal)) {
            $advanced = ($this->advancement ?? new AdvanceSubscriptionPeriod($connection))->advanceInTransaction($connection, $subscription['commercial_account_id'], $subscription['id'], $effectiveInstant, $correlationId);

            return ['id' => $advanced['id'], 'status' => 'active'];
        }

        if ($subscription['billing_status'] === 'past_due') {
            $renewal = $connection->fetchAssociative("SELECT id, status FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal' AND service_period_start=?::timestamptz FOR UPDATE", [$subscription['id'], $subscription['current_period_end']]);
            if ($renewal !== false && $renewal['status'] === 'issued') {
                $payment = $connection->fetchAssociative('SELECT status FROM commercial.payments WHERE invoice_id=? FOR UPDATE', [$renewal['id']]);
                $state = $connection->fetchAssociative('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=? FOR UPDATE', [$renewal['id']]);
                if ($payment !== false && $payment['status'] === 'succeeded') {
                    throw new \LogicException('Succeeded renewal payment has an unpaid invoice.');
                }
                if ($state !== false && !in_array($state['state'], ['succeeded', 'exhausted'], true)) {
                    $connection->executeStatement("UPDATE commercial.renewal_collection_states SET state='exhausted', next_attempt_at=NULL, updated_at=now() WHERE invoice_id=?", [$renewal['id']]);
                    $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.dunning_exhausted', 'invoice', ?, ?, 'failure')", [$subscription['commercial_account_id'], $renewal['id'], $correlationId]);
                    $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.dunning_exhausted', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text))", [$subscription['id'], $subscription['id'], $renewal['id'], $subscription['commercial_account_id']]);
                }
            }
        }

        $connection->executeStatement("UPDATE commercial.subscriptions SET status = 'expired', updated_at = now() WHERE id = ?", [$subscription['id']]);
        $closed = $connection->executeStatement("UPDATE commercial.usage_periods SET status = 'closed', closed_at = now() WHERE subscription_id = ? AND status = 'open'", [$subscription['id']]);
        if ($closed !== 1) {
            throw new \LogicException('Active subscription usage period is unavailable.');
        }
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.expired', 'subscription', ?, ?, 'success')", [$subscription['commercial_account_id'], $subscription['id'], $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.expired', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_end', to_jsonb(?::timestamptz)))", [$subscription['id'], $subscription['id'], $subscription['commercial_account_id'], $this->timestamp($periodEnd)]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewal_failed_or_expired', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_end', to_jsonb(?::timestamptz)))", [$subscription['id'], $subscription['id'], $subscription['commercial_account_id'], $this->timestamp($periodEnd)]);

        return ['id' => $subscription['id'], 'status' => 'expired'];
    }

    private function timestamp(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
