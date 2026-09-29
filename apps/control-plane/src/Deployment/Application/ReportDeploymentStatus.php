<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use Doctrine\DBAL\Connection;

final readonly class ReportDeploymentStatus
{
    public function __construct(private Connection $db) {}

    public function ready(string $deploymentId, int $sequence): void
    {
        if ($sequence < 1) { throw new \DomainException('Invalid entitlement sequence.'); }
        $this->db->transactional(function (Connection $db) use ($deploymentId, $sequence): void {
            [$deployment, $operation] = $this->running($db, $deploymentId);
            $document = $db->fetchOne('SELECT 1 FROM commercial.deployment_entitlement_documents
                WHERE deployment_id = ? AND installation_id = ? AND sequence = ?
                  AND valid_from <= clock_timestamp()
                  AND (valid_until IS NULL OR valid_until > clock_timestamp())
                  AND convert_from(canonical_document, \'UTF8\')::jsonb->>\'deploymentStatus\' = \'active\'
                  AND sequence = (SELECT max(sequence) FROM commercial.deployment_entitlement_documents
                                  WHERE deployment_id = ?)',
                [$deploymentId, $deployment['installation_id'], $sequence, $deploymentId]);
            if ($document === false) { throw new \DomainException('Issued entitlement is unavailable.'); }
            $db->executeStatement("UPDATE commercial.deployments SET status = 'active',
                provisioning_state = 'ready', updated_at = now() WHERE id = ?", [$deploymentId]);
            $db->executeStatement("UPDATE commercial.deployment_operations SET status = 'succeeded',
                current_step = 'ready', finished_at = now(), last_error_code = NULL WHERE id = ?", [$operation['id']]);
            $db->executeStatement("INSERT INTO commercial.audit_events
                (actor_type, commercial_account_id, action, resource_type, resource_id, outcome)
                VALUES ('system', ?, 'deployment.provisioned', 'deployment', ?, 'success')",
                [$deployment['commercial_account_id'], $deploymentId]);
        });
    }

    public function failed(string $deploymentId, string $errorCode): void
    {
        if (strlen($errorCode) > 120
            || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+\z/D', $errorCode) !== 1) {
            throw new \DomainException('Invalid machine error code.');
        }
        $this->db->transactional(function (Connection $db) use ($deploymentId, $errorCode): void {
            [, $operation] = $this->running($db, $deploymentId);
            $db->executeStatement("UPDATE commercial.deployments SET status = 'failed',
                provisioning_state = 'failed', updated_at = now() WHERE id = ?", [$deploymentId]);
            $db->executeStatement("UPDATE commercial.deployment_operations SET status = 'failed',
                finished_at = now(), last_error_code = ? WHERE id = ?", [$errorCode, $operation['id']]);
        });
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function running(Connection $db, string $deploymentId): array
    {
        $deployment = $db->fetchAssociative('SELECT installation_id, commercial_account_id, status, provisioning_state, archived_at
            FROM commercial.deployments WHERE id = ? FOR UPDATE', [$deploymentId]);
        if ($deployment === false || $deployment['archived_at'] !== null
            || $deployment['status'] !== 'provisioning' || $deployment['provisioning_state'] !== 'running') {
            throw new \DomainException('Deployment is not provisioning.');
        }
        $operations = $db->fetchAllAssociative("SELECT id, status FROM commercial.deployment_operations
            WHERE deployment_id = ? AND operation_type = 'provision' ORDER BY created_at FOR UPDATE", [$deploymentId]);
        if (count($operations) !== 1 || $operations[0]['status'] !== 'running') {
            throw new \DomainException('Provision operation is not running.');
        }
        return [$deployment, $operations[0]];
    }
}
