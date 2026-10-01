<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Doctrine\DBAL\Connection;

final readonly class UpdateDeploymentExecutor
{
    public function __construct(private Connection $db, private DeploymentInfrastructureProvider $provider,
        private ProvisioningSecretStore $secrets, private DeploymentBackupEvidenceReader $backups,
        private ProvisioningRequestFactory $requests, private string $environment, private int $maxAttempts,
        private int $retryBaseSeconds, private int $backupCheckIntervalSeconds) {}

    public function runBatch(int $limit): int
    {
        $ids = $this->db->fetchFirstColumn("SELECT id FROM commercial.deployment_operations WHERE operation_type = 'update'
            AND (status = 'pending' OR (status = 'retry' AND next_retry_at <= now())) ORDER BY created_at LIMIT ?", [$limit]);
        foreach ($ids as $id) { $this->process((string) $id); }
        return count($ids);
    }

    private function process(string $operationId): void
    {
        try {
            $row = $this->db->transactional(function (Connection $db) use ($operationId): ?array {
                $row = $db->fetchAssociative("SELECT o.*, d.installation_id, d.commercial_account_id, d.mode, d.status AS deployment_status,
                    d.archived_at, d.current_release_id, d.desired_release_id, d.core_db_secret_ref, r.version, r.channel,
                    r.status AS release_status, r.oci_reference, r.image_digest
                    FROM commercial.deployment_operations o JOIN commercial.deployments d ON d.id=o.deployment_id
                    JOIN commercial.releases r ON r.id=d.desired_release_id WHERE o.id=? AND o.operation_type='update'
                    AND (o.status='pending' OR (o.status='retry' AND o.next_retry_at<=now())) FOR UPDATE OF o,d SKIP LOCKED", [$operationId]);
                if ($row === false) return null;
                $resuming = $row['deployment_status'] === 'provisioning' && $row['provisioning_state'] === 'running'
                    && $row['status'] === 'retry' && $row['current_release_id'] !== null;
                if (!in_array($this->environment, ['dev','test'], true) || $row['mode'] !== 'dedicated'
                    || (!($row['deployment_status'] === 'active' || $resuming)) || $row['archived_at'] !== null || $row['current_release_id'] === null
                    || $row['release_status'] !== 'published') throw new ProvisioningFailure('infrastructure.deployment_ineligible');
                $db->executeStatement("UPDATE commercial.deployments SET status='provisioning', provisioning_state='running', updated_at=now() WHERE id=?", [$row['deployment_id']]);
                $db->executeStatement("UPDATE commercial.deployment_operations SET status='running', current_step=COALESCE(current_step,'quiesce'),
                    started_at=COALESCE(started_at,clock_timestamp()), attempt_count=attempt_count+1, last_error_code=NULL WHERE id=?", [$operationId]);
                return $row;
            });
            if ($row === null) return;
            $id=(string)$row['deployment_id']; $stored=$this->secrets->existing($id);
            if ($row['core_db_secret_ref'] !== $stored['reference']) throw new ProvisioningFailure('infrastructure.secret_storage_failed');
            $request=$this->requests->create($row, $stored);
            $step=(string)$this->db->fetchOne('SELECT current_step FROM commercial.deployment_operations WHERE id=?',[$operationId]);
            if ($step === 'quiesce') { $this->provider->quiesce($id); $this->step($operationId, 'pre_update_backup'); $step='pre_update_backup'; }
            $started=new \DateTimeImmutable((string)$this->db->fetchOne('SELECT started_at::text FROM commercial.deployment_operations WHERE id=?',[$operationId]));
            if ($step === 'pre_update_backup' && !$this->backups->hasUpdateBackup($id,(string)$row['installation_id'],$operationId,$started)) throw new ProvisioningFailure('infrastructure.awaiting_update_backup',true);
            if ($step === 'pre_update_backup') { $this->step($operationId, 'migrate'); $step='migrate'; }
            if ($step === 'migrate') { $this->provider->applyTargetMigrations($request); $this->step($operationId, 'replace_runtime'); $step='replace_runtime'; }
            if ($step === 'replace_runtime') { $this->provider->replaceApplicationRuntime($request); $this->step($operationId, 'runtime_verify'); $step='runtime_verify'; }
            if ($step === 'runtime_verify') { $this->provider->verifyUpdate($request,$started); $this->step($operationId, 'finalize'); }
            $this->finalize($operationId,$row);
        } catch (ProvisioningFailure $e) { $this->failure($operationId,$e); }
        catch (\Throwable) { $this->failure($operationId,new ProvisioningFailure('infrastructure.unavailable',true)); }
    }
    private function step(string $id,string $step): void { $this->db->executeStatement("UPDATE commercial.deployment_operations SET current_step=? WHERE id=? AND status='running'",[$step,$id]); }
    private function finalize(string $op,array $row): void { $this->db->transactional(function(Connection $db) use($op,$row):void {
        $db->executeStatement("UPDATE commercial.deployments SET current_release_id=desired_release_id,status='active',provisioning_state='ready',updated_at=now() WHERE id=?",[$row['deployment_id']]);
        $db->executeStatement("UPDATE commercial.deployment_operations SET status='succeeded',current_step='updated',finished_at=now(),last_error_code=NULL,next_retry_at=NULL WHERE id=?",[$op]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type,commercial_account_id,action,resource_type,resource_id,outcome,metadata) VALUES ('system',?,'deployment.updated','deployment',?,'success',jsonb_build_object('deploymentId',?::text,'sourceReleaseId',?::text,'targetReleaseId',?::text,'operationId',?::text))",[$row['commercial_account_id'],$row['deployment_id'],$row['deployment_id'],$row['current_release_id'],$row['desired_release_id'],$op]);
    }); }
    private function failure(string $op,ProvisioningFailure $e): void { $this->db->transactional(function(Connection $db) use($op,$e):void {
        $r=$db->fetchAssociative("SELECT o.attempt_count,o.deployment_id FROM commercial.deployment_operations o WHERE o.id=? FOR UPDATE",[$op]); if($r===false)return;
        $await=$e->machineCode==='infrastructure.awaiting_update_backup'; $retry=$await||($e->retryable&&(int)$r['attempt_count']<$this->maxAttempts); $delay=$await?$this->backupCheckIntervalSeconds:min(3600,$this->retryBaseSeconds*(2**min(10,(int)$r['attempt_count'])));
        $db->executeStatement("UPDATE commercial.deployment_operations SET status=?,last_error_code=?,next_retry_at=CASE WHEN ?::boolean THEN now()+(?*interval '1 second') ELSE NULL END,finished_at=CASE WHEN ?::boolean THEN NULL ELSE now() END WHERE id=?",[$retry?'retry':'failed',$e->machineCode,$retry?'true':'false',$delay,$retry?'true':'false',$op]);
        if(!$retry)$db->executeStatement("UPDATE commercial.deployments SET status='failed',provisioning_state='failed',updated_at=now() WHERE id=?",[$r['deployment_id']]);
    }); }
}
