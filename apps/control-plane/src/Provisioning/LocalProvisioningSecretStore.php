<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

final class LocalProvisioningSecretStore implements ProvisioningSecretStore
{
    public function __construct(private readonly string $directory, private readonly string $hostDirectory,
        private readonly string $environment) {}

    public function prepare(string $deploymentId, string $credential): array
    {
        $this->guard($deploymentId);
        if (is_link($this->directory)
            || (!is_dir($this->directory) && !mkdir($this->directory, 0700, true))) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed', true);
        }
        $path = $this->directory . '/' . $deploymentId;
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700))) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed', true);
        }
        if (!chgrp($path, 33) || !chmod($path, 0750)) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed', true);
        }
        $dbFile = $path . '/db_password';
        if (is_link($dbFile) || (file_exists($dbFile) && !is_file($dbFile))
            || is_link($path . '/deployment_credential')) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed');
        }
        if (!is_file($dbFile)) { $this->write($dbFile, bin2hex(random_bytes(32))); }
        elseif (!is_readable($dbFile) || !preg_match('/\A[a-f0-9]{64}\z/D',
            (string) file_get_contents($dbFile))) {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed');
        }
        $this->write($path . '/deployment_credential', $credential);
        return ['reference' => 'localdev://' . $deploymentId . '/db',
            'databasePath' => $this->hostDirectory . '/' . $deploymentId . '/db_password',
            'credentialPath' => $this->hostDirectory . '/' . $deploymentId . '/deployment_credential'];
    }

    public function cleanup(string $deploymentId): void
    {
        $this->guard($deploymentId);
        $path = $this->directory . '/' . $deploymentId;
        if (is_link($path)) { throw new ProvisioningFailure('infrastructure.secret_storage_failed'); }
        if (!is_dir($path)) { return; }
        foreach (['db_password', 'deployment_credential'] as $name) {
            $file = $path . '/' . $name;
            if (is_link($file)) { throw new ProvisioningFailure('infrastructure.secret_storage_failed'); }
            if (is_file($file) && !@unlink($file)) { throw new ProvisioningFailure('infrastructure.secret_storage_failed', true); }
        }
        if (!@rmdir($path)) { throw new ProvisioningFailure('infrastructure.secret_storage_failed', true); }
    }

    public function isClean(string $deploymentId): bool
    {
        $this->guard($deploymentId);
        $path = $this->directory . '/' . $deploymentId;
        return !file_exists($path) && !is_link($path);
    }

    private function guard(string $deploymentId): void
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new ProvisioningFailure('infrastructure.provider_not_allowed');
        }
        if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $deploymentId) !== 1) {
            throw new ProvisioningFailure('infrastructure.invalid_identifier');
        }
        if (!str_starts_with($this->directory, '/') || $this->hostDirectory === '') {
            throw new ProvisioningFailure('infrastructure.secret_storage_failed');
        }
    }

    private function write(string $file, string $value): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.partial';
        if (file_put_contents($temporary, $value, LOCK_EX) === false || !chgrp($temporary, 33)
            || !chmod($temporary, 0640)
            || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new ProvisioningFailure('infrastructure.secret_storage_failed', true);
        }
    }
}
