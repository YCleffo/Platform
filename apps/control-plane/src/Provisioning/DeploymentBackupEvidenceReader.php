<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

interface DeploymentBackupEvidenceReader
{
    public function hasFinalBackup(string $deploymentId, string $installationId, string $operationId, \DateTimeImmutable $startedAt): bool;
    public function hasUpdateBackup(string $deploymentId, string $installationId, string $operationId,
        string $sourceReleaseId, \DateTimeImmutable $startedAt): bool;
}
