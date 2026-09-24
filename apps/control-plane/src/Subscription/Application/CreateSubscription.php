<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreateSubscription
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{id: string, status: 'draft'} */
    public function create(string $userId, string $accountId, string $planPriceId, int $quantity, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($planPriceId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $hash = hash('sha256', json_encode([
            'commercial_account_id' => strtolower($accountId),
            'plan_price_id' => strtolower($planPriceId),
            'quantity' => $quantity,
        ], JSON_THROW_ON_ERROR));
        $scope = 'subscription.create.' . str_replace('-', '', strtolower($accountId));

        return $this->connection->transactional(function (Connection $connection) use ($userId, $accountId, $planPriceId, $quantity, $idempotencyKey, $correlationId, $hash, $scope): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');

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
                if ($idempotency['status'] !== 'completed' || !is_string($idempotency['result_reference'])) {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }

                return ['id' => $idempotency['result_reference'], 'status' => 'draft'];
            }

            $account = $connection->fetchAssociative('SELECT id, status, onboarding_state FROM commercial.commercial_accounts WHERE id = ? FOR UPDATE', [$accountId]);
            if ($account === false) {
                throw new \DomainException('Commercial account was not found.');
            }
            $initialSubscription = $account['status'] === 'draft' && $account['onboarding_state'] === 'ready_for_subscription';
            $replacementSubscription = $account['status'] === 'active' && $account['onboarding_state'] === 'completed';
            if (!$initialSubscription && !$replacementSubscription) {
                throw new \DomainException('Commercial account is not ready for a subscription.');
            }

            if ($connection->fetchOne("SELECT 1 FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'active'", [$accountId]) !== false) {
                throw new \DomainException('An active subscription already exists.');
            }

            $billingProfileId = $connection->fetchOne('SELECT id FROM commercial.billing_profiles WHERE commercial_account_id = ? AND archived_at IS NULL ORDER BY is_default DESC, created_at, id LIMIT 1 FOR UPDATE', [$accountId]);
            if (!is_string($billingProfileId)) {
                throw new \DomainException('An active billing profile is required.');
            }

            $price = $connection->fetchAssociative(<<<'SQL'
SELECT pp.id, pp.billing_period, p.status AS plan_status
FROM commercial.plan_prices pp
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE pp.id = ?
FOR UPDATE OF pp, p
SQL, [$planPriceId]);
            if ($price === false) {
                throw new \DomainException('Plan price was not found.');
            }
            if ($price['plan_status'] !== 'published') {
                throw new \DomainException('Only a published plan can be selected.');
            }
            if ($price['billing_period'] === 'one_time') {
                throw new \DomainException('One-time prices are not subscriptions.');
            }
            if (!in_array($price['billing_period'], ['month', 'year'], true)) {
                throw new \DomainException('Unsupported subscription billing period.');
            }

            if ($connection->fetchOne("SELECT 1 FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'draft'", [$accountId]) !== false) {
                throw new \DomainException('A draft subscription already exists.');
            }

            $subscriptionId = $connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$accountId, $billingProfileId]);
            if (!is_string($subscriptionId)) {
                throw new \LogicException('Subscription ID unavailable.');
            }
            $connection->executeStatement('INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, ?, now())', [$subscriptionId, $planPriceId, $quantity]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'subscription.created', 'subscription', ?, ?, 'success')", [$userId, $accountId, $subscriptionId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.created', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text))", [$subscriptionId, $subscriptionId, $accountId]);
            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$subscriptionId, $scope, $idempotencyKey]);

            return ['id' => $subscriptionId, 'status' => 'draft'];
        });
    }
}
