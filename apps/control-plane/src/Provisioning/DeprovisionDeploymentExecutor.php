<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Doctrine\DBAL\Connection;

final readonly class DeprovisionDeploymentExecutor
{
    public function __construct(private Connection $db, private DeploymentInfrastructureProvider $provider,
        private ProvisioningSecretStore $secrets, private DeploymentBackupEvidenceReader $backups,
        private string $environment, private int $maxAttempts, private int $retryBaseSeconds,
        private int $backupCheckIntervalSeconds) {}

    public function runBatch(int $limit): int
    {
        if ($limit < 1 || $limit > 20 || $this->maxAttempts < 1 || $this->maxAttempts > 20
            || $this->retryBaseSeconds < 1 || $this->retryBaseSeconds > 3600
            || $this->backupCheckIntervalSeconds < 5 || $this->backupCheckIntervalSeconds > 3600) {
            throw new \InvalidArgumentException('Invalid deprovision bounds.');
        }
        $this->recoverStale();
        $ids = $this->db->fetchFirstColumn("SELECT id FROM commercial.deployment_operations
            WHERE operation_type = 'deprovision' AND (status = 'pending'
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
        try {
            $row = $this->db->transactional(function (Connection $db) use ($operationId): ?array {
                $row = $db->fetchAssociative("SELECT o.id, o.deployment_id, o.attempt_count,
                    o.started_at, d.installation_id, d.commercial_account_id, d.mode,
                    d.status AS deployment_status, d.archived_at
                    FROM commercial.deployment_operations o
                    JOIN commercial.deployments d ON d.id = o.deployment_id
                    WHERE o.id = ? AND o.operation_type = 'deprovision'
                      AND (o.status = 'pending' OR (o.status = 'retry' AND o.next_retry_at <= now()))
                    FOR UPDATE OF o, d SKIP LOCKED", [$operationId]);
                if ($row === false) { return null; }
                if (!in_array($this->environment, ['dev', 'test'], true)
                    || (getenv('PROVISIONER_PROVIDER') ?: 'local-docker') !== 'local-docker') {
                    throw new ProvisioningFailure('infrastructure.provider_not_allowed');
                }
                if ($row['mode'] !== 'dedicated' || $row['deployment_status'] !== 'suspended'
                    || $row['archived_at'] !== null) {
                    throw new ProvisioningFailure('infrastructure.deployment_ineligible');
                }
                $db->executeStatement("UPDATE commercial.deployment_operations
                    SET status = 'running', current_step = COALESCE(current_step, 'quiesce'),
                        attempt_count = attempt_count + 1,
                        next_retry_at = clock_timestamp() + interval '10 minutes', last_error_code = NULL
                    WHERE id = ?", [$operationId]);
                return $row;
            });
            if ($row === null) { return false; }
            $id = (string) $row['deployment_id'];
            $installation = (string) $row['installation_id'];
            $this->step($operationId, 'quiesce');
            $this->provider->quiesce($id);
            $this->step($operationId, 'final_backup');
            $started = new \DateTimeImmutable((string) $this->db->fetchOne(
                'SELECT started_at::text FROM commercial.deployment_operations WHERE id = ?', [$operationId]));
            if (!$this->backups->hasFinalBackup($id, $installation, $started)) {
                throw new ProvisioningFailure('infrastructure.awaiting_final_backup', true);
            }
            $this->step($operationId, 'infrastructure_destroy');
            $this->provider->decommission($id);
            $this->assertAbsent($id);
            $this->step($operationId, 'secret_cleanup');
            $this->secrets->cleanup($id);
            if (!$this->secrets->isClean($id)) {
                throw new ProvisioningFailure('infrastructure.secret_storage_failed', true);
            }
            $this->step($operationId, 'finalize');
            if (!$this->backups->hasFinalBackup($id, $installation, $started)) {
                throw new ProvisioningFailure('infrastructure.backup_evidence_unavailable', true);
            }
            $this->assertAbsent($id);
            $this->db->transactional(function (Connection $db) use ($operationId, $row): void {
                $id = (string) $row['deployment_id'];
                $state = $db->fetchAssociative("SELECT d.status, d.archived_at, o.status AS operation_status
                    FROM commercial.deployments d JOIN commercial.deployment_operations o ON o.deployment_id = d.id
                    WHERE o.id = ? FOR UPDATE OF d, o", [$operationId]);
                if ($state === false || $state['status'] !== 'suspended' || $state['archived_at'] !== null
                    || $state['operation_status'] !== 'running'
                    || (int) $db->fetchOne("SELECT count(*) FROM commercial.service_credentials
                        WHERE deployment_id = ? AND status = 'active'", [$id]) !== 0) {
                    throw new ProvisioningFailure('infrastructure.deployment_ineligible');
                }
                $db->executeStatement("UPDATE commercial.deployment_domains
                    SET status = 'disabled', disabled_at = COALESCE(disabled_at, now())
                    WHERE deployment_id = ? AND status <> 'disabled'", [$id]);
                $db->executeStatement("UPDATE commercial.deployments SET status = 'decommissioned',
                    provisioning_state = 'not_applicable', archived_at = now(), updated_at = now(),
                    core_db_secret_ref = NULL WHERE id = ?", [$id]);
                $db->executeStatement("UPDATE commercial.deployment_operations SET status = 'succeeded',
                    current_step = 'decommissioned', finished_at = now(), next_retry_at = NULL,
                    last_error_code = NULL WHERE id = ?", [$operationId]);
                $db->executeStatement("INSERT INTO commercial.audit_events
                    (actor_type, commercial_account_id, action, resource_type, resource_id, outcome, metadata)
                    VALUES ('system', ?, 'deployment.decommissioned', 'deployment', ?, 'success',
                        jsonb_build_object('deploymentId', ?::text, 'installationId', ?::text, 'operationId', ?::text))",
                    [$row['commercial_account_id'], $id, $id, $row['installation_id'], $operationId]);
            });
            return true;
        } catch (ProvisioningFailure $failure) {
            $this->recordFailure($operationId, $failure);
            return true;
        } catch (\Throwable) {
            $this->recordFailure($operationId, new ProvisioningFailure('infrastructure.unavailable', true));
            return true;
        }
    }

    private function step(string $operationId, string $step): void
    {
        $order = ['quiesce' => 1, 'final_backup' => 2, 'infrastructure_destroy' => 3,
            'secret_cleanup' => 4, 'finalize' => 5];
        $current = $this->db->fetchOne("SELECT current_step FROM commercial.deployment_operations
            WHERE id = ? AND status = 'running'", [$operationId]);
        if (!is_string($current) || !isset($order[$current]) || !isset($order[$step])) {
            throw new ProvisioningFailure('infrastructure.deployment_ineligible');
        }
        if ($order[$current] >= $order[$step]) { return; }
        if ($step === 'final_backup') {
            $this->db->executeStatement("UPDATE commercial.deployment_operations
                SET current_step = 'final_backup', started_at = COALESCE(started_at, clock_timestamp())
                WHERE id = ? AND status = 'running'", [$operationId]);
        } else {
            $this->db->executeStatement("UPDATE commercial.deployment_operations SET current_step = ?
                WHERE id = ? AND status = 'running'", [$step, $operationId]);
        }
    }

    private function assertAbsent(string $deploymentId): void
    {
        if ($this->provider->status($deploymentId) !== ['runtime' => false, 'database' => false, 'network' => false]) {
            throw new ProvisioningFailure('infrastructure.cleanup_failed', true);
        }
    }

    private function recordFailure(string $operationId, ProvisioningFailure $failure): void
    {
        $this->db->transactional(function (Connection $db) use ($operationId, $failure): void {
            $row = $db->fetchAssociative("SELECT status, attempt_count, current_step
                FROM commercial.deployment_operations WHERE id = ? FOR UPDATE", [$operationId]);
            if ($row === false || in_array($row['status'], ['succeeded', 'failed'], true)) { return; }
            $awaiting = $failure->machineCode === 'infrastructure.awaiting_final_backup';
            $retry = $awaiting || ($failure->retryable && (int) $row['attempt_count'] < $this->maxAttempts);
            $delay = $awaiting ? $this->backupCheckIntervalSeconds
                : min(3600, $this->retryBaseSeconds * (2 ** min(10, (int) $row['attempt_count'])));
            $db->executeStatement("UPDATE commercial.deployment_operations SET status = ?,
                current_step = CASE WHEN ?::boolean THEN 'final_backup' ELSE current_step END,
                last_error_code = ?, next_retry_at = CASE WHEN ?::boolean
                    THEN now() + (? * interval '1 second') ELSE NULL END,
                finished_at = CASE WHEN ?::boolean THEN NULL ELSE now() END
                WHERE id = ?", [$retry ? 'retry' : 'failed', $awaiting ? 'true' : 'false',
                    $failure->machineCode, $retry ? 'true' : 'false', $delay,
                    $retry ? 'true' : 'false', $operationId]);
        });
    }

    private function recoverStale(): void
    {
        $this->db->executeStatement("UPDATE commercial.deployment_operations SET status = 'retry',
            next_retry_at = now(), last_error_code = 'infrastructure.worker_interrupted'
            WHERE operation_type = 'deprovision' AND status = 'running'
              AND next_retry_at <= now()");
    }
}
