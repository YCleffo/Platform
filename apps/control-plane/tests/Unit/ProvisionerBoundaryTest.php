<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Provisioning\DockerCommand;
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

    public function testLocalSecretStoreIsRandomAndProductionFailsClosed(): void
    {
        $dir = sys_get_temp_dir() . '/provisioner-test-' . bin2hex(random_bytes(6));
        $store = new LocalProvisioningSecretStore($dir, $dir, 'test');
        try {
            $first = $store->prepare(self::ID, 'credential-one');
            $password = file_get_contents($dir . '/' . self::ID . '/db_password');
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $password);
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

    public function testCleanupRemovesOnlyMatchingOwnedResourcesAndTheirAnonymousVolumes(): void
    {
        $docker = new class extends DockerCommand {
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array
            {
                return $kind === 'network'
                    ? ['Labels' => ['platform.deployment.id' => ProvisionerBoundaryTest::ID]]
                    : ['Config' => ['Labels' => [
                        'platform.deployment.id' => ProvisionerBoundaryTest::ID,
                        'platform.resource.role' => str_ends_with($name, '-runtime') ? 'runtime' : 'database',
                    ]]];
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->cleanup(self::ID);
        self::assertCount(3, $docker->calls);
        self::assertSame(['rm', '-f', '-v'], array_slice($docker->calls[0], 0, 3));
        self::assertSame(['rm', '-f', '-v'], array_slice($docker->calls[1], 0, 3));
        self::assertSame(['network', 'rm'], array_slice($docker->calls[2], 0, 2));
    }

    public function testQuiesceStopsOnlyOwnedRuntimeAndKeepsDatabase(): void
    {
        $docker = new class extends DockerCommand {
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array
            {
                if ($kind === 'network') { return ['Labels' => ['platform.deployment.id' => ProvisionerBoundaryTest::ID]]; }
                return ['Config' => ['Labels' => [
                    'platform.deployment.id' => ProvisionerBoundaryTest::ID,
                    'platform.resource.role' => str_ends_with($name, '-runtime') ? 'runtime' : 'database',
                ]], 'State' => ['Running' => true]];
            }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->quiesce(self::ID);
        self::assertCount(1, $docker->calls);
        self::assertSame(['stop', 'platform-deployment-' . str_replace('-', '', self::ID) . '-runtime'], $docker->calls[0]);
    }

    public function testDecommissionOfAlreadyAbsentResourcesIsIdempotent(): void
    {
        $docker = new class extends DockerCommand {
            public array $calls = [];
            public function inspect(string $kind, string $name): ?array { return null; }
            public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
            { $this->calls[] = $arguments; return ''; }
        };
        (new LocalDockerInfrastructureProvider($docker, 'test', 'test-net',
            'http://control-plane-test:8000', 'migrator'))->decommission(self::ID);
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
