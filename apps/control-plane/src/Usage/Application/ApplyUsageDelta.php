<?php

declare(strict_types=1);

namespace ControlPlane\Usage\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use ControlPlane\Usage\Domain\Quota;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ApplyUsageDelta
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{feature_code: string, mode: 'counter'|'gauge', used: int, limit: int, remaining: int, exhausted: bool} */
    public function apply(string $commercialAccountId, string $featureCode, int $delta, string $idempotencyKey): array
    {
        if (!Uuid::isValid($commercialAccountId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $featureCode = IdentifierPolicy::machineCode($featureCode, 160);
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        if ($delta === 0) {
            throw new \InvalidArgumentException('Usage delta must not be zero.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($commercialAccountId, $featureCode, $delta, $idempotencyKey): array {
            $subscription = $connection->fetchAssociative(<<<'SQL'
SELECT id, current_period_start, current_period_end
FROM commercial.subscriptions
WHERE commercial_account_id = ? AND status = 'active'
  AND current_period_start <= now() AND now() < current_period_end
FOR UPDATE
SQL, [$commercialAccountId]);
            if ($subscription === false || !is_string($subscription['id'])) {
                throw new \DomainException('A current active subscription is required.');
            }
            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            $existingScopes = $connection->fetchFirstColumn('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ?', [$idempotencyKey]);
            $period = $connection->fetchAssociative(<<<'SQL'
SELECT id, starts_at, ends_at
FROM commercial.usage_periods
WHERE subscription_id = ? AND status = 'open'
  AND starts_at = ?::timestamptz AND ends_at = ?::timestamptz
FOR UPDATE
SQL, [$subscription['id'], $subscription['current_period_start'], $subscription['current_period_end']]);
            if ($period === false || !is_string($period['id'])) {
                throw new \LogicException('Current usage period is unavailable.');
            }

            $rows = $connection->fetchAllAssociative(<<<'SQL'
SELECT f.id AS feature_id, f.code AS feature_code, f.usage_mode, pf.value::text AS limit_value
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plan_features pf ON pf.plan_id = pp.plan_id
JOIN commercial.features f ON f.id = pf.feature_id
WHERE si.subscription_id = ? AND f.code = ?
  AND si.valid_from <= now() AND (si.valid_until IS NULL OR now() < si.valid_until)
ORDER BY si.id
SQL, [$subscription['id'], $featureCode]);
            if ($rows === []) {
                throw new \DomainException('Feature is not an effective subscription entitlement.');
            }
            $featureId = null;
            $mode = null;
            $limit = null;
            foreach ($rows as $row) {
                if (!is_string($row['feature_id']) || !is_string($row['usage_mode'])) {
                    throw new \LogicException('Metered feature identity is invalid.');
                }
                if (!in_array($row['usage_mode'], ['counter', 'gauge'], true)) {
                    throw new \DomainException('Feature is not metered.');
                }
                $rowLimit = Quota::nonNegativeBigInt($row['limit_value']);
                if (($featureId !== null && $featureId !== $row['feature_id']) || ($mode !== null && $mode !== $row['usage_mode']) || ($limit !== null && $limit !== $rowLimit)) {
                    throw new \DomainException('Ambiguous metered entitlement composition.');
                }
                $featureId = $row['feature_id'];
                $mode = $row['usage_mode'];
                $limit = $rowLimit;
            }
            if ($mode === 'counter' && $delta < 0) {
                throw new \DomainException('Counter usage can only increase.');
            }

            $scope = 'usage.apply.' . str_replace('-', '', $period['id']) . '.' . str_replace('-', '', $featureId);
            foreach ($existingScopes as $existingScope) {
                if (!is_string($existingScope) || $existingScope !== $scope) {
                    throw new \DomainException('Idempotency key was reused for a different request.');
                }
            }
            $hash = hash('sha256', json_encode([
                'operation' => 'usage.apply',
                'commercial_account_id' => strtolower($commercialAccountId),
                'usage_period_id' => strtolower($period['id']),
                'feature_id' => strtolower($featureId),
                'feature_code' => $featureCode,
                'delta' => $delta,
            ], JSON_THROW_ON_ERROR));
            $inserted = $connection->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', NULL) ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$scope, $idempotencyKey, $hash],
            );
            $idempotency = $connection->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($idempotency === false || !is_string($idempotency['request_hash'])) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!hash_equals($idempotency['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($idempotency['status'] !== 'completed') {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }
                $used = Quota::nonNegativeBigInt($idempotency['result_reference']);

                return $this->result($featureCode, $mode, $used, $limit);
            }

            $connection->executeStatement('INSERT INTO commercial.usage_counters (usage_period_id, feature_id, quantity) VALUES (?, ?, 0) ON CONFLICT (usage_period_id, feature_id) DO NOTHING', [$period['id'], $featureId]);
            $currentValue = $connection->fetchOne('SELECT quantity FROM commercial.usage_counters WHERE usage_period_id = ? AND feature_id = ? FOR UPDATE', [$period['id'], $featureId]);
            $current = Quota::nonNegativeBigInt($currentValue);
            $newQuantity = Quota::add($current, $delta);
            if ($newQuantity > $limit) {
                throw new \DomainException('Usage quota would be exceeded.');
            }
            $connection->executeStatement('UPDATE commercial.usage_counters SET quantity = ?, updated_at = now() WHERE usage_period_id = ? AND feature_id = ?', [$newQuantity, $period['id'], $featureId]);
            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [(string) $newQuantity, $scope, $idempotencyKey]);

            return $this->result($featureCode, $mode, $newQuantity, $limit);
        });
    }

    /** @param 'counter'|'gauge' $mode
     * @return array{feature_code: string, mode: 'counter'|'gauge', used: int, limit: int, remaining: int, exhausted: bool}
     */
    private function result(string $featureCode, string $mode, int $used, int $limit): array
    {
        if ($used > $limit) {
            throw new \LogicException('Stored usage exceeds its entitlement limit.');
        }

        return [
            'feature_code' => $featureCode,
            'mode' => $mode,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit - $used,
            'exhausted' => $used >= $limit,
        ];
    }
}
