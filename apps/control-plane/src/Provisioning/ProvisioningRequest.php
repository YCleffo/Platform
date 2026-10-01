<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

final readonly class ProvisioningRequest
{
    public function __construct(
        public string $deploymentId,
        public string $installationId,
        public string $mode,
        public string $releaseId,
        public string $applicationVersion,
        public string $releaseChannel,
        public string $ociReference,
        public string $imageDigest,
        public string $databaseSecretPath,
        public string $credentialSecretPath,
        public string $trustedEntitlementKeys,
    ) {
        foreach ([$deploymentId, $installationId, $releaseId] as $id) {
            if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $id) !== 1) {
                throw new ProvisioningFailure('infrastructure.invalid_identifier');
            }
        }
        if ($mode !== 'dedicated') { throw new ProvisioningFailure('infrastructure.mode_not_supported'); }
        if (!preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageDigest)
            || !preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9._:/@-]{0,399}\z~D', $ociReference)
            || in_array(strtolower($ociReference), ['latest', 'dev', 'main'], true)
            || preg_match('/:(?:latest|dev|main)(?:@|\z)/iD', $ociReference) === 1
            || !preg_match('/\A[0-9A-Za-z.+-]{1,120}\z/D', $applicationVersion)
            || !in_array($releaseChannel, ['stable', 'beta', 'canary'], true)) {
            throw new ProvisioningFailure('infrastructure.release_invalid');
        }
        $keys = json_decode($trustedEntitlementKeys, true);
        if (!is_array($keys) || $keys === []) {
            throw new ProvisioningFailure('infrastructure.entitlement_keys_unavailable');
        }
    }
}
