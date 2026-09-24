<?php

declare(strict_types=1);

namespace ControlPlane\Catalog\Application;

use Doctrine\DBAL\Connection;

final readonly class PlanCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function published(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT p.id, p.code, p.version, p.name,
       COALESCE((SELECT jsonb_agg(jsonb_build_object('currency', pp.currency, 'amount_minor', pp.amount_minor, 'billing_period', pp.billing_period) ORDER BY pp.currency, pp.billing_period) FROM commercial.plan_prices pp WHERE pp.plan_id = p.id), '[]'::jsonb) AS prices,
       COALESCE((SELECT jsonb_object_agg(f.code, pf.value ORDER BY f.code) FROM commercial.plan_features pf JOIN commercial.features f ON f.id = pf.feature_id WHERE pf.plan_id = p.id), '{}'::jsonb) AS features
FROM commercial.plans p
WHERE p.status = 'published'
ORDER BY p.code, p.version DESC
SQL);

        return array_map(static function (array $row): array {
            $row['version'] = (int) $row['version'];
            $row['prices'] = json_decode((string) $row['prices'], true, 512, JSON_THROW_ON_ERROR);
            $row['features'] = json_decode((string) $row['features'], true, 512, JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }
}
