<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class RequestDeploymentDecommission
{
    public function __construct(private Connection $db, private string $environment) {}

    public function request(string $deploymentId, string $installationId, string $idempotencyKey): string
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new \DomainException('Local decommission is unavailable.');
        }
        if (!Uuid::isValid($deploymentId) || !Uuid::isValid($installationId)) {
            throw new \InvalidArgumentException('Invalid deployment or installation ID.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);

        return $this->db->transactional(function (Connection $db) use ($deploymentId, $installationId, $idempotencyKey): string {
            $deployment = $db->fetchAssociative('SELECT id, installation_id, commercial_account_id, mode,
                status, archived_at FROM commercial.deployments WHERE id = ? FOR UPDATE', [$deploymentId]);
            if ($deployment === false || $deployment['mode'] !== 'dedicated'
                || $deployment['installation_id'] !== $installationId) {
                throw new \DomainException('Deployment is unavailable or installation confirmation differs.');
            }
            $operations = $db->fetchAllAssociative("SELECT id, idempotency_key, status
                FROM commercial.deployment_operations WHERE deployment_id = ? AND operation_type = 'deprovision'
                ORDER BY created_at FOR UPDATE", [$deploymentId]);
            foreach ($operations as $operation) {
                if ($operation['idempotency_key'] === $idempotencyKey) { return (string) $operation['id']; }
            }
            $busy = $db->fetchOne("SELECT 1 FROM commercial.deployment_operations WHERE deployment_id = ?
                AND operation_type IN ('update','deprovision') AND status IN ('pending','running','retry')", [$deploymentId]);
            if ($busy !== false) { throw new \DomainException('Another deployment operation is in progress.'); }
            if ($deployment['archived_at'] !== null
                || !in_array($deployment['status'], ['active', 'suspended'], true)) {
                throw new \DomainException('Deployment is not eligible for decommission.');
            }
            foreach ($operations as $operation) {
                if (in_array($operation['status'], ['pending', 'running', 'retry'], true)) {
                    throw new \DomainException('Decommission is already in progress.');
                }
            }
            $operationId = $db->fetchOne("INSERT INTO commercial.deployment_operations
                (deployment_id, operation_type, idempotency_key, status, current_step)
                VALUES (?, 'deprovision', ?, 'pending', 'quiesce') RETURNING id",
                [$deploymentId, $idempotencyKey]);
            if (!is_string($operationId)) { throw new \LogicException('Operation ID unavailable.'); }
            $db->executeStatement("UPDATE commercial.deployments SET status = 'suspended', updated_at = now() WHERE id = ?",
                [$deploymentId]);
            $db->executeStatement("UPDATE commercial.service_credentials SET status = 'revoked', revoked_at = now()
                WHERE deployment_id = ? AND status = 'active'", [$deploymentId]);
            $db->executeStatement("INSERT INTO commercial.audit_events
                (actor_type, commercial_account_id, action, resource_type, resource_id, outcome, metadata)
                VALUES ('system', ?, 'deployment.decommission_requested', 'deployment', ?, 'success',
                    jsonb_build_object('deploymentId', ?::text, 'installationId', ?::text, 'operationId', ?::text))",
                [$deployment['commercial_account_id'], $deploymentId, $deploymentId, $installationId, $operationId]);
            return $operationId;
        });
    }
}
