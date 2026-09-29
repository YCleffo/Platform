<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DateTimeImmutable;
use DateTimeZone;

final readonly class PostgresEffectiveRuntimeEntitlements implements EffectiveRuntimeEntitlements
{
    public function __construct(private InstallationRepository $installations,
        private EntitlementDocumentRepository $documents,
        private EntitlementSignatureVerifier $verifier) {}

    public function forInstallation(string $installationId): array
    {
        $installation = $this->installations->find($installationId);
        if ($installation === null || $installation['status'] !== 'active') { return []; }
        $row = $this->documents->latestForInstallation($installationId);
        if ($row === null || $row['sequence'] !== (int) $installation['current_entitlement_sequence']
            || !$this->verifier->verify($row['canonicalDocument'], $row['signature'], $row['keyId'])) {
            return [];
        }
        try {
            $document = EntitlementDocumentV1::parse($row['canonicalDocument']);
            $document->assertCurrentlyValid(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            if ($document->installationId !== $installationId || $document->sequence !== $row['sequence']
                || $document->deploymentStatus !== 'active') { return []; }
            return get_object_vars($document->features);
        } catch (\Throwable) {
            return [];
        }
    }
}
