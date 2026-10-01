<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Provisioning\LocalDeploymentBackupEvidenceReader;
use PHPUnit\Framework\TestCase;

final class LocalDeploymentBackupEvidenceReaderTest extends TestCase
{
    public function testOnlyPublishedMatchingPostStartManifestCounts(): void
    {
        $root = sys_get_temp_dir() . '/decommission-evidence-' . bin2hex(random_bytes(6));
        $deployment = '11111111-1111-4111-8111-111111111111';
        $installation = '22222222-2222-4222-8222-222222222222';
        $backup = '33333333-3333-4333-8333-333333333333';
        $release = '55555555-5555-4555-8555-555555555555';
        $path = $root . '/' . $deployment;
        mkdir($path . '/manifests', 0700, true);
        mkdir($path . '/dumps', 0700, true);
        $dump = $path . '/dumps/' . $backup . '.dump';
        file_put_contents($dump, 'archive');
        $manifest = [
            'schemaVersion' => 1, 'backupType' => 'deployment', 'backupId' => $backup,
            'deploymentId' => $deployment, 'installationId' => $installation,
            'purpose' => 'decommission_final', 'operationId' => $backup,
            'releaseId' => $release,
            'createdAt' => '2026-10-01T10:00:00Z', 'pgRestoreList' => true,
            'dump' => ['file' => "deployments/$deployment/dumps/$backup.dump",
                'sizeBytes' => 7, 'sha256' => hash('sha256', 'archive')],
        ];
        $file = $path . '/manifests/' . $backup . '.json';
        $reader = new LocalDeploymentBackupEvidenceReader($root, 'test');
        try {
            file_put_contents($file . '.partial', json_encode($manifest, JSON_THROW_ON_ERROR));
            self::assertFalse($reader->hasFinalBackup($deployment, $installation, $backup,
                new \DateTimeImmutable('2026-10-01T09:00:00Z')));
            file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
            self::assertFalse($reader->hasFinalBackup($deployment, $installation, $backup,
                new \DateTimeImmutable('2026-10-01T10:00:01Z')));
            self::assertFalse($reader->hasFinalBackup($deployment,
                '44444444-4444-4444-8444-444444444444', $backup, new \DateTimeImmutable('2026-10-01T09:00:00Z')));
            $manifest['createdAt'] = gmdate('Y-m-d\TH:i:s\Z');
            file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
            self::assertTrue($reader->hasFinalBackup($deployment, $installation, $backup,
                new \DateTimeImmutable('-1 minute')));
            $manifest['purpose'] = 'pre_update';
            file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
            self::assertTrue($reader->hasUpdateBackup($deployment, $installation, $backup, $release,
                new \DateTimeImmutable('-1 minute')));
            self::assertFalse($reader->hasUpdateBackup($deployment, $installation, $backup,
                '66666666-6666-4666-8666-666666666666', new \DateTimeImmutable('-1 minute')));
            $manifest['dump']['sha256'] = str_repeat('0', 64);
            file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR));
            self::assertFalse($reader->hasUpdateBackup($deployment, $installation, $backup, $release,
                new \DateTimeImmutable('-1 minute')));
            unlink($dump);
            self::assertFalse($reader->hasFinalBackup($deployment, $installation, $backup,
                new \DateTimeImmutable('-1 minute')));
        } finally {
            @unlink($file . '.partial'); @unlink($file); @unlink($dump);
            @rmdir($path . '/manifests'); @rmdir($path . '/dumps'); @rmdir($path); @rmdir($root);
        }
    }
}
