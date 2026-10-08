<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class AdvanceSubscriptionPeriod
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{id: string, status: 'active', current_period_start: string, current_period_end: string} */
    public function advance(string $accountId, string $subscriptionId, \DateTimeImmutable $instant, string $correlationId): array
    {
        if (!Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid subscription advancement request.');
        }

        return $this->connection->transactional(fn (Connection $connection): array => $this->advanceInTransaction($connection, $accountId, $subscriptionId, $instant, $correlationId));
    }

    /** @return array{id: string, status: 'active', current_period_start: string, current_period_end: string} */
    public function advanceInTransaction(Connection $connection, string $accountId, string $subscriptionId, \DateTimeImmutable $instant, string $correlationId): array
    {
        $subscription = $connection->fetchAssociative('SELECT id, commercial_account_id, status, billing_status, current_period_start, current_period_end FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $accountId]);
        if ($subscription === false) {
            throw new \DomainException('Subscription was not found.');
        }
        if ($subscription['status'] !== 'active' || !is_string($subscription['current_period_end'])) {
            throw new \DomainException('Only an active subscription can advance.');
        }
        $instant = $instant->setTimezone(new \DateTimeZone('UTC'));
        $currentEnd = new \DateTimeImmutable($subscription['current_period_end']);
        if ($instant < $currentEnd) {
            return $this->result($subscription);
        }
        $renewals = $connection->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.commercial_account_id, i.subscription_id, i.currency, i.total_minor,
       i.service_period_start, i.service_period_end, p.id AS payment_id, p.amount_minor, p.currency AS payment_currency
FROM commercial.invoices i
JOIN commercial.payments p ON p.invoice_id = i.id AND p.status = 'succeeded'
WHERE i.subscription_id = ? AND i.billing_reason = 'subscription_renewal' AND i.status = 'paid'
  AND i.service_period_start = ?::timestamptz
FOR UPDATE OF i, p
SQL, [$subscriptionId, $subscription['current_period_end']]);
        if (count($renewals) !== 1) {
            throw new \DomainException('Exactly one paid renewal invoice is required.');
        }
        $renewal = $renewals[0];
        if ($renewal['commercial_account_id'] !== $accountId || $renewal['subscription_id'] !== $subscriptionId
            || $renewal['currency'] !== $renewal['payment_currency'] || (int) $renewal['total_minor'] !== (int) $renewal['amount_minor']
            || !is_string($renewal['service_period_start']) || !is_string($renewal['service_period_end'])) {
            throw new \DomainException('Paid renewal invoice contract is inconsistent.');
        }
        $newStart = new \DateTimeImmutable($renewal['service_period_start']);
        $newEnd = new \DateTimeImmutable($renewal['service_period_end']);
        if ($newStart != $currentEnd || $newEnd <= $newStart) {
            throw new \DomainException('Renewal service period does not continue the active period.');
        }
        $closed = $connection->executeStatement("UPDATE commercial.usage_periods SET status = 'closed', closed_at = now() WHERE subscription_id = ? AND status = 'open' AND starts_at = ?::timestamptz AND ends_at = ?::timestamptz", [$subscriptionId, $subscription['current_period_start'], $subscription['current_period_end']]);
        if ($closed !== 1) {
            throw new \LogicException('Current usage period is unavailable.');
        }
        $advanced = $connection->fetchAssociative("UPDATE commercial.subscriptions SET current_period_start = ?::timestamptz, current_period_end = ?::timestamptz, billing_status='current', past_due_since=NULL, grace_until=NULL, updated_at = now() WHERE id = ? RETURNING id, status, current_period_start, current_period_end", [$renewal['service_period_start'], $renewal['service_period_end'], $subscriptionId]);
        if ($advanced === false) {
            throw new \LogicException('Advanced subscription state is unavailable.');
        }
        $connection->executeStatement("INSERT INTO commercial.usage_periods (subscription_id, starts_at, ends_at, status) VALUES (?, ?::timestamptz, ?::timestamptz, 'open')", [$subscriptionId, $renewal['service_period_start'], $renewal['service_period_end']]);
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.renewed', 'subscription', ?, ?, 'success')", [$accountId, $subscriptionId, $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewed', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'invoice_id', ?::text, 'payment_id', ?::text, 'current_period_start', to_jsonb(?::timestamptz), 'current_period_end', to_jsonb(?::timestamptz)))", [$subscriptionId, $subscriptionId, $accountId, $renewal['id'], $renewal['payment_id'], $renewal['service_period_start'], $renewal['service_period_end']]);

        return $this->result($advanced);
    }

    public function advanceDue(\DateTimeImmutable $instant, int $limit, string $correlationId): int
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid subscription advancement batch.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($instant, $limit, $correlationId): int {
            $rows = $connection->fetchAllAssociative(<<<'SQL'
SELECT s.id, s.commercial_account_id
FROM commercial.subscriptions s
WHERE s.status = 'active' AND s.current_period_end <= ?::timestamptz
  AND EXISTS (
    SELECT 1 FROM commercial.invoices i JOIN commercial.payments p ON p.invoice_id = i.id AND p.status = 'succeeded'
    WHERE i.subscription_id = s.id AND i.billing_reason = 'subscription_renewal' AND i.status = 'paid'
      AND i.service_period_start = s.current_period_end
  )
ORDER BY s.current_period_end, s.id
LIMIT ?
FOR UPDATE OF s SKIP LOCKED
SQL, [$instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), $limit], [ParameterType::STRING, ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $this->advanceInTransaction($connection, (string) $row['commercial_account_id'], (string) $row['id'], $instant, $correlationId);
            }

            return count($rows);
        });
    }

    /** @param array<string, mixed> $row
     * @return array{id: string, status: 'active', current_period_start: string, current_period_end: string}
     */
    private function result(array $row): array
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['current_period_start'] ?? null) || !is_string($row['current_period_end'] ?? null)) {
            throw new \LogicException('Subscription period is unavailable.');
        }

        return ['id' => $row['id'], 'status' => 'active', 'current_period_start' => $row['current_period_start'], 'current_period_end' => $row['current_period_end']];
    }
}
