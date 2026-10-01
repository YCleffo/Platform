<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

/** One isolated network, PostgreSQL container and runtime container per dedicated deployment. */
final readonly class LocalDockerInfrastructureProvider implements DeploymentInfrastructureProvider
{
    public function __construct(private DockerCommand $docker, private string $environment,
        private string $controlNetwork, private string $controlUrl,
        private string $migratorImage) {}

    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        $this->allowed();
        $id = strtolower($request->deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $image = $this->docker->inspect('image', $request->ociReference);
        if ($image === null || strtolower((string) ($image['Id'] ?? '')) !== $request->imageDigest) {
            throw new ProvisioningFailure('infrastructure.release_digest_mismatch');
        }
        $existing = $this->status($id);
        if ($existing['database']) {
            $details = $this->docker->inspect('container', $database);
            $env = $details['Config']['Env'] ?? [];
            if (($details['Config']['Image'] ?? null) !== 'postgres:18'
                || !in_array('POSTGRES_DB=platform', $env, true)
                || !in_array('POSTGRES_USER=platform_runtime', $env, true)
                || !in_array('POSTGRES_PASSWORD_FILE=/run/provisioning-secrets/db_password', $env, true)
                || !$this->hasSecretMount($details, $request->databaseSecretPath, 'db_password')
                || !isset($details['NetworkSettings']['Networks'][$network])) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
        }
        if ($existing['runtime']) {
            $details = $this->docker->inspect('container', $runtime);
            $env = $details['Config']['Env'] ?? [];
            if (($details['Image'] ?? null) !== $request->imageDigest
                || !in_array('PLATFORM_INSTALLATION_ID=' . $request->installationId, $env, true)
                || !in_array('DB_HOST=' . $database, $env, true)
                || !$this->hasSecretMount($details, $request->databaseSecretPath, 'db_password')
                || !$this->hasSecretMount($details, $request->credentialSecretPath, 'deployment_credential')
                || !isset($details['NetworkSettings']['Networks'][$network])) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
        }
        if (!$existing['network']) {
            $this->docker->run(['network', 'create', '--label', 'platform.deployment.id=' . $id,
                $network], 'infrastructure.network_failed');
        }
        if (!$existing['database']) {
            $this->docker->run(['run', '-d', '--name', $database, '--network', $network,
                '--restart', 'unless-stopped', '--label', 'platform.deployment.id=' . $id,
                '--label', 'platform.resource.role=database',
                '--mount', $this->secretMount($request->databaseSecretPath, 'db_password'),
                '-e', 'POSTGRES_DB=platform', '-e', 'POSTGRES_USER=platform_runtime',
                '-e', 'POSTGRES_PASSWORD_FILE=/run/provisioning-secrets/db_password',
                'postgres:18'], 'infrastructure.database_failed');
        } elseif (!(bool) ($this->docker->inspect('container', $database)['State']['Running'] ?? false)) {
            $this->docker->run(['start', $database], 'infrastructure.database_failed');
        }
        $this->migrate($database, $network, $request->databaseSecretPath);
        if (!$existing['runtime']) {
            $this->docker->run(['run', '-d', '--name', $runtime, '--network', $network,
                '--restart', 'unless-stopped', '--label', 'platform.deployment.id=' . $id,
                '--label', 'platform.resource.role=runtime',
                '--mount', $this->secretMount($request->databaseSecretPath, 'db_password'),
                '--mount', $this->secretMount($request->credentialSecretPath, 'deployment_credential'),
                '-e', 'APP_ENV=' . $this->environment, '-e', 'DB_HOST=' . $database,
                '-e', 'DB_PORT=5432', '-e', 'DB_NAME=platform', '-e', 'DB_USER=platform_runtime',
                '-e', 'DB_PASSWORD_FILE=/run/provisioning-secrets/db_password',
                '-e', 'PLATFORM_INSTALLATION_ID=' . $request->installationId,
                '-e', 'PLATFORM_DEPLOYMENT_MODE=dedicated',
                '-e', 'APPLICATION_VERSION=' . $request->applicationVersion,
                '-e', 'PLATFORM_RELEASE_ID=' . $request->releaseId,
                '-e', 'PLATFORM_RELEASE_CHANNEL=' . $request->releaseChannel,
                '-e', 'CONTROL_PLANE_URL=' . $this->controlUrl,
                '-e', 'CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF=file:/run/provisioning-secrets/deployment_credential',
                '-e', 'RUNTIME_ENTITLEMENT_TRUSTED_KEYS=' . $request->trustedEntitlementKeys,
                $request->imageDigest], 'infrastructure.runtime_failed');
        } elseif (!(bool) ($this->docker->inspect('container', $runtime)['State']['Running'] ?? false)) {
            $this->docker->run(['start', $runtime], 'infrastructure.runtime_failed');
        }
        $details = $this->docker->inspect('container', $runtime);
        if ($details === null || (string) ($details['Image'] ?? '') !== $request->imageDigest) {
            throw new ProvisioningFailure('infrastructure.resource_conflict');
        }
        $connected = array_keys($details['NetworkSettings']['Networks'] ?? []);
        if (!in_array($this->controlNetwork, $connected, true)) {
            $this->docker->run(['network', 'connect', $this->controlNetwork, $runtime],
                'infrastructure.network_failed');
        }
        return new ProvisioningResult($runtime, $database, $network,
            $existing['runtime'] || $existing['database']);
    }

    public function status(string $deploymentId): array
    {
        $this->allowed();
        $id = strtolower($deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $result = [];
        foreach (['runtime' => $runtime, 'database' => $database, 'network' => $network] as $role => $name) {
            $object = $this->docker->inspect($role === 'network' ? 'network' : 'container', $name);
            if ($object !== null && ($object['Labels']['platform.deployment.id']
                    ?? $object['Config']['Labels']['platform.deployment.id'] ?? null) !== $id) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            if ($object !== null && $role !== 'network'
                && ($object['Config']['Labels']['platform.resource.role'] ?? null) !== $role) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            $result[$role] = $object !== null;
        }
        return $result;
    }

    public function activate(ProvisioningRequest $request): void
    {
        $this->allowed();
        $runtime = $this->names(strtolower($request->deploymentId))[0];
        if (!$this->status($request->deploymentId)['runtime']) {
            throw new ProvisioningFailure('infrastructure.runtime_missing', true);
        }
        $this->docker->run(['exec', $runtime, 'php', '/app/yii', 'runtime:deployment:activate'],
            'infrastructure.activation_failed');
    }

    public function cleanup(string $deploymentId): void
    {
        $this->allowed();
        $id = strtolower($deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $existing = $this->status($id); // Fail before deleting anything if a name belongs to someone else.
        foreach (['runtime' => $runtime, 'database' => $database] as $role => $name) {
            if ($existing[$role]) { $this->docker->run(['rm', '-f', '-v', $name], 'infrastructure.cleanup_failed'); }
        }
        if ($existing['network']) {
            $this->docker->run(['network', 'rm', $network], 'infrastructure.cleanup_failed');
        }
    }

    private function migrate(string $database, string $network, string $passwordPath): void
    {
        $this->docker->run(['run', '--rm', '--network', $network,
            '--mount', $this->secretMount($passwordPath, 'db_password'),
            '-e', 'PGHOST=' . $database, '-e', 'POSTGRES_DB=platform',
            '-e', 'POSTGRES_USER=platform_runtime',
            '-e', 'POSTGRES_PASSWORD_FILE=/run/provisioning-secrets/db_password',
            $this->migratorImage], 'infrastructure.migration_failed');
    }

    private function secretMount(string $path, string $name): string
    {
        if (!in_array($name, ['db_password', 'deployment_credential'], true)
            || !str_ends_with($path, '/' . $name) || str_contains($path, ',')
            || str_contains($path, "\n")) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed');
        }
        return 'type=bind,source=' . $path . ',target=/run/provisioning-secrets/' . $name . ',readonly';
    }

    private function hasSecretMount(array $details, string $path, string $name): bool
    {
        $expected = strtolower(str_replace('\\', '/', $path));
        foreach ($details['Mounts'] ?? [] as $mount) {
            if (($mount['Destination'] ?? null) === '/run/provisioning-secrets/' . $name
                && strtolower(str_replace('\\', '/', (string) ($mount['Source'] ?? ''))) === $expected
                && ($mount['RW'] ?? true) === false) {
                return true;
            }
        }
        return false;
    }

    /** @return array{string,string,string} */
    private function names(string $id): array
    {
        if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id) !== 1) {
            throw new ProvisioningFailure('infrastructure.invalid_identifier');
        }
        $stem = 'platform-deployment-' . str_replace('-', '', $id);
        return [$stem . '-runtime', $stem . '-db', $stem . '-net'];
    }

    private function allowed(): void
    {
        if (!in_array($this->environment, ['dev', 'test'], true)
            || (getenv('PROVISIONER_PROVIDER') ?: 'local-docker') !== 'local-docker') {
            throw new ProvisioningFailure('infrastructure.provider_not_allowed');
        }
    }
}
