<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;

final readonly class RuntimeEntitlementSynchronizer
{
    public function __construct(private InstallationRepository $installations,
        private EntitlementApplier $applier, private DeploymentEntitlementPuller $puller,
        private string $controlPlaneUrl, private string $credentialReference) {}

    public function sync(): int
    {
        if (preg_match('/\Aenv:([A-Z][A-Z0-9_]*)\z/D', $this->credentialReference, $match) !== 1) {
            throw new DomainException('Deployment credential reference is unavailable.');
        }
        $secret = getenv($match[1]);
        if (!is_string($secret) || $secret === ''
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}\z/D', $secret) !== 1) {
            throw new DomainException('Deployment credential is unavailable.');
        }
        $id = $this->installations->localId();
        $envelope = $this->puller->pull($this->controlPlaneUrl, 'Service ' . $secret);
        if (count($envelope) !== 3 || !isset($envelope['documentBase64'], $envelope['signatureBase64'], $envelope['keyId'])
            || !is_string($envelope['documentBase64']) || !is_string($envelope['signatureBase64'])
            || !is_string($envelope['keyId'])) {
            throw new DomainException('Invalid entitlement envelope.');
        }
        $canonical = base64_decode($envelope['documentBase64'], true);
        $signature = base64_decode($envelope['signatureBase64'], true);
        if (!is_string($canonical) || !is_string($signature)) {
            throw new DomainException('Invalid entitlement bytes.');
        }
        $this->applier->apply($id, $canonical, $signature, $envelope['keyId']);
        $installation = $this->installations->find($id);
        if ($installation === null) { throw new DomainException('Local installation is unavailable.'); }
        return (int) $installation['current_entitlement_sequence'];
    }
}
