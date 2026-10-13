<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Provisioning\DockerCommand;
use ControlPlane\Provisioning\DeploymentRuntimeEnvironment;
use ControlPlane\Provisioning\LocalDockerInfrastructureProvider;
use ControlPlane\Provisioning\LocalProvisioningSecretStore;
use ControlPlane\Provisioning\ProvisioningFailure;
use ControlPlane\Provisioning\ProvisioningRequest;
use PHPUnit\Framework\TestCase;

final class ProvisionerBoundaryTest extends TestCase
{
    public const ID = '11111111-1111-4111-8111-111111111111';
    private const INSTALLATION = '22222222-2222-4222-8222-222222222222';
    private const RELEASE = '33333333-3333-4333-8333-333333333333';

    public function testOnlyDedicatedAndPublishedDigestShapeCanBecomeRequest(): void
    {
        foreach (['shared', 'on_prem'] as $mode) {
            try { $this->request($mode); self::fail('Unsupported mode accepted.'); }
            catch (ProvisioningFailure $e) { self::assertSame('infrastructure.mode_not_supported', $e->machineCode); }
        }
        try { $this->request('dedicated', 'latest'); self::fail('Mutable tag accepted.'); }
        catch (ProvisioningFailure $e) { self::assertSame('infrastructure.release_invalid', $e->machineCode); }
        self::assertSame('dedicated', $this->request()->mode);
    }

    public function testAllCoreProcessesReceiveSameIsolatedDbRedisAndReleaseIdentity(): void
    {
        $builder = new DeploymentRuntimeEnvironment();
        $shared = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD_FILE',
            'REDIS_HOST', 'REDIS_PORT', 'REDIS_DATABASE', 'PLATFORM_INSTALLATION_ID',
            'APPLICATION_VERSION', 'PLATFORM_RELEASE_ID', 'PLATFORM_RELEASE_CHANNEL',
            'CONTROL_PLANE_URL', 'CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF',
            'RUNTIME_ENTITLEMENT_TRUSTED_KEYS'];
        $environments = [];
        foreach (['runtime', 'scheduler', 'emailWorker'] as $role) {
            $args = $builder->arguments($this->request(), 'dedicated-db', 'dedicated-redis',
                'http://control:8000', $role, 60, 'test');
            $values = [];
            for ($i = 0; $i < count($args); $i += 2) {
                self::assertSame('-e', $args[$i]);
                [$key, $value] = explode('=', $args[$i + 1], 2);
                $values[$key] = $value;
            }
            self::assertSame('dedicated-db', $values['DB_HOST']);
            self::assertSame('dedicated-redis', $values['REDIS_HOST']);
            self::assertSame('false', $values['CORE_PRIVACY_ERASURE_ENABLED']);
            self::assertSame('false', $values['CORE_PRIVACY_CLEANUP_ENABLED']);
            self::assertArrayNotHasKey('DOCKER_HOST', $values);
            $environments[$role] = array_intersect_key($values, array_flip($shared));
        }
        self::assertSame($environments['runtime'], $environments['scheduler']);
        self::assertSame($environments['runtime'], $environments['emailWorker']);
        self::assertSame('mailpit', $values['SMTP_HOST']);
    }

    public function testLocalSecretStoreIsRandomAndProductionFailsClosed(): void
    {
        $dir = sys_get_temp_dir() . '/provisioner-test-' . bin2hex(random_bytes(6));
        $store = new LocalProvisioningSecretStore($dir, $dir, 'test');
        if (PHP_OS_FAMILY !== 'Linux') {
            try {
                $store->prepare(self::ID, 'credential-one');
                self::fail('The POSIX local secret store accepted a non-Linux host.');
            } catch (ProvisioningFailure $e) {
                self::assertSame('infrastructure.provider_not_allowed', $e->machineCode);
            }
            self::assertDirectoryDoesNotExist($dir);

            return;
        }
        try {
            $first = $store->prepare(self::ID, 'credential-one');
            $password = file_get_contents($dir . '/' . self::ID . '/db_password');
            self::assertIsString($password);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $password);
            self::assertSame(33, filegroup($dir . '/' . self::ID));
            self::assertSame(0750, fileperms($dir . '/' . self::ID) & 0777);
            self::assertSame(33, filegroup($dir . '/' . self::ID . '/db_password'));
            self::assertSame(0640, fileperms($dir . '/' . self::ID . '/db_password') & 0777);
            self::assertSame('localdev://' . self::ID . '/db', $first['reference']);
            $store->prepare(self::ID, 'credential-two');
            self::assertSame($password, file_get_contents($dir . '/' . self::ID . '/db_password'));
            self::assertSame('credential-two', file_get_contents($dir . '/' . self::ID . '/deployment_credential'));
            try {
                (new LocalProvisioningSecretStore($dir, $dir, 'prod'))->prepare(self::ID, 'x');
                self::fail('Production local secret store accepted.');
            } catch (ProvisioningFailure $e) {
                self::assertSame('infrastructure.provider_not_allowed', $e->machineCode);
            }
        } finally {
            $store->cleanup(self::ID);
            rmdir($dir);
        }
    }

    public function testForeignResourceIsNeverRemovedAndProductionProviderFailsClosed(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array
            {
                return $kind === 'container' && str_ends_with($name, '-runtime')
                    ? ['Config' => ['Labels' => ['platform.deployment.id' => 'other']]] : null;
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        $provider = new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator');
        try { $provider->cleanup(self::ID); self::fail('Foreign resource was accepted.'); }
        catch (ProvisioningFailure $e) { self::assertSame('infrastructure.resource_conflict', $e->machineCode); }
        self::assertSame([], $docker->calls);
        try { $provider->quiesce(self::ID); self::fail('Foreign runtime was stopped.'); }
        catch (ProvisioningFailure $e) { self::assertSame('infrastructure.resource_conflict', $e->machineCode); }
        self::assertSame([], $docker->calls);
        try {
            (new LocalDockerInfrastructureProvider($docker, 'prod', 'test-net',
                'http://control-plane-test:8000', 'migrator'))->status(self::ID);
            self::fail('Production local Docker provider accepted.');
        } catch (ProvisioningFailure $e) {
            self::assertSame('infrastructure.provider_not_allowed', $e->machineCode);
        }
    }

    public function testForeignRedisOrWorkerBlocksCleanupBeforeAnyMutation(): void
    {
        foreach (['-redis', '-scheduler', '-email-worker'] as $suffix) {
            $docker = new class($suffix) extends DockerCommand {
                /** @var list<array<mixed>> */
                public array $calls = [];
                public function __construct(private string $suffix) {}
                public function inspect(string $kind, string $name): ?array
                {
                    return $kind === 'container' && str_ends_with($name, $this->suffix)
                        ? ['Config' => ['Labels' => ['platform.deployment.id' => 'foreign']]] : null;
                }
                public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
                { $this->calls[] = $arguments; return ''; }
            };
            $provider = new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
                'http://control-plane-test:8000', 'migrator');
            try { $provider->cleanup(self::ID); self::fail('Foreign resource was removed.'); }
            catch (ProvisioningFailure $e) { self::assertSame('infrastructure.resource_conflict', $e->machineCode); }
            self::assertSame([], $docker->calls);
        }
    }

    public function testCleanupRemovesOnlyMatchingOwnedResourcesAndTheirAnonymousVolumes(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): array
            {
                return $kind === 'network'
                    ? ['Labels' => ['platform.deployment.id' => ProvisionerBoundaryTest::ID]]
                    : ['Config' => ['Labels' => [
                        'platform.deployment.id' => ProvisionerBoundaryTest::ID,
                        'platform.resource.role' => str_ends_with($name, '-db') ? 'database'
                            : (str_ends_with($name, '-email-worker') ? 'email-worker'
                            : substr($name, strrpos($name, '-') + 1)),
                    ]]];
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->cleanup(self::ID);
        self::assertCount(6, $docker->calls);
        self::assertSame(['rm', '-f', '-v'], array_slice($docker->calls[0], 0, 3));
        self::assertSame(['rm', '-f', '-v'], array_slice($docker->calls[1], 0, 3));
        self::assertSame(['network', 'rm'], array_slice($docker->calls[5], 0, 2));
    }

    public function testQuiesceStopsOnlyOwnedRuntimeAndKeepsDatabase(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): array
            {
                if ($kind === 'network') { return ['Labels' => ['platform.deployment.id' => ProvisionerBoundaryTest::ID]]; }
                return ['Config' => ['Labels' => [
                    'platform.deployment.id' => ProvisionerBoundaryTest::ID,
                    'platform.resource.role' => str_ends_with($name, '-db') ? 'database'
                        : (str_ends_with($name, '-email-worker') ? 'email-worker'
                        : substr($name, strrpos($name, '-') + 1)),
                ]], 'State' => ['Running' => true]];
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->quiesce(self::ID);
        self::assertCount(3, $docker->calls);
        self::assertSame(['stop', 'platform-deployment-' . str_replace('-', '', self::ID) . '-runtime'], $docker->calls[0]);
        self::assertSame(['stop', 'platform-deployment-' . str_replace('-', '', self::ID) . '-scheduler'], $docker->calls[1]);
        self::assertSame(['stop', 'platform-deployment-' . str_replace('-', '', self::ID) . '-email-worker'], $docker->calls[2]);
    }

    public function testDecommissionOfAlreadyAbsentResourcesIsIdempotent(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array { return null; }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->decommission(self::ID);
        self::assertSame([], $docker->calls);
    }

    public function testTargetMigrationValidationAlwaysPrecedesApplication(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array
            { return $kind === 'image' ? ['Id' => 'sha256:' . str_repeat('a', 64)] : null; }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = [$arguments, $code]; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->applyTargetMigrations($this->request());
        self::assertCount(2, $docker->calls);
        self::assertContains('APP_ENV=test', $docker->calls[0][0]);
        self::assertContains('APP_DEBUG=0', $docker->calls[0][0]);
        self::assertSame('runtime:migrations:validate-history', $docker->calls[0][0][count($docker->calls[0][0]) - 1]);
        self::assertSame('infrastructure.release_migration_history_invalid', $docker->calls[0][1]);
        self::assertSame('runtime:migrations:apply', $docker->calls[1][0][count($docker->calls[1][0]) - 1]);
        self::assertSame('infrastructure.migration_failed', $docker->calls[1][1]);
    }

    public function testRuntimeReplacementReusesTargetAndRepairsPartialSourceState(): void
    {
        $scenarios = [
            ['runtime' => 'b', 'scheduler' => 'a', 'email-worker' => 'a'],
            ['runtime' => 'b', 'scheduler' => 'b', 'email-worker' => 'a'],
            ['runtime' => 'absent', 'scheduler' => 'b', 'email-worker' => 'b'],
        ];
        foreach ($scenarios as $state) {
            $docker = new class($state) extends DockerCommand {
                /** @var list<array<mixed>> */
                public array $calls = [];
                /** @var array<string, bool> */
                private array $created = [];
                /** @param array<string, string> $state */
                public function __construct(private readonly array $state) {}
                public function inspect(string $kind, string $name): ?array
                {
                    if ($kind !== 'container') { return null; }
                    $role = str_ends_with($name, '-email-worker') ? 'email-worker'
                        : (str_ends_with($name, '-scheduler') ? 'scheduler' : 'runtime');
                    if (($this->state[$role] ?? 'absent') === 'absent' && !isset($this->created[$name])) { return null; }
                    return ['Image' => (($this->state[$role] ?? null) === 'b' || isset($this->created[$name]))
                            ? 'sha256:' . str_repeat('a', 64) : 'sha256:' . str_repeat('b', 64),
                        'State' => ['Running' => true],
                        'Config' => ['Labels' => ['platform.deployment.id' => ProvisionerBoundaryTest::ID,
                            'platform.resource.role' => $role]],
                        'NetworkSettings' => ['Networks' => ['platform-deployment-' . str_replace('-', '', ProvisionerBoundaryTest::ID) => []]]];
                }
                public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
                {
                    $this->calls[] = $arguments;
                    if (($arguments[0] ?? null) === 'run' && ($index = array_search('--name', $arguments, true)) !== false) {
                        $this->created[$arguments[$index + 1]] = true;
                    }
                    return '';
                }
            };
            (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
                'http://control-plane-test:8000', 'migrator'))->replaceApplicationRuntime($this->request());
            $commands = implode("\n", array_map(static fn(array $call): string => implode(' ', $call), $docker->calls));
            foreach ($state as $role => $version) {
                $name = 'platform-deployment-' . str_replace('-', '', self::ID) . '-' . $role;
                self::assertSame($version === 'a', str_contains($commands, 'rm -f -v ' . $name), $role);
                self::assertSame($version !== 'b', str_contains($commands, '--name ' . $name), $role);
            }
        }
    }

    public function testRuntimeReplacementRejectsForeignPartialStateBeforeMutation(): void
    {
        $docker = new class extends DockerCommand {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array
            {
                if ($kind !== 'container') { return null; }
                $role = str_ends_with($name, '-scheduler') ? 'scheduler' : 'runtime';
                return ['Image' => 'sha256:' . str_repeat('a', 64), 'State' => ['Running' => true],
                    'Config' => ['Labels' => ['platform.deployment.id' => $role === 'scheduler' ? 'foreign' : ProvisionerBoundaryTest::ID,
                        'platform.resource.role' => $role]]];
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        try {
            (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
                'http://control-plane-test:8000', 'migrator'))->replaceApplicationRuntime($this->request());
            self::fail('Foreign partial runtime state was accepted.');
        } catch (ProvisioningFailure $e) { self::assertSame('infrastructure.resource_conflict', $e->machineCode); }
        self::assertSame([], $docker->calls);
    }

    private function request(string $mode = 'dedicated', string $image = 'platform-runtime:local'): ProvisioningRequest
    {
        return new ProvisioningRequest(self::ID, self::INSTALLATION, $mode, self::RELEASE,
            '5.4.0', 'stable', $image, 'sha256:' . str_repeat('a', 64),
            '/tmp/test/db_password', '/tmp/test/deployment_credential',
            '{"test-key":{"publicKeyBase64":"AQ==","status":"trusted"}}');
    }
}
