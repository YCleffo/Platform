<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use App\PlatformRuntime\Scheduler\OperationalStatus;

/** Coordinates existing local bootstrap, signed entitlement sync and status reporting. */
final readonly class RuntimeActivationService
{
    public function __construct(
        private RuntimeInstallationBootstrap $bootstrap,
        private RuntimeEntitlementSynchronizer $entitlements,
        private DeploymentStatusReporter $reporter,
        private OperationalStatus $operationalStatus,
    ) {}

    /** @param array{installationId:string,mode:string,environment:string,applicationVersion:string,releaseId:string,releaseChannel:string} $identity
     * @return array{installationId:string,entitlementSequence:int,status:string}
     */
    public function activate(array $identity): array
    {
        $installationId = $this->bootstrap->initialize($identity);
        try {
            $sequence = $this->entitlements->sync();
            if ($sequence < 1) {
                throw new \DomainException('Applied entitlement is unavailable.');
            }
        } catch (\Throwable) {
            try {
                $this->reporter->failed('runtime.entitlement_sync_failed');
            } catch (\Throwable) { /* Local activation still fails if status transport is unavailable. */
            }
            throw new \DomainException('Runtime entitlement activation failed.');
        }
        $rawTimeout = getenv('RUNTIME_ACTIVATION_READY_TIMEOUT_SECONDS');
        $timeout = $rawTimeout === false || $rawTimeout === '' ? 60 : filter_var($rawTimeout, FILTER_VALIDATE_INT);
        if (!is_int($timeout) || $timeout < 1 || $timeout > 300) {
            throw new \DomainException('runtime.background_workers_not_ready');
        }
        $deadline = microtime(true) + $timeout;
        $ready = false;
        do {
            $ready = $this->operationalStatus->ready();
            if ($ready) {
                break;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        if (!$ready) {
            try {
                $this->reporter->failed('runtime.background_workers_not_ready');
            } catch (\Throwable) { /* Activation still fails if status transport is unavailable. */
            }
            throw new \DomainException('runtime.background_workers_not_ready');
        }
        $this->reporter->ready($sequence);
        return ['installationId' => $installationId, 'entitlementSequence' => $sequence,
            'status' => 'ready'];
    }
}
