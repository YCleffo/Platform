<?php

declare(strict_types=1);

namespace ControlPlane\Usage\Application;

use ControlPlane\M2m\DeploymentIdentity;
use Doctrine\DBAL\Connection;

final readonly class IngestDeploymentUsage
{
    public function __construct(private Connection $connection, private ApplyUsageDelta $usage) {}

    /** @return array{feature_code: string, mode: 'counter'|'gauge', used: int, limit: int, remaining: int, exhausted: bool} */
    public function apply(DeploymentIdentity $identity, string $featureCode, int $delta, string $key): array
    {
        return $this->connection->transactional(function (Connection $db) use ($identity, $featureCode, $delta, $key): array {
            $deployment = $db->fetchAssociative(<<<'SQL'
                SELECT commercial_account_id::text AS account_id, status, archived_at
                FROM commercial.deployments WHERE id = ? FOR SHARE
                SQL, [$identity->deploymentId]);
            if ($deployment === false || $deployment['status'] !== 'active' || $deployment['archived_at'] !== null) {
                throw new DeploymentUsageForbidden('Deployment is not active.');
            }
            return $this->usage->apply($deployment['account_id'], $featureCode, $delta, $key,
                UsageWriteSource::deployment($identity->deploymentId));
        });
    }
}
