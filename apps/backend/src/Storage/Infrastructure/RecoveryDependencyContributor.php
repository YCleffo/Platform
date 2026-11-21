<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\RetentionDependencyContributor;
use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class RecoveryDependencyContributor implements RetentionDependencyContributor
{
    public function __construct(private ConnectionInterface $db) {}
    public function code(): string
    {
        return 'storage.recovery_dependency';
    }
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['storage.space', 'education.workspace'], true);
    }
    public function resolved(RetentionCandidate $candidate): bool
    {
        return !$this->hasPendingRecovery($candidate->subjectPersonId);
    }
    public function hasPendingRecovery(string $subject): bool
    {
        $found = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM storage.spaces sp
            WHERE sp.subject_person_id=CAST(:subject AS uuid) AND (
                EXISTS(SELECT 1 FROM device_management.device_sessions s
                    WHERE s.student_id=sp.student_id AND s.organization_id=sp.organization_id AND s.status<>'CLOSED')
                OR EXISTS(SELECT 1 FROM storage.manifests m WHERE m.space_id=sp.id AND m.status='pending')
                OR EXISTS(SELECT 1 FROM storage.sync_sessions ss WHERE ss.space_id=sp.id AND ss.status='running')
                OR EXISTS(SELECT 1 FROM storage.transfers tr WHERE tr.space_id=sp.id
                    AND tr.direction='upload' AND tr.status IN ('pending','uploading','verifying') AND tr.expires_at>now())
            ) LIMIT 1
            SQL, [':subject' => $subject])->queryScalar();
        return $found !== false && $found !== null;
    }
}
