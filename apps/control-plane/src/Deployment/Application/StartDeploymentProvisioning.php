<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use ControlPlane\M2m\DeploymentCredentialService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class StartDeploymentProvisioning
{
    public function __construct(private Connection $db, private DeploymentCredentialService $credentials) {}

    /** @return array{installationId:string,credentialId:string,credentialSecret:string,scopes:list<string>} */
    public function start(string $deploymentId): array
    {
        if (!Uuid::isValid($deploymentId)) { throw new \DomainException('Invalid deployment ID.'); }
        return $this->db->transactional(function (Connection $db) use ($deploymentId): array {
            $deployment = $db->fetchAssociative('SELECT id, installation_id, mode, status, provisioning_state, archived_at
                FROM commercial.deployments WHERE id = ? FOR UPDATE', [$deploymentId]);
            if ($deployment === false || !in_array($deployment['mode'], ['shared', 'dedicated'], true)
                || $deployment['archived_at'] !== null
                || !in_array($deployment['status'], ['reserved', 'failed'], true)
                || !in_array($deployment['provisioning_state'], ['pending', 'failed'], true)) {
                throw new \DomainException('Provisioning already started or deployment is ineligible.');
            }
            $operations = $db->fetchAllAssociative("SELECT id, status, attempt_count
                FROM commercial.deployment_operations WHERE deployment_id = ? AND operation_type = 'provision'
                ORDER BY created_at FOR UPDATE", [$deploymentId]);
            if (count($operations) !== 1 || !in_array($operations[0]['status'], ['pending', 'retry'], true)) {
                throw new \DomainException('Provision operation is unavailable.');
            }
            $scopes = ['entitlements.read', 'usage.write', 'deployment.status.write'];
            $issued = $this->credentials->create($deploymentId, $scopes);
            if ($issued === null) { throw new \DomainException('Bootstrap credential is unavailable.'); }
            $db->executeStatement("UPDATE commercial.deployments
                SET status = 'provisioning', provisioning_state = 'running', updated_at = now()
                WHERE id = ?", [$deploymentId]);
            $db->executeStatement("UPDATE commercial.deployment_operations
                SET status = 'running', current_step = 'runtime_bootstrap', started_at = now(),
                    finished_at = NULL, attempt_count = attempt_count + 1, next_retry_at = NULL,
                    last_error_code = NULL WHERE id = ?", [$operations[0]['id']]);
            return ['installationId' => $deployment['installation_id'],
                'credentialId' => $issued['credential']['credentialId'],
                'credentialSecret' => $issued['secret'], 'scopes' => $scopes];
        });
    }
}
