<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

/** Coordinates existing local bootstrap, signed entitlement sync and status reporting. */
final readonly class RuntimeActivationService
{
    public function __construct(private RuntimeInstallationBootstrap $bootstrap,
        private RuntimeEntitlementSynchronizer $entitlements,
        private DeploymentStatusReporter $reporter) {}

    /** @param array{installationId:string,mode:string,environment:string,applicationVersion:string,releaseId:string,releaseChannel:string} $identity
     * @return array{installationId:string,entitlementSequence:int,status:string}
     */
    public function activate(array $identity): array
    {
        $installationId = $this->bootstrap->initialize($identity);
        try {
            $sequence = $this->entitlements->sync();
            if ($sequence < 1) { throw new \DomainException('Applied entitlement is unavailable.'); }
        } catch (\Throwable) {
            try { $this->reporter->failed('runtime.entitlement_sync_failed'); }
            catch (\Throwable) { /* Local activation still fails if status transport is unavailable. */ }
            throw new \DomainException('Runtime entitlement activation failed.');
        }
        $this->reporter->ready($sequence);
        return ['installationId' => $installationId, 'entitlementSequence' => $sequence,
            'status' => 'ready'];
    }
}
