<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Deployment\Application\RequestDeploymentUpdate;
use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Provisioning\DeploymentBackupEvidenceReader;
use ControlPlane\Provisioning\DeploymentInfrastructureProvider;
use ControlPlane\Provisioning\LocalProvisioningSecretStore;
use ControlPlane\Provisioning\ProvisioningRequest;
use ControlPlane\Provisioning\ProvisioningRequestFactory;
use ControlPlane\Provisioning\ProvisioningResult;
use ControlPlane\Provisioning\UpdateDeploymentExecutor;

final class UpdateDeploymentExecutorTest extends DatabaseTestCase
{
    public function testEveryPersistedStepResumesWithoutRepeatingCompletedWork(): void
    {
        $cases = [
            'quiesce' => ['quiesce','migrate','replace','verify'],
            'pre_update_backup' => ['migrate','replace','verify'],
            'migrate' => ['migrate','replace','verify'],
            'replace_runtime' => ['replace','verify'],
            'runtime_verify' => ['verify'],
            'finalize' => [],
        ];
        $suiteSuffix=bin2hex(random_bytes(4));
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys(key_id,algorithm,purpose,public_key,private_key_secret_ref,status,valid_from) VALUES (?,'Ed25519','entitlement',decode(repeat('01',32),'hex'),'vault://keys/test','active',now()-interval '1 day')",['entitlement-'.$suiteSuffix]);
        foreach ($cases as $step => $expected) {
            $suffix=bin2hex(random_bytes(4)); $account=$this->account('update-resume-'.$suffix);
            $a=$this->release('81.0.0-'.$suffix,'a',$suffix); $b=$this->release('81.1.0-'.$suffix,'b',$suffix);
            $deployment=(new ReserveDeployment($this->connection,'platform.example'))->reserveCloud($account,'dedicated','resume-'.$suffix,'provision-'.$suffix);
            $this->connection->executeStatement("UPDATE commercial.deployments SET status='active',provisioning_state='ready',current_release_id=?,desired_release_id=? WHERE id=?",[$a,$a,$deployment]);
            $this->connection->executeStatement("UPDATE commercial.deployment_operations SET status='succeeded',finished_at=now() WHERE deployment_id=?",[$deployment]);
            $operation=(new RequestDeploymentUpdate($this->connection,'test'))->request($deployment,$b,'update-'.$suffix);
            $this->connection->executeStatement("UPDATE commercial.deployments SET status='provisioning',provisioning_state='running' WHERE id=?",[$deployment]);
            $this->connection->executeStatement("UPDATE commercial.deployment_operations SET status='retry',current_step=?,started_at=now(),next_retry_at=now() WHERE id=?",[$step,$operation]);
            $dir='/tmp/update-resume-'.$suffix; $secrets=new LocalProvisioningSecretStore($dir,$dir,'test');
            $stored=$secrets->prepare($deployment,'credential.test');
            $this->connection->executeStatement('UPDATE commercial.deployments SET core_db_secret_ref=? WHERE id=?',[$stored['reference'],$deployment]);
            $provider=new class implements DeploymentInfrastructureProvider {
                /** @var list<string> */
                public array $events=[];
                public function provision(ProvisioningRequest $request): ProvisioningResult { throw new \LogicException(); }
                public function status(string $deploymentId): array { return ['runtime'=>true,'database'=>true,'redis'=>true,'scheduler'=>true,'emailWorker'=>true,'network'=>true]; }
                public function activate(ProvisioningRequest $request): void { throw new \LogicException(); }
                public function applyTargetMigrations(ProvisioningRequest $request): void { $this->events[]='migrate'; }
                public function replaceApplicationRuntime(ProvisioningRequest $request): void { $this->events[]='replace'; }
                public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void { $this->events[]='verify'; }
                public function quiesce(string $deploymentId): void { $this->events[]='quiesce'; }
                public function decommission(string $deploymentId): void {}
                public function cleanup(string $deploymentId): void {}
            };
            $backups=new class implements DeploymentBackupEvidenceReader {
                public function hasFinalBackup(string $deploymentId,string $installationId,string $operationId,\DateTimeImmutable $startedAt): bool { return false; }
                public function hasUpdateBackup(string $deploymentId,string $installationId,string $operationId,string $sourceReleaseId,\DateTimeImmutable $startedAt): bool { return true; }
            };
            $executor=new UpdateDeploymentExecutor($this->connection,$provider,$secrets,$backups,new ProvisioningRequestFactory($this->connection),'test',5,1,5);
            self::assertSame(1,$executor->runBatch(1),$step);
            self::assertSame($expected,$provider->events,$step);
            self::assertSame('succeeded',$this->connection->fetchOne('SELECT status FROM commercial.deployment_operations WHERE id=?',[$operation]),$step);
            self::assertSame($b,$this->connection->fetchOne('SELECT current_release_id FROM commercial.deployments WHERE id=?',[$deployment]),$step);
            $secrets->cleanup($deployment); @rmdir($dir);
        }
    }

    public function testBackupRetryResumesWhileDeploymentIsProvisioning(): void
    {
        $suffix=bin2hex(random_bytes(4)); $account=$this->account('update-executor-'.$suffix);
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys(key_id,algorithm,purpose,public_key,private_key_secret_ref,status,valid_from) VALUES (?,'Ed25519','entitlement',decode(repeat('01',32),'hex'),'vault://keys/test','active',now()-interval '1 day')",['entitlement-'.$suffix]);
        $a=$this->release('80.0.0-'.$suffix,'a',$suffix); $b=$this->release('80.1.0-'.$suffix,'b',$suffix);
        $deployment=(new ReserveDeployment($this->connection,'platform.example'))->reserveCloud($account,'dedicated','executor-'.$suffix,'provision-'.$suffix);
        $this->connection->executeStatement("UPDATE commercial.deployments SET status='active',provisioning_state='ready',current_release_id=?,desired_release_id=? WHERE id=?",[$a,$a,$deployment]);
        $this->connection->executeStatement("UPDATE commercial.deployment_operations SET status='succeeded',finished_at=now() WHERE deployment_id=?",[$deployment]);
        $operation=(new RequestDeploymentUpdate($this->connection,'test'))->request($deployment,$b,'update-'.$suffix);
        $dir='/tmp/update-executor-'.$suffix; $secrets=new LocalProvisioningSecretStore($dir,$dir,'test');
        $stored=$secrets->prepare($deployment,'credential.test');
        $this->connection->executeStatement('UPDATE commercial.deployments SET core_db_secret_ref=? WHERE id=?',[$stored['reference'],$deployment]);
        $provider=new class implements DeploymentInfrastructureProvider {
            /** @var list<string> */
            public array $events=[];
            public function provision(ProvisioningRequest $request): ProvisioningResult { throw new \LogicException(); }
            public function status(string $deploymentId): array { return ['runtime'=>true,'database'=>true,'redis'=>true,'scheduler'=>true,'emailWorker'=>true,'network'=>true]; }
            public function activate(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function applyTargetMigrations(ProvisioningRequest $request): void { $this->events[]='migrate'; }
            public function replaceApplicationRuntime(ProvisioningRequest $request): void { $this->events[]='replace'; }
            public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void { $this->events[]='verify'; }
            public function quiesce(string $deploymentId): void { $this->events[]='quiesce'; }
            public function decommission(string $deploymentId): void {}
            public function cleanup(string $deploymentId): void {}
        };
        $backups=new class implements DeploymentBackupEvidenceReader {
            public bool $ready=false;
            public function hasFinalBackup(string $deploymentId,string $installationId,string $operationId,\DateTimeImmutable $startedAt): bool { return false; }
            public function hasUpdateBackup(string $deploymentId,string $installationId,string $operationId,string $sourceReleaseId,\DateTimeImmutable $startedAt): bool { return $this->ready; }
        };
        $executor=new UpdateDeploymentExecutor($this->connection,$provider,$secrets,$backups,new ProvisioningRequestFactory($this->connection),'test',5,1,5);
        self::assertSame(1,$executor->runBatch(1));
        self::assertSame('retry',$this->connection->fetchOne('SELECT status FROM commercial.deployment_operations WHERE id=?',[$operation]));
        self::assertSame('provisioning',$this->connection->fetchOne('SELECT status FROM commercial.deployments WHERE id=?',[$deployment]));
        self::assertSame(['quiesce'],$provider->events);
        $backups->ready=true;
        $this->connection->executeStatement('UPDATE commercial.deployment_operations SET next_retry_at=now() WHERE id=?',[$operation]);
        self::assertSame(1,$executor->runBatch(1));
        self::assertSame(['quiesce','migrate','replace','verify'],$provider->events);
        self::assertSame('succeeded',$this->connection->fetchOne('SELECT status FROM commercial.deployment_operations WHERE id=?',[$operation]),
            (string)$this->connection->fetchOne('SELECT last_error_code FROM commercial.deployment_operations WHERE id=?',[$operation]));
        self::assertSame($b,$this->connection->fetchOne('SELECT current_release_id FROM commercial.deployments WHERE id=?',[$deployment]));
        $secrets->cleanup($deployment); @rmdir($dir);
    }
    private function release(string $version,string $digest,string $suffix): string
    {
        $key='release-'.$digest.'-'.$suffix;
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys(key_id,algorithm,purpose,public_key,private_key_secret_ref,status,valid_from) VALUES (?,'Ed25519','release',decode(repeat('02',32),'hex'),'vault://keys/test','active',now()-interval '1 day')",[$key]);
        return (string)$this->connection->fetchOne("INSERT INTO commercial.releases(version,channel,status,oci_reference,image_digest,manifest_sha256,manifest_signature,signing_key_id,published_at) VALUES (?,'stable','published',?,?,decode(repeat('00',32),'hex'),decode(repeat('00',64),'hex'),?,now()) RETURNING id",[$version,'platform-runtime:'.$digest,'sha256:'.str_repeat($digest,64),$key]);
    }
}
