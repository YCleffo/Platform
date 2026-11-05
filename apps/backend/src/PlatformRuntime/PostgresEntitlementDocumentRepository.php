<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresEntitlementDocumentRepository implements EntitlementDocumentRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function latestForInstallation(string $installationId): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
SELECT id::text, sequence, document_version, issued_at, valid_from, valid_until,
       payload->>'canonicalDocumentBase64' AS canonical_base64,
       encode(signature, 'hex') AS signature_hex, signing_key_id, status::text
FROM platform_runtime.entitlement_documents
WHERE installation_id = CAST(:id AS uuid) AND status = 'applied'
ORDER BY sequence DESC LIMIT 1
SQL, [':id' => $installationId])->queryOne();
        if (!is_array($row)) {
            return null;
        }
        $canonical = is_string($row['canonical_base64']) ? base64_decode($row['canonical_base64'], true) : false;
        $signature = hex2bin((string) $row['signature_hex']);
        if (!is_string($canonical) || !is_string($signature)) {
            return null;
        }
        return ['id' => $row['id'], 'sequence' => (int) $row['sequence'],
            'documentVersion' => (int) $row['document_version'], 'canonicalDocument' => $canonical,
            'signature' => $signature, 'keyId' => $row['signing_key_id'],
            'issuedAt' => $row['issued_at'], 'validFrom' => $row['valid_from'],
            'validUntil' => $row['valid_until'], 'status' => $row['status']];
    }
}
