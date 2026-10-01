<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

final readonly class LocalDeploymentBackupEvidenceReader implements DeploymentBackupEvidenceReader
{
    public function __construct(private string $directory, private string $environment) {}

    public function hasFinalBackup(string $deploymentId, string $installationId, \DateTimeImmutable $startedAt): bool
    {
        if (!in_array($this->environment, ['dev', 'test'], true)
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $deploymentId) !== 1
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $installationId) !== 1) {
            throw new ProvisioningFailure('infrastructure.backup_evidence_unavailable');
        }
        $base = rtrim($this->directory, '/') . '/' . $deploymentId;
        $manifests = $base . '/manifests';
        if (is_link($this->directory) || is_link($base) || is_link($manifests)
            || is_link($base . '/dumps') || !is_dir($manifests)) { return false; }
        foreach (new \DirectoryIterator($manifests) as $file) {
            if (!$file->isFile() || $file->isLink()
                || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.json\z/D', $file->getFilename()) !== 1) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 1
                || ($manifest['backupType'] ?? null) !== 'deployment'
                || ($manifest['deploymentId'] ?? null) !== $deploymentId
                || ($manifest['installationId'] ?? null) !== $installationId
                || ($manifest['backupId'] ?? null) !== substr($file->getFilename(), 0, -5)
                || ($manifest['pgRestoreList'] ?? null) !== true
                || !is_string($manifest['createdAt'] ?? null)
                || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $manifest['createdAt']) !== 1) {
                continue;
            }
            try { $created = new \DateTimeImmutable($manifest['createdAt']); }
            catch (\Throwable) { continue; }
            if ($created < $startedAt || $created > new \DateTimeImmutable('+5 minutes')) { continue; }
            $dump = $base . '/dumps/' . $manifest['backupId'] . '.dump';
            if (($manifest['dump']['file'] ?? null) !== 'deployments/' . $deploymentId . '/dumps/' . $manifest['backupId'] . '.dump'
                || !is_file($dump) || is_link($dump)
                || !is_int($manifest['dump']['sizeBytes'] ?? null)
                || $manifest['dump']['sizeBytes'] < 1
                || filesize($dump) !== $manifest['dump']['sizeBytes']
                || !is_string($manifest['dump']['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $manifest['dump']['sha256']) !== 1) {
                continue;
            }
            // The backup worker verifies SHA and pg_restore before publishing the manifest last.
            return true;
        }
        return false;
    }
}
