<?php

declare(strict_types=1);

namespace App\Core\Organization\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class OrganizationMembershipErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'core.organization_membership.delete';
    }
    public function resourceType(): string
    {
        return 'core.organization_membership';
    }
    public function supportsAction(string $action): bool
    {
        return $action === 'delete';
    }
    public function reconcile(string $itemId): ?string
    {
        return null;
    }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        return (bool) $this->db->createCommand(<<<'SQL'
            SELECT EXISTS(SELECT 1 FROM core.organization_memberships
                WHERE id = CAST(:id AS uuid) AND person_id = CAST(:subject AS uuid))
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'delete') {
            throw new DomainException('Unsupported membership erasure action.');
        }
        $deleted = $this->db->createCommand(<<<'SQL'
            DELETE FROM core.organization_memberships
            WHERE id = CAST(:id AS uuid) AND person_id = CAST(:subject AS uuid)
            RETURNING id
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        if ($deleted === false || $deleted === null) {
            throw new DomainException('Membership ownership changed.');
        }
        return 'deleted';
    }
}
