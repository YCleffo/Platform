<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Subscription\Application\ExpireSubscription;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class ProcessRenewalDunning
{
    public function __construct(private Connection $connection, private RenewalDunningPolicy $policy,
        private CollectRenewalInvoice $collector, private ExpireSubscription $expiration) {}

    /** @return array{selected:int,started:int,past_due:int,recovered:int,payment_method_required:int,exhausted:int} */
    public function process(\DateTimeImmutable $instant, int $limit, string $correlationId): array
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) throw new \InvalidArgumentException('Invalid dunning batch.');
        $now = $instant->setTimezone(new \DateTimeZone('UTC'));
        $report = ['selected' => 0, 'started' => 0, 'past_due' => 0, 'recovered' => 0, 'payment_method_required' => 0, 'exhausted' => 0];
        $report['past_due'] = $this->enterPastDue($now, $limit, $correlationId);

        $work = $this->connection->transactional(function (Connection $db) use ($now, $limit): array {
            $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT r.invoice_id, r.state
FROM commercial.renewal_collection_states r
JOIN commercial.invoices i ON i.id=r.invoice_id
JOIN commercial.subscriptions s ON s.id=r.subscription_id
WHERE i.status='issued' AND s.status='active' AND (
 (r.state='retry_wait' AND r.next_attempt_at <= ?::timestamptz) OR
 (r.state='payment_method_required' AND EXISTS (
   SELECT 1 FROM commercial.saved_payment_methods m JOIN commercial.payment_provider_accounts pa ON pa.id=m.provider_account_id
   WHERE m.commercial_account_id=r.commercial_account_id AND m.status='active' AND m.is_default
     AND m.method_type='bank_card' AND pa.status='active' AND pa.provider_code='yookassa')))
ORDER BY r.next_attempt_at NULLS FIRST, r.invoice_id
LIMIT ? FOR UPDATE OF r SKIP LOCKED
SQL, [$this->timestamp($now), $limit], [ParameterType::STRING, ParameterType::INTEGER]);
            foreach ($rows as $row) {
                if ($row['state'] === 'retry_wait') {
                    $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='collecting', updated_at=now() WHERE invoice_id=? AND state='retry_wait'", [$row['invoice_id']]);
                }
            }
            return $rows;
        });
        $report['selected'] = count($work);
        foreach ($work as $row) {
            $result = $this->collector->collect((string) $row['invoice_id'], $correlationId, $now);
            if (isset($result['attempt_id'])) ++$report['started'];
            if ($result['state'] === 'no_payment_method') ++$report['payment_method_required'];
        }
        $exhausted = $this->exhaustDue($now, $limit, $correlationId);
        $report['exhausted'] = count($exhausted);
        foreach ($exhausted as $row) {
            try { $this->expiration->expire((string) $row['commercial_account_id'], (string) $row['subscription_id'], $now, $correlationId); }
            catch (\DomainException) { /* A concurrent settlement/cancellation owns the final state. */ }
        }
        return $report;
    }

    private function enterPastDue(\DateTimeImmutable $now, int $limit, string $correlationId): int
    {
        return $this->connection->transactional(function (Connection $db) use ($now, $limit, $correlationId): int {
            $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT s.id, s.commercial_account_id, s.current_period_end, i.id AS invoice_id
FROM commercial.subscriptions s
JOIN commercial.invoices i ON i.subscription_id=s.id AND i.commercial_account_id=s.commercial_account_id
 AND i.billing_reason='subscription_renewal' AND i.service_period_start=s.current_period_end AND i.status='issued'
WHERE s.status='active' AND s.billing_status='current' AND s.current_period_end <= ?::timestamptz
ORDER BY s.current_period_end, s.id LIMIT ? FOR UPDATE OF s,i SKIP LOCKED
SQL, [$this->timestamp($now), $limit], [ParameterType::STRING, ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $periodEnd = new \DateTimeImmutable((string) $row['current_period_end']);
                $grace = $this->policy->graceDeadline($periodEnd);
                $db->executeStatement("UPDATE commercial.subscriptions SET billing_status='past_due', past_due_since=?::timestamptz, grace_until=?::timestamptz, updated_at=now() WHERE id=? AND billing_status='current'", [$this->timestamp($periodEnd), $this->timestamp($grace), $row['id']]);
                $db->executeStatement("INSERT INTO commercial.renewal_collection_states (invoice_id, commercial_account_id, subscription_id, state, next_attempt_at) VALUES (?, ?, ?, 'ready', now()) ON CONFLICT (invoice_id) DO NOTHING", [$row['invoice_id'], $row['commercial_account_id'], $row['id']]);
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.past_due', 'subscription', ?, ?, 'success')", [$row['commercial_account_id'], $row['id'], $correlationId]);
                $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.past_due', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'past_due_since', to_jsonb(?::timestamptz), 'grace_until', to_jsonb(?::timestamptz)))", [$row['id'], $row['id'], $row['invoice_id'], $row['commercial_account_id'], $this->timestamp($periodEnd), $this->timestamp($grace)]);
            }
            return count($rows);
        });
    }

    /** @return list<array<string,mixed>> */
    private function exhaustDue(\DateTimeImmutable $now, int $limit, string $correlationId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($now, $limit, $correlationId): array {
            $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT r.invoice_id, r.subscription_id, r.commercial_account_id
FROM commercial.renewal_collection_states r
JOIN commercial.invoices i ON i.id=r.invoice_id
JOIN commercial.subscriptions s ON s.id=r.subscription_id
WHERE i.status='issued' AND s.status='active' AND r.state NOT IN ('succeeded','exhausted')
 AND ((r.state <> 'collecting' AND r.attempt_count >= ?) OR (s.billing_status='past_due' AND s.grace_until <= ?::timestamptz))
ORDER BY s.grace_until NULLS LAST, r.invoice_id LIMIT ? FOR UPDATE OF r,s SKIP LOCKED
SQL, [$this->policy->maximumAttempts(), $this->timestamp($now), $limit], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='exhausted', next_attempt_at=NULL, updated_at=now() WHERE invoice_id=?", [$row['invoice_id']]);
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.dunning_exhausted', 'invoice', ?, ?, 'failure')", [$row['commercial_account_id'], $row['invoice_id'], $correlationId]);
                $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.dunning_exhausted', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text))", [$row['subscription_id'], $row['subscription_id'], $row['invoice_id'], $row['commercial_account_id']]);
            }
            return $rows;
        });
    }

    private function timestamp(\DateTimeImmutable $value): string { return $value->format('Y-m-d H:i:s.uP'); }
}
