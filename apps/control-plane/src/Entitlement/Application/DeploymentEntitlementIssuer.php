<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Application;

use Doctrine\DBAL\Connection;

final readonly class DeploymentEntitlementIssuer
{
    public function __construct(private Connection $connection, private EffectiveEntitlements $effective,
        private EntitlementSigner $signer) {}

    /** @return array{id:string,sequence:int,documentBase64:string,signatureBase64:string,keyId:string} */
    public function ensureLatest(string $deploymentId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($deploymentId): array {
            $deployment = $db->fetchAssociative(<<<'SQL'
SELECT id, installation_id, commercial_account_id, status, archived_at
FROM commercial.deployments WHERE id = ? FOR UPDATE
SQL, [$deploymentId]);
            if ($deployment === false || $deployment['archived_at'] !== null
                || !in_array($deployment['status'], ['active', 'provisioning', 'suspended'], true)) {
                throw new \DomainException('Deployment cannot receive entitlements.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $status = $deployment['status'] === 'provisioning' ? 'active' : $deployment['status'];
            $state = $status === 'active'
                ? $this->effective->forDeploymentAccount($deployment['commercial_account_id'])
                : null;
            $features = $status === 'active' ? $state['features'] : new \stdClass();
            $features = self::canonicalValue($features);
            $until = null;
            if ($status === 'active' && $state['subscription'] !== null) {
                $boundary = $state['subscription']['billing_status'] === 'past_due'
                    ? $state['subscription']['grace_until'] : $state['subscription']['current_period_end'];
                $until = self::timestamp(new \DateTimeImmutable((string) $boundary));
            }
            $keyId = $this->signer->activeKeyId();
            $fingerprint = hash('sha256', self::json([
                'documentVersion' => 1, 'installationId' => $deployment['installation_id'],
                'deploymentStatus' => $status, 'features' => $features,
                'billingStatus' => $state['subscription']['billing_status'] ?? null,
                'pastDueSince' => $state['subscription']['past_due_since'] ?? null,
                'graceUntil' => $state['subscription']['grace_until'] ?? null,
                'validUntil' => $until, 'keyId' => $keyId,
            ]));
            $latest = $db->fetchAssociative(<<<'SQL'
SELECT id, sequence, encode(canonical_document, 'hex') AS document_hex,
       encode(signature, 'hex') AS signature_hex, signing_key_id,
       encode(source_fingerprint, 'hex') AS source_fingerprint_hex, valid_until
FROM commercial.deployment_entitlement_documents
WHERE deployment_id = ? ORDER BY sequence DESC LIMIT 1
SQL, [$deploymentId]);
            if ($latest !== false && hash_equals($latest['source_fingerprint_hex'], $fingerprint)
                && ($latest['valid_until'] === null || new \DateTimeImmutable($latest['valid_until']) > $now)) {
                return self::present($latest);
            }
            $sequence = $latest === false ? 1 : (int) $latest['sequence'] + 1;
            $issuedAt = self::timestamp($now);
            $document = self::json([
                'documentVersion' => 1, 'sequence' => $sequence,
                'installationId' => $deployment['installation_id'],
                'issuedAt' => $issuedAt, 'validFrom' => $issuedAt,
                'validUntil' => $until, 'deploymentStatus' => $status,
                'features' => $features,
            ]);
            $signed = $this->signer->sign($document);
            if ($signed['keyId'] !== $keyId) {
                throw new \DomainException('Entitlement signing key changed during issuance.');
            }
            $digest = hash('sha256', $document);
            $id = $db->fetchOne(<<<'SQL'
INSERT INTO commercial.deployment_entitlement_documents
 (deployment_id, installation_id, document_version, sequence, canonical_document, document_sha256,
  signature, signing_key_id, source_fingerprint, issued_at, valid_from, valid_until)
VALUES (?, ?, 1, ?, decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), ?, decode(?, 'hex'),
        ?::timestamptz, ?::timestamptz, ?::timestamptz) RETURNING id
SQL, [$deploymentId, $deployment['installation_id'], $sequence, bin2hex($document), $digest,
                bin2hex($signed['signature']), $keyId, $fingerprint, $issuedAt, $issuedAt, $until]);
            $db->executeStatement(<<<'SQL'
INSERT INTO commercial.outbox_events (event_type, schema_version, aggregate_type, aggregate_id, payload)
VALUES ('entitlement.issued', 1, 'deployment', ?,
        jsonb_build_object('entitlement_document_id', ?::text, 'installation_id', ?::text,
                           'document_sha256', ?::text))
SQL, [$deploymentId, $id, $deployment['installation_id'], $digest]);
            return ['id' => (string) $id, 'sequence' => $sequence,
                'documentBase64' => base64_encode($document),
                'signatureBase64' => base64_encode($signed['signature']), 'keyId' => $keyId];
        });
    }

    /** @param array<string,mixed> $row
     * @return array{id:string,sequence:int,documentBase64:string,signatureBase64:string,keyId:string}
     */
    private static function present(array $row): array
    {
        foreach (['id', 'document_hex', 'signature_hex', 'signing_key_id'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new \LogicException('Issued entitlement document is incomplete.');
            }
        }
        $document = hex2bin($row['document_hex']);
        $signature = hex2bin($row['signature_hex']);
        if ($document === false || $signature === false) { throw new \DomainException('Issued entitlement bytes are invalid.'); }
        return ['id' => $row['id'], 'sequence' => (int) $row['sequence'],
            'documentBase64' => base64_encode($document), 'signatureBase64' => base64_encode($signature),
            'keyId' => $row['signing_key_id']];
    }

    private static function timestamp(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $child) { $properties[$key] = self::canonicalValue($child); }
            return (object) $properties;
        }
        if (is_array($value)) {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as $key => $child) { $value[$key] = self::canonicalValue($child); }
        }
        return $value;
    }
}
