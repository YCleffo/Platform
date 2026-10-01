<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

interface DeploymentBackupEvidenceReader
{
    public function hasFinalBackup(string $deploymentId, string $installationId, \DateTimeImmutable $startedAt): bool;
}
