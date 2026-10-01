<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use Doctrine\DBAL\Connection;

final readonly class ProvisionDeploymentExecutor
{
    public function __construct(private Connection $db, private StartDeploymentProvisioning $start,
        private DeploymentInfrastructureProvider $provider, private ProvisioningSecretStore $secrets,
        private string $environment, private int $maxAttempts, private int $retryBaseSeconds,
        private ?ProvisioningRequestFactory $requestFactory = null) {}

    public function runBatch(int $limit): int
    {
        if ($limit < 1 || $limit > 20 || $this->maxAttempts < 1 || $this->maxAttempts > 20
            || $this->retryBaseSeconds < 1 || $this->retryBaseSeconds > 3600) {
            throw new \InvalidArgumentException('Invalid provisioner bounds.');
        }
        $this->recoverStale();
        $ids = $this->db->fetchFirstColumn("SELECT id FROM commercial.deployment_operations
            WHERE operation_type = 'provision' AND (status = 'pending'
                OR (status = 'retry' AND next_retry_at <= now()))
            ORDER BY created_at LIMIT ?", [$limit]);
        $processed = 0;
        foreach ($ids as $id) {
            if ($this->process((string) $id)) { ++$processed; }
        }
        return $processed;
    }

    private function process(string $operationId): bool
    {
        $deploymentId = null;
        try {
            $claimed = $this->db->transactional(function (Connection $db) use ($operationId): ?array {
                $row = $db->fetchAssociative("SELECT o.id, o.deployment_id, o.attempt_count,
                    d.installation_id, d.mode, d.status AS deployment_status, d.provisioning_state,
                    d.archived_at, d.desired_release_id,
                    r.version, r.channel, r.status AS release_status, r.oci_reference, r.image_digest
                    FROM commercial.deployment_operations o
                    JOIN commercial.deployments d ON d.id = o.deployment_id
                    LEFT JOIN commercial.releases r ON r.id = d.desired_release_id
                    WHERE o.id = ? AND o.operation_type = 'provision'
                      AND (o.status = 'pending' OR (o.status = 'retry' AND o.next_retry_at <= now()))
                    FOR UPDATE OF o, d SKIP LOCKED", [$operationId]);
                if ($row === false) { return null; }
                if (!in_array($this->environment, ['dev', 'test'], true)
                    || (getenv('PROVISIONER_PROVIDER') ?: 'local-docker') !== 'local-docker') {
                    throw new ProvisioningFailure('infrastructure.provider_not_allowed');
                }
                if ($row['mode'] !== 'dedicated') {
                    throw new ProvisioningFailure('infrastructure.mode_not_supported');
                }
                if ($row['archived_at'] !== null || !in_array($row['deployment_status'], ['reserved', 'failed'], true)
                    || !in_array($row['provisioning_state'], ['pending', 'failed'], true)) {
                    throw new ProvisioningFailure('infrastructure.deployment_ineligible');
                }
                if ($row['desired_release_id'] === null || $row['release_status'] !== 'published'
                    || $row['oci_reference'] === null || $row['image_digest'] === null
                    || !preg_match('/\Asha256:[0-9a-f]{64}\z/D', (string) $row['image_digest'])) {
                    throw new ProvisioningFailure('infrastructure.release_unavailable');
                }
                if ((int) $row['attempt_count'] >= $this->maxAttempts) {
                    throw new ProvisioningFailure('infrastructure.attempts_exhausted');
                }
                $credential = $this->start->start((string) $row['deployment_id']);
                return [$row, $credential];
            });
            if ($claimed === null) { return false; }
            [$row, $credential] = $claimed;
            $deploymentId = (string) $row['deployment_id'];
            $stored = $this->secrets->prepare($deploymentId,
                $credential['credentialId'] . '.' . $credential['credentialSecret']);
            $this->db->executeStatement('UPDATE commercial.deployments SET core_db_secret_ref = ? WHERE id = ?',
                [$stored['reference'], $deploymentId]);
            $request = ($this->requestFactory ?? new ProvisioningRequestFactory($this->db))->create($row, $stored);
            $this->db->executeStatement("UPDATE commercial.deployment_operations SET current_step = 'infrastructure'
                WHERE id = ? AND status = 'running'", [$operationId]);
            $this->provider->provision($request);
            $this->db->executeStatement("UPDATE commercial.deployment_operations SET current_step = 'activation'
                WHERE id = ? AND status = 'running'", [$operationId]);
            $this->provider->activate($request);
            $state = $this->db->fetchAssociative('SELECT d.status, d.provisioning_state, o.status AS operation_status
                FROM commercial.deployments d JOIN commercial.deployment_operations o ON o.deployment_id = d.id
                WHERE o.id = ?', [$operationId]);
            if ($state === false || $state['status'] !== 'active' || $state['provisioning_state'] !== 'ready'
                || $state['operation_status'] !== 'succeeded') {
                throw new ProvisioningFailure('infrastructure.activation_incomplete', true);
            }
            return true;
        } catch (ProvisioningFailure $failure) {
            $this->recordFailure($operationId, $deploymentId, $failure);
            return true;
        } catch (\Throwable) {
            $this->recordFailure($operationId, $deploymentId,
                new ProvisioningFailure('infrastructure.unavailable', true));
            return true;
        }
    }

    private function recordFailure(string $operationId, ?string $deploymentId, ProvisioningFailure $failure): void
    {
        $retry = $this->db->transactional(function (Connection $db) use ($operationId, $failure): array {
            $row = $db->fetchAssociative("SELECT o.attempt_count, o.deployment_id, o.status,
                d.status AS deployment_status FROM commercial.deployment_operations o
                JOIN commercial.deployments d ON d.id = o.deployment_id
                WHERE o.id = ? FOR UPDATE OF o, d", [$operationId]);
            if ($row === false || $row['status'] === 'succeeded'
                || $row['deployment_status'] === 'active') { return [false, null]; }
            if ($row['status'] === 'failed') {
                return [false, (string) $row['deployment_id']];
            }
            $retryable = $failure->retryable && (int) $row['attempt_count'] < $this->maxAttempts;
            $delay = min(3600, $this->retryBaseSeconds * (2 ** min(10, (int) $row['attempt_count'])));
            $db->executeStatement("UPDATE commercial.deployments SET status = 'failed',
                provisioning_state = 'failed', updated_at = now() WHERE id = ?", [$row['deployment_id']]);
            $db->executeStatement("UPDATE commercial.deployment_operations SET status = ?,
                current_step = 'failed', last_error_code = ?,
                next_retry_at = CASE WHEN ?::boolean THEN now() + (? * interval '1 second') ELSE NULL END,
                finished_at = now() WHERE id = ?",
                [$retryable ? 'retry' : 'failed', $failure->machineCode,
                    $retryable ? 'true' : 'false', $delay, $operationId]);
            $db->executeStatement("UPDATE commercial.service_credentials SET status = 'revoked', revoked_at = now()
                WHERE deployment_id = ? AND status = 'active'
                AND scopes @> ARRAY['deployment.status.write']::text[]", [$row['deployment_id']]);
            return [$retryable, (string) $row['deployment_id']];
        });
        if (!$retry[0] && $retry[1] !== null) {
            try { $this->provider->cleanup($retry[1]); $this->secrets->cleanup($retry[1]); }
            catch (\Throwable) { /* Retain owned resources for a later operator review. */ }
        }
    }

    private function recoverStale(): void
    {
        $rows = $this->db->fetchAllAssociative("SELECT o.id FROM commercial.deployment_operations o
            JOIN commercial.deployments d ON d.id = o.deployment_id
            WHERE o.operation_type = 'provision' AND o.status = 'running'
              AND d.status = 'provisioning' AND o.started_at < now() - interval '10 minutes'
            ORDER BY o.started_at LIMIT 20");
        foreach ($rows as $row) {
            $this->recordFailure((string) $row['id'], null,
                new ProvisioningFailure('infrastructure.worker_interrupted', true));
        }
    }
}
