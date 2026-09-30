<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\RetentionDependencyContributor;
use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Active organization relationships block membership destruction. */
final readonly class CoreResourceDependencyContributor implements RetentionDependencyContributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string { return 'core.resource_dependency'; }

    public function supports(string $resourceType): bool
    {
        return $resourceType === 'core.organization_membership';
    }

    public function resolved(RetentionCandidate $candidate): bool
    {
        if (!$this->supports($candidate->resourceType)) { return false; }
        $result = $this->db->createCommand(<<<'SQL'
            SELECT m.status = 'left' FROM core.organization_memberships m
            WHERE m.id = CAST(:id AS uuid) AND m.person_id = CAST(:subject AS uuid)
            SQL,
            [':id' => $candidate->resourceId, ':subject' => $candidate->subjectPersonId])->queryScalar();
        return in_array($result, [true, 't', '1', 1], true);
    }
}
