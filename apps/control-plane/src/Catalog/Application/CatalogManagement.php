<?php

declare(strict_types=1);

namespace ControlPlane\Catalog\Application;

use Doctrine\DBAL\Connection;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Draft, version and publish workflow for the global Commercial catalog. */
final readonly class CatalogManagement
{
    public function __construct(private Connection $connection) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative($this->selectSql('ORDER BY p.code, p.version DESC'));
        return array_map($this->decode(...), $rows);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function createDraft(string $actorId, array $input, string $correlationId): array
    {
        $code = $this->code($input['code'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $prices = $this->prices($input['prices'] ?? []);
        $features = $this->features($input['features'] ?? []);

        return $this->connection->transactional(function (Connection $db) use ($actorId, $code, $name, $prices, $features, $correlationId): array {
            $this->lockCode($db, $code);
            $version = (int) $db->fetchOne('SELECT COALESCE(max(version), 0) + 1 FROM commercial.plans WHERE code = ?', [$code]);
            $id = (string) $db->fetchOne(
                'INSERT INTO commercial.plans (code, version, status, name) VALUES (?, ?, ?, ?) RETURNING id',
                [$code, $version, 'draft', $name],
            );
            $this->replaceTerms($db, $id, $prices, $features);
            $this->audit($db, $actorId, 'commercial.catalog.plan_drafted', $id, $correlationId);
            return $this->one($db, $id);
        });
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function updateDraft(string $actorId, string $planId, array $input, string $correlationId): array
    {
        $name = $this->name($input['name'] ?? null);
        $prices = $this->prices($input['prices'] ?? []);
        $features = $this->features($input['features'] ?? []);

        return $this->connection->transactional(function (Connection $db) use ($actorId, $planId, $name, $prices, $features, $correlationId): array {
            $plan = $this->locked($db, $planId);
            if ($plan['status'] !== 'draft') {
                throw new ConflictHttpException('Only draft plans can be edited.');
            }
            $db->executeStatement('UPDATE commercial.plans SET name = ? WHERE id = ?', [$name, $planId]);
            $this->replaceTerms($db, $planId, $prices, $features);
            $this->audit($db, $actorId, 'commercial.catalog.plan_updated', $planId, $correlationId);
            return $this->one($db, $planId);
        });
    }

    /** @return array<string, mixed> */
    public function publish(string $actorId, string $planId, string $correlationId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($actorId, $planId, $correlationId): array {
            $plan = $this->locked($db, $planId);
            if ($plan['status'] !== 'draft') {
                throw new ConflictHttpException('Only draft plans can be published.');
            }
            if ((int) $db->fetchOne('SELECT count(*) FROM commercial.plan_prices WHERE plan_id = ?', [$planId]) === 0) {
                throw new ConflictHttpException('A plan needs at least one price before publication.');
            }
            $db->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$planId]);
            $this->audit($db, $actorId, 'commercial.catalog.plan_published', $planId, $correlationId);
            return $this->one($db, $planId);
        });
    }

    /** @return array<string, mixed> */
    public function newVersion(string $actorId, string $planId, string $correlationId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($actorId, $planId, $correlationId): array {
            $source = $db->fetchAssociative('SELECT id, code, name FROM commercial.plans WHERE id = ?', [$planId]);
            if ($source === false) {
                throw new NotFoundHttpException('Catalog plan not found.');
            }
            $code = (string) $source['code'];
            $this->lockCode($db, $code);
            $version = (int) $db->fetchOne('SELECT COALESCE(max(version), 0) + 1 FROM commercial.plans WHERE code = ?', [$code]);
            $id = (string) $db->fetchOne(
                'INSERT INTO commercial.plans (code, version, status, name) VALUES (?, ?, ?, ?) RETURNING id',
                [$code, $version, 'draft', (string) $source['name']],
            );
            $db->executeStatement(
                'INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period)
                 SELECT ?, currency, amount_minor, billing_period FROM commercial.plan_prices WHERE plan_id = ?',
                [$id, $planId],
            );
            $db->executeStatement(
                'INSERT INTO commercial.plan_features (plan_id, feature_id, value)
                 SELECT ?, feature_id, value FROM commercial.plan_features WHERE plan_id = ?',
                [$id, $planId],
            );
            $this->audit($db, $actorId, 'commercial.catalog.version_drafted', $id, $correlationId);
            return $this->one($db, $id);
        });
    }

    private function selectSql(string $suffix = ''): string
    {
        $sql = <<<'SQL'
            SELECT p.id, p.code, p.version, p.status, p.name, p.created_at, p.published_at, p.retired_at,
                   COALESCE((SELECT jsonb_agg(jsonb_build_object('id', pp.id, 'currency', pp.currency, 'amount_minor', pp.amount_minor, 'billing_period', pp.billing_period) ORDER BY pp.currency, pp.billing_period) FROM commercial.plan_prices pp WHERE pp.plan_id = p.id), '[]'::jsonb) AS prices,
                   COALESCE((SELECT jsonb_object_agg(f.code, pf.value ORDER BY f.code) FROM commercial.plan_features pf JOIN commercial.features f ON f.id = pf.feature_id WHERE pf.plan_id = p.id), '{}'::jsonb) AS features
            FROM commercial.plans p
            SQL;
        return $suffix === '' ? $sql : $sql . ' ' . $suffix;
    }

    /** @return array<string, mixed> */
    private function one(Connection $db, string $planId): array
    {
        $row = $db->fetchAssociative($this->selectSql('WHERE p.id = ?'), [$planId]);
        if ($row === false) {
            throw new NotFoundHttpException('Catalog plan not found.');
        }
        return $this->decode($row);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $row['version'] = (int) $row['version'];
        $row['prices'] = json_decode((string) $row['prices'], true, 512, JSON_THROW_ON_ERROR);
        $features = json_decode((string) $row['features'], true, 512, JSON_THROW_ON_ERROR);
        $row['features'] = $features === [] ? new \stdClass() : $features;
        return $row;
    }

    /** @return array<string, mixed> */
    private function locked(Connection $db, string $planId): array
    {
        $plan = $db->fetchAssociative('SELECT id, status FROM commercial.plans WHERE id = ? FOR UPDATE', [$planId]);
        if ($plan === false) {
            throw new NotFoundHttpException('Catalog plan not found.');
        }
        return $plan;
    }

    private function lockCode(Connection $db, string $code): void
    {
        $db->executeStatement("SELECT pg_advisory_xact_lock(hashtextextended('catalog:' || CAST(? AS text), 0))", [$code]);
    }

    /** @param list<array{currency: string, amount_minor: int, billing_period: string}> $prices
     *  @param array<string, string> $features
     */
    private function replaceTerms(Connection $db, string $planId, array $prices, array $features): void
    {
        $db->executeStatement('DELETE FROM commercial.plan_prices WHERE plan_id = ?', [$planId]);
        $db->executeStatement('DELETE FROM commercial.plan_features WHERE plan_id = ?', [$planId]);
        foreach ($prices as $price) {
            $db->executeStatement(
                'INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, ?, ?, ?)',
                [$planId, $price['currency'], $price['amount_minor'], $price['billing_period']],
            );
        }
        foreach ($features as $featureId => $value) {
            $db->executeStatement(
                'INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, ?::jsonb)',
                [$planId, $featureId, $value],
            );
        }
    }

    private function audit(Connection $db, string $actorId, string $action, string $resourceId, string $correlationId): void
    {
        $db->executeStatement(
            "INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome)
             VALUES ('control_user', ?, ?, 'catalog_plan', ?, ?, 'success')",
            [$actorId, $action, $resourceId, $correlationId !== '' ? $correlationId : 'catalog-' . bin2hex(random_bytes(8))],
        );
    }

    private function code(mixed $value): string
    {
        $code = is_string($value) ? strtolower(trim($value)) : '';
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/', $code) || strlen($code) > 120) {
            throw new \InvalidArgumentException('Invalid plan code.');
        }
        return $code;
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || strlen($name) > 200 || preg_match('/[[:cntrl:]]/', $name) === 1) {
            throw new \InvalidArgumentException('Invalid plan name.');
        }
        return $name;
    }

    /** @return list<array{currency: string, amount_minor: int, billing_period: string}> */
    private function prices(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Prices must be a list.');
        }
        $prices = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Invalid price.');
            }
            $currency = is_string($item['currency'] ?? null) ? strtoupper(trim($item['currency'])) : '';
            $amount = $item['amount_minor'] ?? null;
            $period = $item['billing_period'] ?? null;
            if (!preg_match('/^[A-Z]{3}$/', $currency)
                || !is_int($amount) || $amount < 0 || $amount > 1_000_000_000_000
                || !in_array($period, ['month', 'year', 'one_time'], true)) {
                throw new \InvalidArgumentException('Invalid price.');
            }
            $key = $currency . ':' . $period;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate currency and billing period.');
            }
            $seen[$key] = true;
            $prices[] = ['currency' => $currency, 'amount_minor' => $amount, 'billing_period' => $period];
        }
        return $prices;
    }

    /** @return array<string, string> */
    private function features(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Features must be an object.');
        }
        $features = [];
        foreach ($value as $code => $featureValue) {
            if (!is_string($code) || !preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/', $code)) {
                throw new \InvalidArgumentException('Invalid feature code.');
            }
            $feature = $this->connection->fetchAssociative(
                'SELECT id, value_type, usage_mode, retired_at FROM commercial.features WHERE code = ?',
                [$code],
            );
            if ($feature === false || $feature['retired_at'] !== null) {
                throw new \InvalidArgumentException('Unknown or retired feature: ' . $code);
            }
            $type = (string) $feature['value_type'];
            $valid = match ($type) {
                'boolean' => is_bool($featureValue),
                'integer' => is_int($featureValue)
                    && ($featureValue >= 0 || $feature['usage_mode'] === 'none')
                    && $featureValue <= \PHP_INT_MAX,
                'string' => is_string($featureValue),
                'json' => $featureValue !== null,
                default => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException('Feature value type mismatch: ' . $code);
            }
            try {
                $encoded = json_encode($featureValue, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new \InvalidArgumentException('Feature value is not serializable: ' . $code);
            }
            $features[(string) $feature['id']] = $encoded;
        }
        return $features;
    }
}
