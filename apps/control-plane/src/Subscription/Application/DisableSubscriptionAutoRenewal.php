<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\RenewalExecutionLock;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DisableSubscriptionAutoRenewal
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{id: string, auto_renew_enabled: false} */
    public function disable(string $userId, string $accountId, string $subscriptionId, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $scope = 'subscription.auto_renew.disable.' . str_replace('-', '', strtolower($accountId)) . '.' . str_replace('-', '', strtolower($subscriptionId));
        $hash = hash('sha256', json_encode(['operation' => 'disable_auto_renew', 'commercial_account_id' => strtolower($accountId), 'subscription_id' => strtolower($subscriptionId)], JSON_THROW_ON_ERROR));

        RenewalExecutionLock::acquire($this->connection, $subscriptionId);
        try {
            return $this->connection->transactional(function (Connection $db) use ($userId, $accountId, $subscriptionId, $idempotencyKey, $correlationId, $scope, $hash): array {
                $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
                $subscription = $db->fetchAssociative('SELECT id, status, auto_renew_enabled, current_period_end FROM commercial.subscriptions WHERE id=? AND commercial_account_id=? FOR UPDATE', [$subscriptionId, $accountId]);
                if ($subscription === false) throw new \DomainException('Subscription was not found.');
                $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
                if ($db->fetchOne('SELECT 1 FROM commercial.idempotency_keys WHERE idempotency_key=? AND scope<>? LIMIT 1', [$idempotencyKey, $scope]) !== false) {
                    throw new \DomainException('Idempotency key was reused for a different request.');
                }
                $inserted = $db->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING", [$scope, $idempotencyKey, $hash]);
                $record = $db->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope=? AND idempotency_key=? FOR UPDATE", [$scope, $idempotencyKey]);
                if ($record === false || !is_string($record['request_hash']) || !hash_equals($record['request_hash'], $hash)) {
                    throw new \DomainException('Idempotency key was reused with a different request.');
                }
                if ($inserted === 0) {
                    if ($record['status'] !== 'completed' || $record['result_reference'] !== $subscriptionId) throw new \DomainException('Idempotency operation is already in progress.');
                    return ['id' => $subscriptionId, 'auto_renew_enabled' => false];
                }
                if ($subscription['status'] !== 'active') throw new \DomainException('Only an active subscription can stop auto-renewal.');
                if ($db->fetchOne("SELECT 1 FROM commercial.subscription_plan_changes WHERE (source_subscription_id=? OR target_subscription_id=?) AND status IN ('scheduled','payment_required','paid_unapplied')", [$subscriptionId, $subscriptionId]) !== false) {
                    throw new \DomainException('Resolve the open plan change before stopping auto-renewal.');
                }
                if (!in_array($subscription['auto_renew_enabled'], [true, 't', '1', 1], true)) {
                    $db->executeStatement("UPDATE commercial.idempotency_keys SET status='completed', result_reference=? WHERE scope=? AND idempotency_key=?", [$subscriptionId, $scope, $idempotencyKey]);
                    return ['id' => $subscriptionId, 'auto_renew_enabled' => false];
                }
                if (!is_string($subscription['current_period_end'])) throw new \LogicException('Active subscription period is unavailable.');

                $invoice = $db->fetchAssociative("SELECT id, status, service_period_end FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal' AND service_period_start=?::timestamptz AND status IN ('issued','paid') FOR UPDATE", [$subscriptionId, $subscription['current_period_end']]);
                if ($invoice !== false && $invoice['status'] === 'issued') {
                    if (!is_string($invoice['id'])) throw new \LogicException('Renewal invoice identity is unavailable.');
                    $payment = $db->fetchAssociative('SELECT id, status FROM commercial.payments WHERE invoice_id=? FOR UPDATE', [$invoice['id']]);
                    if ($payment !== false) {
                        if (!is_string($payment['id']) || $payment['status'] !== 'pending') throw new \DomainException('Reconcile the renewal payment before stopping auto-renewal.');
                        if ($db->fetchOne("SELECT 1 FROM commercial.payment_attempts WHERE payment_id=? AND status='pending' FOR UPDATE", [$payment['id']]) !== false) {
                            throw new \DomainException('Reconcile the pending provider attempt before stopping auto-renewal.');
                        }
                    }
                    $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='exhausted', next_attempt_at=NULL, updated_at=now() WHERE invoice_id=? AND state NOT IN ('succeeded','exhausted')", [$invoice['id']]);
                    if ($payment !== false) {
                        $db->executeStatement("UPDATE commercial.payments SET status='cancelled', cancelled_at=now(), updated_at=now() WHERE id=?", [$payment['id']]);
                        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'payment.cancelled', 'payment', ?, ?, 'success')", [$userId, $accountId, $payment['id'], $correlationId]);
                        $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('payment.cancelled', 'payment', ?, jsonb_build_object('payment_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text))", [$payment['id'], $payment['id'], $invoice['id'], $accountId]);
                    }
                    $db->executeStatement("UPDATE commercial.invoices SET status='void', voided_at=now(), updated_at=now() WHERE id=?", [$invoice['id']]);
                    $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'invoice.voided', 'invoice', ?, ?, 'success')", [$userId, $accountId, $invoice['id'], $correlationId]);
                    $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('invoice.voided', 'invoice', ?, jsonb_build_object('invoice_id', ?::text, 'subscription_id', ?::text, 'commercial_account_id', ?::text))", [$invoice['id'], $invoice['id'], $subscriptionId, $accountId]);
                }
                if ($invoice === false || $invoice['status'] === 'issued') {
                    if (new \DateTimeImmutable($subscription['current_period_end']) <= new \DateTimeImmutable('now')) {
                        throw new \DomainException('The paid period has ended; reconcile renewal or cancel the subscription.');
                    }
                }
                $db->executeStatement('UPDATE commercial.subscriptions SET auto_renew_enabled=false, updated_at=now() WHERE id=?', [$subscriptionId]);
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'subscription.auto_renew_disabled', 'subscription', ?, ?, 'success')", [$userId, $accountId, $subscriptionId, $correlationId]);
                $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.auto_renew_disabled', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_end', to_jsonb(?::timestamptz)))", [$subscriptionId, $subscriptionId, $accountId, $subscription['current_period_end']]);
                $db->executeStatement("UPDATE commercial.idempotency_keys SET status='completed', result_reference=? WHERE scope=? AND idempotency_key=?", [$subscriptionId, $scope, $idempotencyKey]);
                return ['id' => $subscriptionId, 'auto_renew_enabled' => false];
            });
        } finally {
            RenewalExecutionLock::release($this->connection, $subscriptionId);
        }
    }
}
