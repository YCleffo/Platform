<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Payment\Provider\ProviderPaymentFailure;
use ControlPlane\Subscription\Application\ExpireSubscription;
use Doctrine\DBAL\Connection;

final readonly class RenewalCollectionLifecycle
{
    public function __construct(private Connection $connection, private RenewalDunningPolicy $policy) {}

    public function uncertain(string $invoiceId, \DateTimeImmutable $now, string $code): void
    {
        $this->connection->executeStatement(<<<'SQL'
UPDATE commercial.renewal_collection_states
SET state='collecting', network_retry_count=network_retry_count+1, next_attempt_at=?::timestamptz,
    last_failure_class='unknown', last_failure_code=?, updated_at=now()
WHERE invoice_id=? AND state IN ('ready','collecting')
SQL, [$this->timestamp($this->policy->uncertainRetryAt($now)), $code, $invoiceId]);
    }

    public function terminal(string $invoiceId, ProviderPaymentFailure $failure, \DateTimeImmutable $now, string $correlationId, ?string $providerAccountId = null, ?string $providerMethodReference = null): void
    {
        $subscriptionId = $this->connection->fetchOne('SELECT subscription_id FROM commercial.invoices WHERE id=?', [$invoiceId]);
        if (!is_string($subscriptionId)) return;
        RenewalExecutionLock::acquire($this->connection, $subscriptionId);
        try {
            $this->connection->transactional(function (Connection $db) use ($invoiceId, $failure, $now, $correlationId, $providerAccountId, $providerMethodReference): void {
            $identity = $db->fetchAssociative('SELECT subscription_id FROM commercial.invoices WHERE id=?', [$invoiceId]);
            if ($identity === false) return;
            $subscription = $db->fetchAssociative('SELECT status, grace_until FROM commercial.subscriptions WHERE id=? FOR UPDATE', [$identity['subscription_id']]);
            $invoice = $db->fetchAssociative('SELECT status FROM commercial.invoices WHERE id=? FOR UPDATE', [$invoiceId]);
            $payment = $db->fetchAssociative('SELECT status FROM commercial.payments WHERE invoice_id=? FOR UPDATE', [$invoiceId]);
            $row = $db->fetchAssociative('SELECT * FROM commercial.renewal_collection_states WHERE invoice_id=? FOR UPDATE', [$invoiceId]);
            if ($row === false || $subscription === false || $invoice === false || $subscription['status'] !== 'active'
                || $invoice['status'] !== 'issued' || ($payment !== false && $payment['status'] === 'succeeded')
                || in_array($row['state'], ['succeeded','exhausted'], true)) return;
            if ($failure->paymentMethodInvalid && $providerAccountId !== null && $providerMethodReference !== null) {
                $db->executeStatement("UPDATE commercial.saved_payment_methods SET status='provider_unusable', is_default=false, disabled_at=now(), updated_at=now() WHERE provider_account_id=? AND provider_method_reference=? AND status='active'", [$providerAccountId, $providerMethodReference]);
            }
            if (!$this->policy->consumesAttempt($failure->class)) {
                $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='collecting', next_attempt_at=?::timestamptz, last_failure_class=?, last_failure_code=?, updated_at=now() WHERE invoice_id=?", [$this->timestamp($this->policy->uncertainRetryAt($now)), $failure->class, $failure->code, $invoiceId]);
                return;
            }
            $attempts = (int) $row['attempt_count'] + 1;
            $retryAt = $this->policy->mayRetry($failure->class) ? $this->policy->retryAt($attempts, $now) : null;
            $graceExpired = is_string($subscription['grace_until']) && $now >= new \DateTimeImmutable($subscription['grace_until']);
            $state = $graceExpired ? 'exhausted' : ($failure->paymentMethodInvalid ? 'payment_method_required' : 'retry_wait');
            if ($state === 'exhausted') {
                (new ExpireSubscription($db))->expireInTransaction($db, (string) $row['commercial_account_id'], (string) $row['subscription_id'], $now, $correlationId);
                return;
            }
            $db->executeStatement('UPDATE commercial.renewal_collection_states SET state=?, attempt_count=?, last_attempt_at=?::timestamptz, next_attempt_at=?::timestamptz, last_failure_class=?, last_failure_code=?, updated_at=now() WHERE invoice_id=?', [$state, $attempts, $this->timestamp($now), $retryAt === null ? null : $this->timestamp($retryAt), $failure->class, $failure->code, $invoiceId]);
            $event = $state === 'payment_method_required' ? 'subscription.payment_method_required' : 'subscription.renewal_collection_failed';
            $action = match ($state) {
                'payment_method_required' => 'renewal.payment_method_required',
                default => 'renewal.collection.failed',
            };
            $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, ?, 'invoice', ?, ?, 'failure')", [$row['commercial_account_id'], $action, $invoiceId, $correlationId]);
            $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES (?, 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'failure_class', ?::text, 'failure_code', ?::text, 'attempt_count', ?::int))", [$event, $row['subscription_id'], $row['subscription_id'], $invoiceId, $row['commercial_account_id'], $failure->class, $failure->code, $attempts]);
            if ($state === 'retry_wait' && $retryAt !== null) {
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.retry_scheduled', 'invoice', ?, ?, 'success')", [$row['commercial_account_id'], $invoiceId, $correlationId]);
            }
            });
        } finally {
            RenewalExecutionLock::release($this->connection, $subscriptionId);
        }
    }

    /** Must be called inside the authoritative settlement transaction. */
    /** @param array<string,mixed> $invoice */
    public function succeededInTransaction(Connection $db, array $invoice, string $paymentId, string $correlationId): void
    {
        if (($invoice['billing_reason'] ?? null) !== 'subscription_renewal') return;
        $subscription = $db->fetchAssociative('SELECT status, billing_status FROM commercial.subscriptions WHERE id=? FOR UPDATE', [$invoice['subscription_id']]);
        if ($subscription === false) throw new \LogicException('Renewal subscription is unavailable.');
        $state = $db->fetchAssociative('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=? FOR UPDATE', [$invoice['id']]);
        if ($state !== false && !in_array($state['state'], ['succeeded','exhausted'], true)) {
            $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='succeeded', next_attempt_at=NULL, last_failure_class=NULL, last_failure_code=NULL, updated_at=now() WHERE invoice_id=?", [$invoice['id']]);
        }
        if ($subscription['status'] !== 'active') {
            $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewal_late_settlement', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'payment_id', ?::text))", [$invoice['subscription_id'], $invoice['subscription_id'], $invoice['id'], $paymentId]);
            return;
        }
        if ($subscription['billing_status'] === 'past_due') {
            $db->executeStatement("UPDATE commercial.subscriptions SET billing_status='current', past_due_since=NULL, grace_until=NULL, updated_at=now() WHERE id=?", [$invoice['subscription_id']]);
            $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.billing_recovered', 'subscription', ?, ?, 'success')", [$invoice['commercial_account_id'], $invoice['subscription_id'], $correlationId]);
            $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.billing_recovered', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'payment_id', ?::text, 'commercial_account_id', ?::text))", [$invoice['subscription_id'], $invoice['subscription_id'], $invoice['id'], $paymentId, $invoice['commercial_account_id']]);
        }
    }

    private function timestamp(\DateTimeImmutable $value): string { return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'); }
}
