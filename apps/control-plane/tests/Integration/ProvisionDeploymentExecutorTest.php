<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\M2m\DeploymentCredentialLifetime;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopeRegistry;
use ControlPlane\Provisioning\DeploymentInfrastructureProvider;
use ControlPlane\Provisioning\LocalProvisioningSecretStore;
use ControlPlane\Provisioning\ProvisionDeploymentExecutor;
use ControlPlane\Provisioning\ProvisioningFailure;
use ControlPlane\Provisioning\ProvisioningRequest;
use ControlPlane\Provisioning\ProvisioningResult;

final class ProvisionDeploymentExecutorTest extends DatabaseTestCase
{
    private array $secretIds = [];
    private string $secretDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretDir = sys_get_temp_dir() . '/provisioner-integration-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $store = new LocalProvisioningSecretStore($this->secretDir, $this->secretDir, 'test');
        foreach ($this->secretIds as $id) { $store->cleanup($id); }
        if (is_dir($this->secretDir)) { rmdir($this->secretDir); }
        parent::tearDown();
    }

    public function testPendingAndDueRetryAreClaimedButRunningIsNot(): void
    {
        $deployment = $this->reserve('dedicated', true);
        $this->secretIds[] = $deployment;
        $provider = $this->failingProvider();
        $executor = $this->executor($provider);
        self::assertSame(1, $executor->runBatch(1));
        $first = $this->operation($deployment);
        self::assertSame('retry', $first['status']);
        self::assertSame('infrastructure.database_failed', $first['last_error_code']);
        self::assertSame(1, (int) $first['attempt_count']);
        self::assertSame('failed', $this->connection->fetchOne(
            'SELECT status FROM commercial.deployments WHERE id = ?', [$deployment]));
        $reference = $this->connection->fetchOne(
            'SELECT core_db_secret_ref FROM commercial.deployments WHERE id = ?', [$deployment]);
        self::assertSame('localdev://' . $deployment . '/db', $reference);
        self::assertStringNotContainsString(
            (string) file_get_contents($this->secretDir . '/' . $deployment . '/db_password'), $reference);
        self::assertSame(0, $executor->runBatch(1));
        $this->connection->executeStatement("UPDATE commercial.deployment_operations
            SET next_retry_at = now() - interval '1 second' WHERE deployment_id = ?", [$deployment]);
        self::assertSame(1, $executor->runBatch(1));
        self::assertSame(2, (int) $this->operation($deployment)['attempt_count']);
        self::assertSame(2, $provider->calls);
        $this->connection->executeStatement("UPDATE commercial.deployment_operations
            SET status = 'running', started_at = now() WHERE deployment_id = ?", [$deployment]);
        self::assertSame(0, $executor->runBatch(1));
    }

    public function testUnsupportedModeAndIncompleteReleaseFailBeforeInfrastructure(): void
    {
        $shared = $this->reserve('shared', true);
        $draft = $this->reserve('dedicated', false);
        $provider = $this->failingProvider();
        $executor = $this->executor($provider);
        self::assertSame(2, $executor->runBatch(2));
        self::assertSame('infrastructure.mode_not_supported', $this->operation($shared)['last_error_code']);
        self::assertSame('infrastructure.release_unavailable', $this->operation($draft)['last_error_code']);
        self::assertSame('failed', $this->operation($shared)['status']);
        self::assertSame('failed', $this->operation($draft)['status']);
        self::assertSame(0, $provider->calls);
    }

    public function testMigrationAndActivationFailuresNeverMarkDeploymentActive(): void
    {
        $migration = $this->reserve('dedicated', true);
        $activation = $this->reserve('dedicated', true);
        $this->secretIds = [$migration, $activation];
        self::assertSame(1, $this->executor($this->failingProvider('infrastructure.migration_failed'))
            ->runBatch(1));
        $provider = new class implements DeploymentInfrastructureProvider {
            public function provision(ProvisioningRequest $request): ProvisioningResult
            { return new ProvisioningResult('runtime', 'database', 'network', false); }
            public function status(string $deploymentId): array
            { return ['runtime' => true, 'database' => true, 'network' => true]; }
            public function activate(ProvisioningRequest $request): void
            { throw new ProvisioningFailure('infrastructure.activation_failed', true); }
            public function cleanup(string $deploymentId): void {}
        };
        self::assertSame(1, $this->executor($provider)->runBatch(1));
        foreach ([$migration, $activation] as $id) {
            self::assertSame('failed', $this->connection->fetchOne(
                'SELECT status FROM commercial.deployments WHERE id = ?', [$id]));
            self::assertSame('retry', $this->operation($id)['status']);
        }
        self::assertSame('infrastructure.migration_failed', $this->operation($migration)['last_error_code']);
        self::assertSame('infrastructure.activation_failed', $this->operation($activation)['last_error_code']);
    }

    private function reserve(string $mode, bool $published): string
    {
        $suffix = bin2hex(random_bytes(5));
        $account = $this->account('provisioner-' . $suffix);
        $key = 'provisioner-key-' . $suffix;
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys
            (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
            VALUES (?, 'Ed25519', 'entitlement', decode(repeat('01',32),'hex'),
                'vault://keys/provisioner-test', 'active', now() - interval '1 day')", [$key]);
        $release = $this->connection->fetchOne("INSERT INTO commercial.releases (version, channel,
            status, oci_reference, image_digest, manifest_sha256, manifest_signature,
            signing_key_id, published_at)
            VALUES (?, 'stable', ?, ?, ?, decode(repeat('00',32),'hex'),
                decode(repeat('00',64),'hex'), ?, CASE WHEN ? THEN now() ELSE NULL END) RETURNING id",
            ['5.4.0-' . $suffix, $published ? 'published' : 'draft',
                'platform-runtime:local', 'sha256:' . str_repeat('a', 64), $key,
                $published ? 'true' : 'false']);
        $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
            ->reserveCloud($account, $mode, 'provisioner-' . $suffix, 'operation-' . $suffix);
        $this->connection->executeStatement('UPDATE commercial.deployments SET desired_release_id = ? WHERE id = ?',
            [$release, $deployment]);
        return $deployment;
    }

    private function executor(DeploymentInfrastructureProvider $provider): ProvisionDeploymentExecutor
    {
        $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
        $credentials = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
            new DatabaseRateLimiter($this->connection, $tokens));
        return new ProvisionDeploymentExecutor($this->connection,
            new StartDeploymentProvisioning($this->connection, $credentials, new DeploymentCredentialLifetime('30')),
            $provider, new LocalProvisioningSecretStore($this->secretDir, $this->secretDir, 'test'),
            'test', 5, 30);
    }

    private function failingProvider(string $code = 'infrastructure.database_failed'): DeploymentInfrastructureProvider
    {
        return new class($code) implements DeploymentInfrastructureProvider {
            public function __construct(private string $code) {}
            public int $calls = 0;
            public function provision(ProvisioningRequest $request): ProvisioningResult
            { ++$this->calls; throw new ProvisioningFailure($this->code, true); }
            public function status(string $deploymentId): array
            { return ['runtime' => false, 'database' => false, 'network' => false]; }
            public function activate(ProvisioningRequest $request): void { throw new \LogicException('Unexpected activation'); }
            public function cleanup(string $deploymentId): void {}
        };
    }

    private function operation(string $deployment): array
    {
        return $this->connection->fetchAssociative("SELECT status, attempt_count, last_error_code
            FROM commercial.deployment_operations WHERE deployment_id = ? AND operation_type = 'provision'",
            [$deployment]);
    }
}
