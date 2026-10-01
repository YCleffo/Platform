<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Doctrine\DBAL\Connection;

final readonly class ProvisioningRequestFactory
{
    public function __construct(private Connection $db) {}

    /** @param array<string,mixed> $row
     * @param array{databasePath:string,credentialPath:string} $secrets
     */
    public function create(array $row, array $secrets, bool $requirePublished = true): ProvisioningRequest
    {
        if (($requirePublished && ($row['release_status'] ?? null) !== 'published')
            || ($row['desired_release_id'] ?? null) === null
            || ($row['oci_reference'] ?? null) === null || ($row['image_digest'] ?? null) === null) {
            throw new ProvisioningFailure('infrastructure.release_unavailable');
        }
        return new ProvisioningRequest((string) $row['deployment_id'], (string) $row['installation_id'],
            (string) $row['mode'], (string) $row['desired_release_id'], (string) $row['version'],
            (string) $row['channel'], (string) $row['oci_reference'], (string) $row['image_digest'],
            $secrets['databasePath'], $secrets['credentialPath'], $this->trustedKeys());
    }

    private function trustedKeys(): string
    {
        $rows = $this->db->fetchAllAssociative("SELECT key_id, encode(public_key, 'base64') AS encoded
            FROM commercial.signing_keys WHERE purpose = 'entitlement' AND status = 'active'
            AND revoked_at IS NULL AND algorithm = 'Ed25519'
            AND valid_from <= now() AND (valid_until IS NULL OR valid_until > now())");
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['key_id']] = ['publicKeyBase64' => preg_replace('/\s+/', '', $row['encoded']),
                'status' => 'trusted'];
        }
        if ($keys === []) { throw new ProvisioningFailure('infrastructure.entitlement_keys_unavailable'); }
        return json_encode($keys, JSON_THROW_ON_ERROR);
    }
}
