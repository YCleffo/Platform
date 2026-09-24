<?php

declare(strict_types=1);

namespace ControlPlane\Usage\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Usage\Domain\Quota;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CurrentUsage
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{commercial_account_id: string, subscription: array<string, mixed>|null, period: array<string, mixed>|null, counters: array<string, mixed>|\stdClass} */
    public function forAccount(string $userId, string $commercialAccountId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($commercialAccountId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($userId, $commercialAccountId, 'account.read');
        $subscription = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, current_period_start, current_period_end
FROM commercial.subscriptions
WHERE commercial_account_id = ? AND status = 'active'
  AND current_period_start <= now() AND now() < current_period_end
SQL, [$commercialAccountId]);
        if ($subscription === false) {
            return $this->empty($commercialAccountId);
        }
        $period = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, starts_at, ends_at, status
FROM commercial.usage_periods
WHERE subscription_id = ? AND status = 'open'
  AND starts_at = ?::timestamptz AND ends_at = ?::timestamptz
SQL, [$subscription['id'], $subscription['current_period_start'], $subscription['current_period_end']]);
        if ($period === false) {
            throw new \LogicException('Current usage period is unavailable.');
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT f.id AS feature_id, f.code AS feature_code, f.usage_mode, pf.value::text AS limit_value,
       COALESCE(uc.quantity, 0) AS used
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plan_features pf ON pf.plan_id = pp.plan_id
JOIN commercial.features f ON f.id = pf.feature_id
LEFT JOIN commercial.usage_counters uc ON uc.usage_period_id = ? AND uc.feature_id = f.id
WHERE si.subscription_id = ? AND f.usage_mode IN ('counter','gauge')
  AND si.valid_from <= now() AND (si.valid_until IS NULL OR now() < si.valid_until)
ORDER BY f.code, si.id
SQL, [$period['id'], $subscription['id']]);

        $counters = [];
        foreach ($rows as $row) {
            if (!is_string($row['feature_code']) || !is_string($row['usage_mode']) || !in_array($row['usage_mode'], ['counter', 'gauge'], true)) {
                throw new \LogicException('Metered usage row is invalid.');
            }
            $limit = Quota::nonNegativeBigInt($row['limit_value']);
            $used = Quota::nonNegativeBigInt($row['used']);
            if ($used > $limit) {
                throw new \LogicException('Stored usage exceeds its entitlement limit.');
            }
            $entry = [
                'mode' => $row['usage_mode'],
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit - $used,
                'exhausted' => $used >= $limit,
            ];
            if (isset($counters[$row['feature_code']]) && $counters[$row['feature_code']] !== $entry) {
                throw new \DomainException('Ambiguous metered entitlement composition.');
            }
            $counters[$row['feature_code']] = $entry;
        }
        ksort($counters);

        return [
            'commercial_account_id' => $commercialAccountId,
            'subscription' => $subscription,
            'period' => $period,
            'counters' => $counters === [] ? new \stdClass() : $counters,
        ];
    }

    /** @return array{commercial_account_id: string, subscription: null, period: null, counters: \stdClass} */
    private function empty(string $commercialAccountId): array
    {
        return [
            'commercial_account_id' => $commercialAccountId,
            'subscription' => null,
            'period' => null,
            'counters' => new \stdClass(),
        ];
    }
}
