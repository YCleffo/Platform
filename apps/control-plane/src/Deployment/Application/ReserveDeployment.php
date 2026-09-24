<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use ControlPlane\Deployment\Domain\DomainName;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;

final readonly class ReserveDeployment
{
    public function __construct(private Connection $connection, private string $baseDomain)
    {
    }

    public function reserveCloud(string $accountId, string $mode, string $slug, string $operationKey): string
    {
        if (!in_array($mode, ['shared', 'dedicated'], true)) {
            throw new \InvalidArgumentException('Cloud mode must be shared or dedicated.');
        }
        $slug = IdentifierPolicy::slug($slug);
        $hostname = DomainName::normalize($slug . '.' . DomainName::normalize($this->baseDomain));
        $operationKey = IdentifierPolicy::opaqueKey($operationKey);

        return $this->connection->transactional(function (Connection $connection) use ($accountId, $mode, $slug, $hostname, $operationKey): string {
            $deploymentId = $connection->fetchOne(
                "INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), ?, ?) RETURNING id",
                [$accountId, $mode, $slug],
            );
            if (!is_string($deploymentId)) {
                throw new \LogicException('Deployment ID unavailable.');
            }
            $connection->executeStatement(
                "INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname, status) VALUES (?, 'system', ?, 'active')",
                [$deploymentId, $hostname],
            );
            $connection->executeStatement(
                "INSERT INTO commercial.deployment_operations (deployment_id, operation_type, idempotency_key) VALUES (?, 'provision', ?)",
                [$deploymentId, $operationKey],
            );
            $connection->executeStatement(
                "INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, outcome) VALUES ('system', ?, 'deployment.reserved', 'deployment', ?, 'success')",
                [$accountId, $deploymentId],
            );
            $connection->executeStatement(
                "INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('deployment.provision_requested', 'deployment', ?, jsonb_build_object('deployment_id', CAST(? AS text)))",
                [$deploymentId, $deploymentId],
            );

            return $deploymentId;
        });
    }
}
