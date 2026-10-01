<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

/** Owned Docker topology for one local dedicated deployment. */
final readonly class LocalDockerInfrastructureProvider implements DeploymentInfrastructureProvider
{
    public function __construct(private DockerCommand $docker, private string $environment,
        private string $controlNetwork, private string $controlUrl,
        private string $migratorImage, private string $backupNetwork = 'platform_backup_net',
        private string $redisImage = 'redis:8-alpine', private int $readyTimeout = 60,
        private ?DeploymentRuntimeEnvironment $runtimeEnvironment = null) {}

    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        return $this->ensure($request, true);
    }

    public function reconcile(ProvisioningRequest $request): ProvisioningResult
    {
        return $this->ensure($request, false);
    }

    /** Read-only proof used for legacy release adoption. */
    public function validateRelease(ProvisioningRequest $request): void
    {
        $this->allowed();
        $id = strtolower($request->deploymentId);
        foreach (['runtime' => $this->names($id)[0], 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker')] as $role => $name) {
            $details = $this->docker->inspect('container', $name);
            if ($details === null || !(bool)($details['State']['Running'] ?? false)
                || strtolower((string)($details['Image'] ?? '')) !== $request->imageDigest
                || ($details['Config']['Labels']['platform.deployment.id'] ?? null) !== $id
                || ($details['Config']['Labels']['platform.resource.role'] ?? null) !== ($role === 'emailWorker' ? 'email-worker' : $role)) {
                throw new ProvisioningFailure('infrastructure.release_digest_mismatch');
            }
        }
    }

    private function ensure(ProvisioningRequest $request, bool $migrate): ProvisioningResult
    {
        $this->allowed();
        if ($this->readyTimeout < 1 || $this->readyTimeout > 300 || $this->redisImage !== 'redis:8-alpine') {
            throw new ProvisioningFailure('infrastructure.configuration_invalid');
        }
        $id = strtolower($request->deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $redis = $this->resource($id, 'redis');
        $scheduler = $this->resource($id, 'scheduler');
        $emailWorker = $this->resource($id, 'email-worker');
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
        $replaceLegacyRuntime = false;
        if ($existing['runtime']) {
            $details = $this->docker->inspect('container', $runtime);
            $env = $details['Config']['Env'] ?? [];
            if (($details['Image'] ?? null) !== $request->imageDigest
                || !in_array('PLATFORM_INSTALLATION_ID=' . $request->installationId, $env, true)
                || !in_array('DB_HOST=' . $database, $env, true)
                || !$this->hasSecretMount($details, $request->databaseSecretPath, 'db_password')
                || !$this->hasSecretMount($details, $request->credentialSecretPath, 'deployment_credential')
                || !isset($details['NetworkSettings']['Networks'][$network])
                || !$this->isolatedCore($details, $network)) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            if (in_array('REDIS_HOST=' . $redis, $env, true) === false) {
                if ($migrate) {
                    throw new ProvisioningFailure('infrastructure.resource_conflict');
                }
                $replaceLegacyRuntime = true;
            }
        }
        if ($existing['redis']) {
            $details = $this->docker->inspect('container', $redis);
            if (($details['Config']['Image'] ?? null) !== $this->redisImage
                || array_keys($details['NetworkSettings']['Networks'] ?? []) !== [$network]
                || !empty($details['HostConfig']['PortBindings'])) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
        }
        foreach (['scheduler' => $scheduler, 'emailWorker' => $emailWorker] as $role => $name) {
            if (!$existing[$role]) { continue; }
            $details = $this->docker->inspect('container', $name);
            $env = $details['Config']['Env'] ?? [];
            if (($details['Image'] ?? null) !== $request->imageDigest
                || !in_array('DB_HOST=' . $database, $env, true)
                || !in_array('REDIS_HOST=' . $redis, $env, true)
                || !in_array('PLATFORM_INSTALLATION_ID=' . $request->installationId, $env, true)
                || !$this->hasSecretMount($details, $request->databaseSecretPath, 'db_password')
                || !$this->hasSecretMount($details, $request->credentialSecretPath, 'deployment_credential')
                || !isset($details['NetworkSettings']['Networks'][$network])
                || !$this->isolatedCore($details, $network)) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            if ($role === 'emailWorker' && (!in_array('SMTP_HOST=mailpit', $env, true)
                || !in_array('SMTP_PORT=1025', $env, true))) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
        }
        if (!$existing['network']) {
            $this->docker->run(['network', 'create', '--label', 'platform.deployment.id=' . $id,
                '--label', 'platform.resource.role=network',
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
        $details = $this->docker->inspect('container', $database);
        if (!isset($details['NetworkSettings']['Networks'][$this->backupNetwork])) {
            $this->docker->run(['network', 'connect', $this->backupNetwork, $database],
                'infrastructure.network_failed');
        }
        if (!$existing['redis']) {
            $this->docker->run(['run', '-d', '--name', $redis, '--network', $network,
                '--restart', 'unless-stopped', '--label', 'platform.deployment.id=' . $id,
                '--label', 'platform.resource.role=redis', $this->redisImage], 'infrastructure.redis_failed');
        } elseif (!(bool) ($this->docker->inspect('container', $redis)['State']['Running'] ?? false)) {
            $this->docker->run(['start', $redis], 'infrastructure.redis_failed');
        }
        if ($migrate) { $this->migrate($database, $network, $request->databaseSecretPath); }
        foreach (['scheduler' => $scheduler, 'emailWorker' => $emailWorker] as $role => $name) {
            if (!$existing[$role]) {
                $this->createCore($request, $role, $name, $database, $redis, $network);
            } elseif (!(bool) ($this->docker->inspect('container', $name)['State']['Running'] ?? false)) {
                $this->docker->run(['start', $name], 'infrastructure.worker_failed');
            }
            $this->connect($name, $this->controlNetwork);
        }
        if ($replaceLegacyRuntime) {
            // Replace the verified legacy process only after dependencies are running.
            $this->docker->run(['rm', '-f', '-v', $runtime], 'infrastructure.runtime_failed');
            $existing['runtime'] = false;
        }
        if (!$existing['runtime']) {
            $this->createCore($request, 'runtime', $runtime, $database, $redis, $network);
        } elseif (!(bool) ($this->docker->inspect('container', $runtime)['State']['Running'] ?? false)) {
            $this->docker->run(['start', $runtime], 'infrastructure.runtime_failed');
        }
        $this->connect($runtime, $this->controlNetwork);
        return new ProvisioningResult($runtime, $database, $network,
            $existing['runtime'] || $existing['database']);
    }

    public function status(string $deploymentId): array
    {
        $this->allowed();
        $id = strtolower($deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $result = [];
        foreach (['runtime' => $runtime, 'database' => $database,
            'redis' => $this->resource($id, 'redis'),
            'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker'), 'network' => $network] as $role => $name) {
            $object = $this->docker->inspect($role === 'network' ? 'network' : 'container', $name);
            if ($object !== null && ($object['Labels']['platform.deployment.id']
                    ?? $object['Config']['Labels']['platform.deployment.id'] ?? null) !== $id) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            if ($object !== null && $role !== 'network'
                && ($object['Config']['Labels']['platform.resource.role'] ?? null)
                !== ($role === 'emailWorker' ? 'email-worker' : $role)) {
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
        $id = strtolower($request->deploymentId);
        $existing = $this->status($id);
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker'),
            'redis' => $this->resource($id, 'redis')] as $role => $name) {
            if (!$existing[$role] || !(bool) ($this->docker->inspect('container', $name)['State']['Running'] ?? false)) {
                throw new ProvisioningFailure('runtime.background_workers_not_ready', true);
            }
        }
        $this->docker->run(['exec', $runtime, 'php', '/app/yii', 'runtime:deployment:activate'],
            'infrastructure.activation_failed');
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker')] as $name) {
            if (!(bool) ($this->docker->inspect('container', $name)['State']['Running'] ?? false)) {
                throw new ProvisioningFailure('runtime.background_workers_not_ready', true);
            }
        }
        $this->docker->run(['exec', $runtime, 'php', '-r',
            "exit(@file_get_contents('http://127.0.0.1/health/ready') === false ? 1 : 0);"],
            'infrastructure.runtime_not_ready');
        $this->docker->run(['exec', $this->resource($id, 'scheduler'), 'php', '/app/yii',
            'ops:health', 'backend-scheduler'], 'runtime.background_workers_not_ready');
        $this->docker->run(['exec', $this->resource($id, 'email-worker'), 'php', '/app/yii',
            'ops:health', 'backend-email-worker'], 'runtime.background_workers_not_ready');
    }

    public function applyTargetMigrations(ProvisioningRequest $request): void
    {
        $this->allowed();
        $id = strtolower($request->deploymentId);
        $image = $this->docker->inspect('image', $request->ociReference);
        if ($image === null || strtolower((string) ($image['Id'] ?? '')) !== $request->imageDigest) {
            throw new ProvisioningFailure('infrastructure.release_digest_mismatch');
        }
        // The migration command is executed from the pinned target artifact, never from this checkout.
        [$runtime, $database, $network] = $this->names($id);
        $this->docker->run(['run', '--rm', '--network', $network,
            '--mount', $this->secretMount($request->databaseSecretPath, 'db_password'),
            '-e', 'PGHOST=' . $database, '-e', 'POSTGRES_DB=platform', '-e', 'POSTGRES_USER=platform_runtime',
            '-e', 'POSTGRES_PASSWORD_FILE=/run/provisioning-secrets/db_password',
            '--entrypoint', 'php', $request->imageDigest, '/app/yii', 'migrate/up', '--interactive=0'],
            'infrastructure.migration_failed');
    }

    public function replaceApplicationRuntime(ProvisioningRequest $request): void
    {
        $this->allowed();
        $id = strtolower($request->deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $redis = $this->resource($id, 'redis');
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker')] as $role => $name) {
            $details = $this->docker->inspect('container', $name);
            if ($details !== null && (($details['Config']['Labels']['platform.deployment.id'] ?? null) !== $id
                || ($details['Config']['Labels']['platform.resource.role'] ?? null) !== ($role === 'emailWorker' ? 'email-worker' : $role))) {
                throw new ProvisioningFailure('infrastructure.resource_conflict');
            }
            if ($details !== null && strtolower((string)($details['Image'] ?? '')) === $request->imageDigest
                && (bool)($details['State']['Running'] ?? false)) { continue; }
        }
        // Target availability was checked before any old process is removed. DB, Redis, network and secrets remain untouched.
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker')] as $role => $name) {
            if ($details !== null) $this->docker->run(['rm', '-f', '-v', $name], 'infrastructure.runtime_replace_failed');
            $this->createCore($request, $role, $name, $database, $redis, $network);
            $this->connect($name, $this->controlNetwork);
        }
    }

    public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void
    {
        $this->allowed();
        $id = strtolower($request->deploymentId);
        $runtime = $this->names($id)[0];
        $this->docker->run(['exec', $runtime, 'php', '/app/yii', 'runtime:release:apply'], 'infrastructure.runtime_metadata_failed');
        $this->docker->run(['exec', $runtime, 'php', '/app/yii', 'runtime:entitlements:sync'], 'infrastructure.entitlement_sync_failed');
        $this->docker->run(['exec', $runtime, 'php', '-r', "exit(@file_get_contents('http://127.0.0.1/health/ready') === false ? 1 : 0);"], 'infrastructure.runtime_not_ready');
        foreach (['scheduler' => 'backend-scheduler', 'email-worker' => 'backend-email-worker'] as $role => $health) {
            $this->docker->run(['exec', $this->resource($id, $role), 'php', '/app/yii', 'ops:health', $health],
                'runtime.background_workers_not_ready');
        }
        $this->docker->run(['exec', $runtime, 'php', '/app/yii', 'runtime:release:verify',
            '--started-at=' . $startedAt->format('c')], 'infrastructure.runtime_verification_failed');
    }

    public function cleanup(string $deploymentId): void
    {
        $this->allowed();
        $id = strtolower($deploymentId);
        [$runtime, $database, $network] = $this->names($id);
        $existing = $this->status($id); // Fail before deleting anything if a name belongs to someone else.
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker'), 'redis' => $this->resource($id, 'redis'),
            'database' => $database] as $role => $name) {
            if ($existing[$role]) { $this->docker->run(['rm', '-f', '-v', $name], 'infrastructure.cleanup_failed'); }
        }
        if ($existing['network']) {
            $this->docker->run(['network', 'rm', $network], 'infrastructure.cleanup_failed');
        }
    }

    public function quiesce(string $deploymentId): void
    {
        $this->allowed();
        $id = strtolower($deploymentId);
        $runtime = $this->names($id)[0];
        $existing = $this->status($id); // Check all deterministic names before stopping anything.
        foreach (['runtime' => $runtime, 'scheduler' => $this->resource($id, 'scheduler'),
            'emailWorker' => $this->resource($id, 'email-worker')] as $role => $name) {
            if ($existing[$role] && (bool) ($this->docker->inspect('container', $name)['State']['Running'] ?? false)) {
                $this->docker->run(['stop', $name], 'infrastructure.quiesce_failed');
            }
        }
    }

    public function decommission(string $deploymentId): void
    {
        $this->cleanup($deploymentId);
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

    private function createCore(ProvisioningRequest $request, string $role, string $name,
        string $database, string $redis, string $network): void
    {
        $args = ['run', '-d', '--name', $name, '--network', $network, '--restart', 'unless-stopped',
            '--label', 'platform.deployment.id=' . strtolower($request->deploymentId),
            '--label', 'platform.resource.role=' . ($role === 'emailWorker' ? 'email-worker' : $role),
            '--mount', $this->secretMount($request->databaseSecretPath, 'db_password'),
            '--mount', $this->secretMount($request->credentialSecretPath, 'deployment_credential')];
        $builder = $this->runtimeEnvironment ?? new DeploymentRuntimeEnvironment();
        $args = array_merge($args, $builder->arguments($request, $database, $redis, $this->controlUrl,
            $role, $this->readyTimeout, $this->environment));
        if ($role !== 'runtime') { $args = array_merge($args, ['--entrypoint', 'php']); }
        $args[] = $request->imageDigest;
        if ($role === 'scheduler') { $args = array_merge($args, ['/app/yii', 'scheduler:run']); }
        if ($role === 'emailWorker') { $args = array_merge($args, ['/app/yii', 'communication:process-email', '--loop']); }
        $this->docker->run($args, 'infrastructure.' . $role . '_failed');
    }

    private function connect(string $name, string $network): void
    {
        $details = $this->docker->inspect('container', $name);
        if (!isset($details['NetworkSettings']['Networks'][$network])) {
            $this->docker->run(['network', 'connect', $network, $name], 'infrastructure.network_failed');
        }
    }

    private function isolatedCore(array $details, string $network): bool
    {
        $networks = array_keys($details['NetworkSettings']['Networks'] ?? []);
        if (array_diff($networks, [$network, $this->controlNetwork]) !== []) { return false; }
        foreach ($details['Mounts'] ?? [] as $mount) {
            if (($mount['Source'] ?? null) === '/var/run/docker.sock'
                || ($mount['Destination'] ?? null) === '/var/run/docker.sock'
                || ($mount['Destination'] ?? null) === '/backups') { return false; }
        }
        return true;
    }

    private function resource(string $id, string $role): string
    {
        return 'platform-deployment-' . str_replace('-', '', $id) . '-' . $role;
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
