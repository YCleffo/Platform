<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresEntitlementApplier implements EntitlementApplier
{
    public function __construct(
        private ConnectionInterface $db,
        private EntitlementSignatureVerifier $verifier,
    ) {}

    public function apply(string $installationId, string $canonicalDocument, string $signature, string $keyId): void
    {
        if (!$this->verifier->verify($canonicalDocument, $signature, $keyId)) {
            throw new DomainException('Entitlement signature is untrusted.');
        }
        $document = EntitlementDocumentV1::parse($canonicalDocument);
        $document->assertCurrentlyValid(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($document->installationId !== $installationId) {
            throw new DomainException('Entitlement installation mismatch.');
        }
        $this->db->transaction(function () use ($installationId, $canonicalDocument, $signature, $keyId, $document): void {
            $installation = $this->db->createCommand(<<<'SQL'
SELECT status::text, current_entitlement_sequence FROM platform_runtime.installations
WHERE id = CAST(:id AS uuid) FOR UPDATE
SQL, [':id' => $installationId])->queryOne();
            if (!is_array($installation) || $installation['status'] === 'decommissioned') {
                throw new DomainException('Local installation cannot apply entitlements.');
            }
            $current = (int) $installation['current_entitlement_sequence'];
            if ($document->sequence <= $current) {
                if ($document->sequence === $current && $this->isExactReplay(
                    $installationId,
                    $document->sequence,
                    $canonicalDocument,
                    $signature,
                    $keyId,
                )) {
                    return;
                }
                throw new DomainException('Stale entitlement sequence.');
            }
            $payload = json_encode(['canonicalDocumentBase64' => base64_encode($canonicalDocument)], JSON_THROW_ON_ERROR);
            $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.entitlement_documents
 (installation_id, document_version, sequence, issued_at, valid_from, valid_until,
  payload, signature, signing_key_id)
VALUES (CAST(:installation AS uuid), 1, :sequence, CAST(:issued AS timestamptz),
        CAST(:valid_from AS timestamptz), CAST(:valid_until AS timestamptz),
        CAST(:payload AS jsonb), decode(:signature, 'hex'), :key)
SQL, [':installation' => $installationId, ':sequence' => $document->sequence,
                ':issued' => $document->issuedAt->format(DATE_ATOM),
                ':valid_from' => $document->validFrom->format(DATE_ATOM),
                ':valid_until' => $document->validUntil?->format(DATE_ATOM),
                ':payload' => $payload, ':signature' => bin2hex($signature), ':key' => $keyId])->execute();
            $this->db->createCommand(<<<'SQL'
UPDATE platform_runtime.entitlement_documents SET status = 'applied', applied_at = now()
WHERE installation_id = CAST(:installation AS uuid) AND sequence = :sequence AND status = 'pending'
SQL, [':installation' => $installationId, ':sequence' => $document->sequence])->execute();
            $this->db->createCommand(<<<'SQL'
UPDATE platform_runtime.installations
SET status = CAST(:status AS platform_runtime.installation_status),
    activated_at = CASE WHEN :status = 'active' THEN COALESCE(activated_at, now()) ELSE activated_at END,
    updated_at = now()
WHERE id = CAST(:installation AS uuid)
SQL, [':status' => $document->deploymentStatus, ':installation' => $installationId])->execute();
        });
    }

    private function isExactReplay(
        string $installationId,
        int $sequence,
        string $canonical,
        string $signature,
        string $keyId,
    ): bool {
        $row = $this->db->createCommand(<<<'SQL'
SELECT payload->>'canonicalDocumentBase64' AS canonical_base64,
       encode(signature, 'hex') AS signature_hex, signing_key_id, status::text
FROM platform_runtime.entitlement_documents
WHERE installation_id = CAST(:installation AS uuid) AND sequence = :sequence
SQL, [':installation' => $installationId, ':sequence' => $sequence])->queryOne();
        return is_array($row) && $row['status'] === 'applied'
            && $row['signing_key_id'] === $keyId
            && hash_equals((string) $row['canonical_base64'], base64_encode($canonical))
            && hash_equals((string) $row['signature_hex'], bin2hex($signature));
    }
}
