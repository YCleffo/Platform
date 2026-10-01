<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

/** Explicit environment contract shared by the three Core processes. */
final readonly class DeploymentRuntimeEnvironment
{
    /** @return list<string> Docker CLI arguments */
    public function arguments(ProvisioningRequest $request, string $database, string $redis,
        string $controlUrl, string $role, int $readyTimeout, string $environment): array
    {
        $values = [
            'APP_ENV' => $environment,
            'DB_HOST' => $database, 'DB_PORT' => '5432', 'DB_NAME' => 'platform',
            'DB_USER' => 'platform_runtime', 'DB_PASSWORD_FILE' => '/run/provisioning-secrets/db_password',
            'REDIS_HOST' => $redis, 'REDIS_PORT' => '6379', 'REDIS_DATABASE' => '0',
            'PLATFORM_INSTALLATION_ID' => $request->installationId,
            'PLATFORM_DEPLOYMENT_MODE' => 'dedicated',
            'APPLICATION_VERSION' => $request->applicationVersion,
            'PLATFORM_RELEASE_ID' => $request->releaseId,
            'PLATFORM_RELEASE_CHANNEL' => $request->releaseChannel,
            'CONTROL_PLANE_URL' => $controlUrl,
            'CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF' => 'file:/run/provisioning-secrets/deployment_credential',
            'RUNTIME_ENTITLEMENT_TRUSTED_KEYS' => $request->trustedEntitlementKeys,
            'CORE_SCHEDULER_ENABLED' => 'true', 'CORE_EMAIL_WORKER_ENABLED' => 'true',
            'CORE_PRIVACY_RETENTION_ENABLED' => 'false',
            'CORE_PRIVACY_ERASURE_ENABLED' => 'false', 'CORE_PRIVACY_CLEANUP_ENABLED' => 'false',
            'RUNTIME_ACTIVATION_READY_TIMEOUT_SECONDS' => (string) $readyTimeout,
        ];
        if ($role === 'emailWorker') {
            $values += ['SMTP_HOST' => 'mailpit', 'SMTP_PORT' => '1025'];
        }
        $args = [];
        foreach ($values as $key => $value) { $args[] = '-e'; $args[] = $key . '=' . $value; }
        return $args;
    }
}
