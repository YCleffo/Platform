<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Deployment\Application\RequestDeploymentDecommission;
use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopeRegistry;
use ControlPlane\Provisioning\DeploymentBackupEvidenceReader;
use ControlPlane\Provisioning\DeploymentInfrastructureProvider;
use ControlPlane\Provisioning\DeprovisionDeploymentExecutor;
use ControlPlane\Provisioning\ProvisioningRequest;
use ControlPlane\Provisioning\ProvisioningResult;
use ControlPlane\Provisioning\ProvisioningSecretStore;
use ControlPlane\Provisioning\ProvisioningFailure;

final class DeploymentDecommissionTest extends DatabaseTestCase
{
    public function testRequestEligibilityIdempotencyAndImmediateCredentialRevocation(): void
    {
        $requests = new RequestDeploymentDecommission($this->connection, 'test');
        $active = $this->deployment('active');
        $installation = $this->installation($active);
        $credentials = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
            new DatabaseRateLimiter($this->connection,
                new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
        $issued = $credentials->create($active, ['usage.write', 'entitlements.read'],
            (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($issued);
        $header = 'Service ' . $issued['credential']['credentialId'] . '.' . $issued['secret'];
        self::assertNotNull($credentials->authenticate($header, '127.0.0.1'));
        try { $requests->request($active, $this->uuid(), 'decommission-one'); self::fail('Wrong installation accepted.'); }
        catch (\DomainException) {}
        $operation = $requests->request($active, $installation, 'decommission-one');
        self::assertSame($operation, $requests->request($active, $installation, 'decommission-one'));
        self::assertSame('suspended', $this->connection->fetchOne('SELECT status FROM commercial.deployments WHERE id = ?', [$active]));
        self::assertSame('revoked', $this->connection->fetchOne('SELECT status FROM commercial.service_credentials WHERE credential_id = ?', [$issued['credential']['credentialId']]));
        self::assertNull($credentials->authenticate($header, '127.0.0.1'));
        self::assertNull($credentials->create($active, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)));
        self::assertNull($credentials->rotate($active, $issued['credential']['credentialId'],
            (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)));
        try { $requests->request($active, $installation, 'decommission-two'); self::fail('Concurrent operation accepted.'); }
        catch (\DomainException) {}
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events
            WHERE resource_id = ? AND action = 'deployment.decommission_requested'", [$active]));
        $metadata = json_decode((string) $this->connection->fetchOne("SELECT metadata::text
            FROM commercial.audit_events WHERE resource_id = ? AND action = 'deployment.decommission_requested'",
            [$active]), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $metadata);
        self::assertSame($active, $metadata['deploymentId']);
        self::assertSame($installation, $metadata['installationId']);
        self::assertSame($operation, $metadata['operationId']);
        $suspended = $this->deployment('suspended');
        self::assertNotEmpty($requests->request($suspended, $this->installation($suspended), 'decommission-suspended'));
        foreach (['reserved', 'provisioning', 'failed', 'decommissioned'] as $status) {
            $id = $this->deployment($status);
            try { $requests->request($id, $this->installation($id), 'decommission-' . $status);
                self::fail($status . ' accepted.'); }
            catch (\DomainException) {}
        }
    }

    public function testExecutorWaitsForFinalBackupThenDestroysAndFinalizes(): void
    {
        $id = $this->deployment('active');
        $installation = $this->installation($id);
        $operation = (new RequestDeploymentDecommission($this->connection, 'test'))
            ->request($id, $installation, 'decommission-flow');
        $provider = new class implements DeploymentInfrastructureProvider {
            public bool $running = true;
            public bool $exists = true;
            public array $events = [];
            public function provision(ProvisioningRequest $request): ProvisioningResult { throw new \LogicException(); }
            public function activate(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function applyTargetMigrations(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function replaceApplicationRuntime(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void { throw new \LogicException(); }
            public function status(string $deploymentId): array
            { return ['runtime' => $this->exists, 'database' => $this->exists, 'network' => $this->exists]; }
            public function quiesce(string $deploymentId): void
            { $this->running = false; $this->events[] = 'quiesce'; }
            public function decommission(string $deploymentId): void
            { if ($this->running) { throw new \LogicException(); } $this->exists = false; $this->events[] = 'destroy'; }
            public function cleanup(string $deploymentId): void { throw new \LogicException(); }
        };
        $secrets = new class implements ProvisioningSecretStore {
            public bool $clean = false;
            public array $events = [];
            public function prepare(string $deploymentId, string $credential): array { throw new \LogicException(); }
            public function existing(string $deploymentId): array { throw new \LogicException(); }
            public function cleanup(string $deploymentId): void { $this->clean = true; $this->events[] = 'secret_cleanup'; }
            public function isClean(string $deploymentId): bool { return $this->clean; }
        };
        $evidence = new class implements DeploymentBackupEvidenceReader {
            public bool $ready = false;
            public function hasFinalBackup(string $deploymentId, string $installationId, string $operationId, \DateTimeImmutable $startedAt): bool
            { return $this->ready; }
            public function hasUpdateBackup(string $deploymentId, string $installationId, string $operationId, string $sourceReleaseId, \DateTimeImmutable $startedAt): bool { return false; }
        };
        $executor = new DeprovisionDeploymentExecutor($this->connection, $provider, $secrets,
            $evidence, 'test', 5, 1, 5);
        self::assertSame(1, $executor->runBatch(1));
        self::assertFalse($provider->running);
        self::assertTrue($provider->exists);
        self::assertFalse($secrets->clean);
        $row = $this->connection->fetchAssociative('SELECT status, current_step, last_error_code
            FROM commercial.deployment_operations WHERE id = ?', [$operation]);
        self::assertSame('retry', $row['status']);
        self::assertSame('final_backup', $row['current_step']);
        self::assertSame('infrastructure.awaiting_final_backup', $row['last_error_code']);
        $evidence->ready = true;
        $this->connection->executeStatement("UPDATE commercial.deployment_operations SET next_retry_at = now() - interval '1 second' WHERE id = ?", [$operation]);
        self::assertSame(1, $executor->runBatch(1));
        self::assertFalse($provider->exists);
        self::assertTrue($secrets->clean);
        self::assertSame(['quiesce', 'quiesce', 'destroy'], $provider->events);
        $deployment = $this->connection->fetchAssociative('SELECT status, provisioning_state,
            archived_at, core_db_secret_ref, installation_id FROM commercial.deployments WHERE id = ?', [$id]);
        self::assertSame('decommissioned', $deployment['status']);
        self::assertSame('not_applicable', $deployment['provisioning_state']);
        self::assertNotNull($deployment['archived_at']);
        self::assertNull($deployment['core_db_secret_ref']);
        self::assertSame($installation, $deployment['installation_id']);
        self::assertSame('disabled', $this->connection->fetchOne('SELECT status FROM commercial.deployment_domains WHERE deployment_id = ?', [$id]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.deployment_operations WHERE id = ?', [$operation]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events
            WHERE resource_id = ? AND action = 'deployment.decommissioned'", [$id]));
    }

    public function testRetriesAfterPartialDestroyAndSecretCleanup(): void
    {
        $id = $this->deployment('active');
        $operation = (new RequestDeploymentDecommission($this->connection, 'test'))
            ->request($id, $this->installation($id), 'decommission-crash');
        $provider = new class implements DeploymentInfrastructureProvider {
            public int $destroyCalls = 0;
            public bool $runtime = true;
            public bool $database = true;
            public bool $network = true;
            public function provision(ProvisioningRequest $request): ProvisioningResult { throw new \LogicException(); }
            public function activate(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function applyTargetMigrations(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function replaceApplicationRuntime(ProvisioningRequest $request): void { throw new \LogicException(); }
            public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void { throw new \LogicException(); }
            public function status(string $deploymentId): array
            { return ['runtime' => $this->runtime, 'database' => $this->database, 'network' => $this->network]; }
            public function quiesce(string $deploymentId): void {}
            public function decommission(string $deploymentId): void
            {
                ++$this->destroyCalls;
                $this->runtime = false;
                if ($this->destroyCalls === 1) { throw new ProvisioningFailure('infrastructure.cleanup_failed', true); }
                $this->database = false; $this->network = false;
            }
            public function cleanup(string $deploymentId): void { throw new \LogicException(); }
        };
        $secrets = new class implements ProvisioningSecretStore {
            public bool $clean = false;
            public int $calls = 0;
            public function prepare(string $deploymentId, string $credential): array { throw new \LogicException(); }
            public function existing(string $deploymentId): array { throw new \LogicException(); }
            public function cleanup(string $deploymentId): void
            {
                ++$this->calls;
                $this->clean = true;
                if ($this->calls === 1) { throw new ProvisioningFailure('infrastructure.secret_storage_failed', true); }
            }
            public function isClean(string $deploymentId): bool { return $this->clean; }
        };
        $evidence = new class implements DeploymentBackupEvidenceReader {
            public function hasFinalBackup(string $deploymentId, string $installationId, string $operationId, \DateTimeImmutable $startedAt): bool
            { return true; }
            public function hasUpdateBackup(string $deploymentId, string $installationId, string $operationId, string $sourceReleaseId, \DateTimeImmutable $startedAt): bool { return false; }
        };
        $executor = new DeprovisionDeploymentExecutor($this->connection, $provider, $secrets,
            $evidence, 'test', 5, 1, 5);
        self::assertSame(1, $executor->runBatch(1));
        self::assertFalse($provider->runtime);
        self::assertTrue($provider->database);
        self::assertSame('suspended', $this->connection->fetchOne('SELECT status FROM commercial.deployments WHERE id = ?', [$id]));
        $this->retryNow($operation);
        self::assertSame(1, $executor->runBatch(1));
        self::assertFalse($provider->database);
        self::assertTrue($secrets->clean);
        self::assertSame('suspended', $this->connection->fetchOne('SELECT status FROM commercial.deployments WHERE id = ?', [$id]));
        $this->retryNow($operation);
        self::assertSame(1, $executor->runBatch(1));
        self::assertSame('decommissioned', $this->connection->fetchOne('SELECT status FROM commercial.deployments WHERE id = ?', [$id]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.deployment_operations WHERE id = ?', [$operation]));
    }

    private function deployment(string $status): string
    {
        $slug = 'decommission-' . bin2hex(random_bytes(5));
        $account = $this->account($slug);
        $id = (new ReserveDeployment($this->connection, 'platform.example'))
            ->reserveCloud($account, 'dedicated', $slug, $slug . '-provision');
        $this->connection->executeStatement('UPDATE commercial.deployments SET status = ? WHERE id = ?', [$status, $id]);
        return $id;
    }

    private function installation(string $deploymentId): string
    {
        return (string) $this->connection->fetchOne('SELECT installation_id FROM commercial.deployments WHERE id = ?', [$deploymentId]);
    }

    private function retryNow(string $operationId): void
    {
        $this->connection->executeStatement("UPDATE commercial.deployment_operations
            SET next_retry_at = now() - interval '1 second' WHERE id = ?", [$operationId]);
    }
}
