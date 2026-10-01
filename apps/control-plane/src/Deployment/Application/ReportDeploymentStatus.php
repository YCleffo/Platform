<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentIdentity;
use Doctrine\DBAL\Connection;

final readonly class ReportDeploymentStatus
{
    public function __construct(private Connection $db, private DeploymentCredentialService $credentials) {}

    public function ready(DeploymentIdentity $identity, int $sequence): string
    {
        if ($sequence < 1) { throw new \DomainException('Invalid entitlement sequence.'); }
        return $this->db->transactional(function (Connection $db) use ($identity, $sequence): string {
            $deploymentId = $identity->deploymentId;
            $deployment = $db->fetchAssociative('SELECT installation_id, commercial_account_id, status,
                provisioning_state, archived_at FROM commercial.deployments WHERE id = ? FOR UPDATE', [$deploymentId]);
            if ($deployment === false || $deployment['archived_at'] !== null) {
                throw new \DomainException('Deployment is unavailable.');
            }
            $operations = $db->fetchAllAssociative("SELECT id, status, finished_at FROM commercial.deployment_operations
                WHERE deployment_id = ? AND operation_type = 'provision' ORDER BY created_at FOR UPDATE", [$deploymentId]);
            if (count($operations) !== 1) { throw new \DomainException('Provision operation is unavailable.'); }
            $operation = $operations[0];
            $credential = $db->fetchAssociative(<<<'SQL'
                SELECT array_to_json(scopes)::text AS scopes_json
                FROM commercial.service_credentials WHERE deployment_id = ? AND credential_id = ?
                    AND status = 'active' AND (expires_at IS NULL OR expires_at > now()) FOR UPDATE
                SQL, [$deploymentId, $identity->credentialId]);
            if ($credential === false) { throw new \DomainException('Deployment credential is unavailable.'); }
            $scopes = json_decode($credential['scopes_json'], true, flags: JSON_THROW_ON_ERROR);
            $document = $db->fetchOne('SELECT 1 FROM commercial.deployment_entitlement_documents
                WHERE deployment_id = ? AND installation_id = ? AND sequence = ?
                  AND valid_from <= clock_timestamp()
                  AND (valid_until IS NULL OR valid_until > clock_timestamp())
                  AND convert_from(canonical_document, \'UTF8\')::jsonb->>\'deploymentStatus\' = \'active\'
                  AND sequence = (SELECT max(sequence) FROM commercial.deployment_entitlement_documents
                                  WHERE deployment_id = ?)',
                [$deploymentId, $deployment['installation_id'], $sequence, $deploymentId]);
            if ($document === false) { throw new \DomainException('Issued entitlement is unavailable.'); }
            if ($deployment['status'] === 'active' && $deployment['provisioning_state'] === 'ready'
                && $operation['status'] === 'succeeded' && $operation['finished_at'] !== null
                && $scopes === ['entitlements.read', 'usage.write']) {
                return 'already_ready';
            }
            if ($deployment['status'] !== 'provisioning' || $deployment['provisioning_state'] !== 'running'
                || $operation['status'] !== 'running'
                || !in_array('deployment.status.write', $scopes, true)
                || !in_array('entitlements.read', $scopes, true)
                || !in_array('usage.write', $scopes, true)) {
                throw new \DomainException('Deployment is not provisioning.');
            }
            $this->credentials->restrictScopes($deploymentId, $identity->credentialId,
                ['entitlements.read', 'usage.write']);
            // current_release_id is the release that has actually completed activation.
            // Do not advance it while a runtime is merely being provisioned.
            $db->executeStatement("UPDATE commercial.deployments SET status = 'active',
                provisioning_state = 'ready', current_release_id = desired_release_id,
                updated_at = now() WHERE id = ?", [$deploymentId]);
            $db->executeStatement("UPDATE commercial.deployment_operations SET status = 'succeeded',
                current_step = 'ready', finished_at = now(), last_error_code = NULL WHERE id = ?", [$operation['id']]);
            $db->executeStatement("INSERT INTO commercial.audit_events
                (actor_type, commercial_account_id, action, resource_type, resource_id, outcome)
                VALUES ('system', ?, 'deployment.provisioned', 'deployment', ?, 'success')",
                [$deployment['commercial_account_id'], $deploymentId]);
            return 'ready';
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
