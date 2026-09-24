<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CancelSubscription
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{id: string, status: 'cancelled'} */
    public function cancel(string $userId, string $accountId, string $subscriptionId, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $scope = 'subscription.cancel.' . str_replace('-', '', strtolower($accountId)) . '.' . str_replace('-', '', strtolower($subscriptionId));
        $hash = hash('sha256', json_encode([
            'operation' => 'cancel',
            'commercial_account_id' => strtolower($accountId),
            'subscription_id' => strtolower($subscriptionId),
        ], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $connection) use ($userId, $accountId, $subscriptionId, $idempotencyKey, $correlationId, $scope, $hash): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');

            // Serialize the same opaque key even when callers attempt to reuse it for another resource.
            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            $otherScope = $connection->fetchOne('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ? AND scope <> ? LIMIT 1', [$idempotencyKey, $scope]);
            if ($otherScope !== false) {
                throw new \DomainException('Idempotency key was reused for a different request.');
            }

            $inserted = $connection->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$scope, $idempotencyKey, $hash],
            );
            $idempotency = $connection->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($idempotency === false) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!is_string($idempotency['request_hash']) || !hash_equals($idempotency['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($idempotency['status'] !== 'completed' || $idempotency['result_reference'] !== $subscriptionId) {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }

                return ['id' => $subscriptionId, 'status' => 'cancelled'];
            }

            $subscription = $connection->fetchAssociative('SELECT id, status FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $accountId]);
            if ($subscription === false) {
                throw new \DomainException('Subscription was not found.');
            }
            if ($subscription['status'] === 'expired') {
                throw new \DomainException('An expired subscription cannot be cancelled.');
            }
            if ($subscription['status'] !== 'cancelled') {
                if (!in_array($subscription['status'], ['draft', 'active'], true)) {
                    throw new \DomainException('Subscription cannot be cancelled from its current state.');
                }
                $connection->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ?", [$subscriptionId]);
                $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'subscription.cancelled', 'subscription', ?, ?, 'success')", [$userId, $accountId, $subscriptionId, $correlationId]);
                $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.cancelled', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text))", [$subscriptionId, $subscriptionId, $accountId]);
            }

            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$subscriptionId, $scope, $idempotencyKey]);

            return ['id' => $subscriptionId, 'status' => 'cancelled'];
        });
    }
}
