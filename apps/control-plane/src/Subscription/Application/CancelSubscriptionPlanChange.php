<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CancelSubscriptionPlanChange
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization) {}

    /** @return array{id: string, status: 'cancelled'} */
    public function cancel(string $userId, string $accountId, string $changeId, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($changeId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid plan change cancellation.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $scope = 'subscription.plan_change.cancel.' . str_replace('-', '', strtolower($accountId));
        $hash = hash('sha256', json_encode([strtolower($accountId), strtolower($changeId)], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $db) use ($userId, $accountId, $changeId, $idempotencyKey, $correlationId, $scope, $hash): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $sourceId = $db->fetchOne('SELECT source_subscription_id FROM commercial.subscription_plan_changes WHERE id = ? AND commercial_account_id = ?', [$changeId, $accountId]);
            if (!is_string($sourceId)) {
                throw new \DomainException('Plan change was not found.');
            }
            $db->executeQuery('SELECT id FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$sourceId]);
            $change = $db->fetchAssociative('SELECT id, status, target_subscription_id, invoice_id FROM commercial.subscription_plan_changes WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$changeId, $accountId]);
            if ($change === false || !is_string($change['target_subscription_id'])) {
                throw new \LogicException('Plan change state is unavailable.');
            }
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            if ($db->fetchOne('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ? AND scope <> ? LIMIT 1', [$idempotencyKey, $scope]) !== false) {
                throw new \DomainException('Idempotency key was reused for another operation.');
            }
            $db->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING", [$scope, $idempotencyKey, $hash]);
            $key = $db->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($key === false || !is_string($key['request_hash']) || !hash_equals($key['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with different content.');
            }
            if ($key['status'] === 'completed') {
                if ($key['result_reference'] !== $changeId) {
                    throw new \LogicException('Idempotent cancellation result is invalid.');
                }
                return ['id' => $changeId, 'status' => 'cancelled'];
            }
            if ($change['status'] === 'cancelled') {
                $db->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$changeId, $scope, $idempotencyKey]);
                return ['id' => $changeId, 'status' => 'cancelled'];
            }
            if (!in_array($change['status'], ['scheduled', 'payment_required'], true)) {
                throw new \DomainException('Plan change can no longer be cancelled.');
            }
            $target = $db->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$change['target_subscription_id']]);
            if ($target !== 'draft') {
                throw new \DomainException('Replacement subscription is no longer draft.');
            }
            if (is_string($change['invoice_id'])) {
                $invoice = $db->fetchAssociative('SELECT id, status FROM commercial.invoices WHERE id = ? FOR UPDATE', [$change['invoice_id']]);
                if ($invoice === false || $invoice['status'] !== 'issued') {
                    throw new \DomainException('Plan change invoice is no longer cancellable.');
                }
                $payment = $db->fetchAssociative('SELECT id, status FROM commercial.payments WHERE invoice_id = ? FOR UPDATE', [$invoice['id']]);
                if ($payment !== false) {
                    if ($payment['status'] !== 'pending' || $db->fetchOne("SELECT 1 FROM commercial.payment_attempts WHERE payment_id = ? AND status = 'pending' FOR UPDATE", [$payment['id']]) !== false) {
                        throw new \DomainException('Payment processing is in progress.');
                    }
                    $db->executeStatement("UPDATE commercial.payments SET status = 'cancelled', cancelled_at = now(), updated_at = now() WHERE id = ?", [$payment['id']]);
                }
                $db->executeStatement("UPDATE commercial.invoices SET status = 'void', voided_at = now(), updated_at = now() WHERE id = ?", [$invoice['id']]);
            }
            $db->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ?", [$change['target_subscription_id']]);
            $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'cancelled', updated_at = now() WHERE id = ?", [$changeId]);
            $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'subscription.plan_change_cancelled', 'subscription_plan_change', ?, ?, 'success')", [$userId, $accountId, $changeId, $correlationId]);
            $db->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$changeId, $scope, $idempotencyKey]);

            return ['id' => $changeId, 'status' => 'cancelled'];
        });
    }
}
