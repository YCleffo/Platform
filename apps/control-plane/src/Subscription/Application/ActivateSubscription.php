<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Entitlement\Domain\EntitlementComposition;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ActivateSubscription
{
    public function __construct(private Connection $connection, private SubscriptionPeriodCalculator $periods)
    {
    }

    /** @return array{id: string, status: 'active', current_period_start: string, current_period_end: string} */
    public function activate(string $accountId, string $subscriptionId, \DateTimeImmutable $activationInstant, string $correlationId): array
    {
        if (!Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($accountId, $subscriptionId, $activationInstant, $correlationId): array {
            $subscription = $connection->fetchAssociative(<<<'SQL'
SELECT id, commercial_account_id, billing_profile_id, status, current_period_start, current_period_end
FROM commercial.subscriptions
WHERE id = ? AND commercial_account_id = ?
FOR UPDATE
SQL, [$subscriptionId, $accountId]);
            if ($subscription === false) {
                throw new \DomainException('Subscription was not found.');
            }
            if ($subscription['status'] === 'active') {
                return $this->activeResult($subscription);
            }
            if ($subscription['status'] !== 'draft') {
                throw new \DomainException('Subscription cannot be activated from its current state.');
            }

            $account = $connection->fetchAssociative('SELECT status, onboarding_state FROM commercial.commercial_accounts WHERE id = ? FOR UPDATE', [$accountId]);
            $initialActivation = $account !== false && $account['status'] === 'draft' && $account['onboarding_state'] === 'ready_for_subscription';
            $replacementActivation = $account !== false && $account['status'] === 'active' && $account['onboarding_state'] === 'completed';
            if (!$initialActivation && !$replacementActivation) {
                throw new \DomainException('Commercial account is not ready for activation.');
            }
            if ($connection->fetchOne("SELECT 1 FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'active' AND id <> ?", [$accountId, $subscriptionId]) !== false) {
                throw new \DomainException('Another active subscription already exists.');
            }
            $billingProfile = $connection->fetchOne('SELECT id FROM commercial.billing_profiles WHERE id = ? AND commercial_account_id = ? AND archived_at IS NULL FOR UPDATE', [$subscription['billing_profile_id'], $accountId]);
            if (!is_string($billingProfile)) {
                throw new \DomainException('Subscription billing profile is not active for this account.');
            }

            $items = $connection->fetchAllAssociative(<<<'SQL'
SELECT si.id, pp.currency, pp.billing_period, p.status AS plan_status
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE si.subscription_id = ?
ORDER BY si.id
FOR UPDATE OF si, pp, p
SQL, [$subscriptionId]);
            if ($items === []) {
                throw new \DomainException('Subscription must contain at least one item.');
            }

            $billingPeriod = null;
            $currency = null;
            foreach ($items as $item) {
                if ($item['plan_status'] !== 'published') {
                    throw new \DomainException('Every subscription plan must be published at activation.');
                }
                if (!in_array($item['billing_period'], ['month', 'year'], true)) {
                    throw new \DomainException('Every subscription price must be recurring.');
                }
                if ($billingPeriod !== null && $billingPeriod !== $item['billing_period']) {
                    throw new \DomainException('Mixed subscription billing periods are not supported.');
                }
                if ($currency !== null && $currency !== $item['currency']) {
                    throw new \DomainException('Mixed subscription currencies are not supported.');
                }
                $billingPeriod = $item['billing_period'];
                $currency = $item['currency'];
            }
            $featureRows = $connection->fetchAllAssociative(<<<'SQL'
SELECT f.code AS feature_code, pf.value::text AS feature_value
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plan_features pf ON pf.plan_id = pp.plan_id
JOIN commercial.features f ON f.id = pf.feature_id
WHERE si.subscription_id = ?
ORDER BY f.code, si.id
SQL, [$subscriptionId]);
            EntitlementComposition::fromRows($featureRows);

            $period = $this->periods->calculate($activationInstant, $billingPeriod);
            $start = $period['start']->format('Y-m-d H:i:s.uP');
            $end = $period['end']->format('Y-m-d H:i:s.uP');
            $activated = $connection->fetchAssociative("UPDATE commercial.subscriptions SET status = 'active', current_period_start = ?::timestamptz, current_period_end = ?::timestamptz, updated_at = now() WHERE id = ? RETURNING id, status, current_period_start, current_period_end", [$start, $end, $subscriptionId]);
            if ($activated === false) {
                throw new \LogicException('Activated subscription state is unavailable.');
            }
            if ($initialActivation) {
                $connection->executeStatement("UPDATE commercial.commercial_accounts SET status = 'active', onboarding_state = 'completed', updated_at = now() WHERE id = ?", [$accountId]);
            }
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.activated', 'subscription', ?, ?, 'success')", [$accountId, $subscriptionId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.activated', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_start', to_jsonb(?::timestamptz), 'current_period_end', to_jsonb(?::timestamptz)))", [$subscriptionId, $subscriptionId, $accountId, $start, $end]);

            return $this->activeResult($activated);
        });
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{id: string, status: 'active', current_period_start: string, current_period_end: string}
     */
    private function activeResult(array $subscription): array
    {
        if (!is_string($subscription['id']) || $subscription['status'] !== 'active'
            || !is_string($subscription['current_period_start']) || !is_string($subscription['current_period_end'])) {
            throw new \LogicException('Active subscription period is invalid.');
        }

        return [
            'id' => $subscription['id'],
            'status' => 'active',
            'current_period_start' => $subscription['current_period_start'],
            'current_period_end' => $subscription['current_period_end'],
        ];
    }
}
