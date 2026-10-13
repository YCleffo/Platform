<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Domain\EntitlementComposition;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class EffectiveEntitlements
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{commercial_account_id: string, subscription: array<string, mixed>|null, features: array<string, mixed>|\stdClass} */
    public function forAccount(string $userId, string $accountId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        return $this->forDeploymentAccount($accountId);
    }

    /** Internal server-side composition. Callers must already hold a trusted deployment boundary.
     * @return array{commercial_account_id: string, subscription: array<string, mixed>|null, features: array<string, mixed>|\stdClass}
     */
    public function forDeploymentAccount(string $accountId): array
    {
        if (!Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Invalid commercial account identifier.');
        }
        $subscription = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, current_period_start, current_period_end, billing_status, past_due_since, grace_until
FROM commercial.subscriptions
WHERE commercial_account_id = ? AND status = 'active'
  AND ((billing_status = 'current' AND current_period_start <= now() AND now() < current_period_end)
    OR (billing_status = 'past_due' AND now() >= current_period_end AND now() < grace_until))
SQL, [$accountId]);
        if ($subscription === false) {
            return [
                'commercial_account_id' => $accountId,
                'subscription' => null,
                'features' => new \stdClass(),
            ];
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT f.code AS feature_code, pf.value::text AS feature_value
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plan_features pf ON pf.plan_id = pp.plan_id
JOIN commercial.features f ON f.id = pf.feature_id
WHERE si.subscription_id = ?
  AND si.valid_from <= now()
  AND (si.valid_until IS NULL OR now() < si.valid_until)
ORDER BY f.code, si.id
SQL, [$subscription['id']]);
        $features = EntitlementComposition::fromRows($rows);

        return [
            'commercial_account_id' => $accountId,
            'subscription' => $subscription,
            'features' => $features === [] ? new \stdClass() : $features,
        ];
    }
}
