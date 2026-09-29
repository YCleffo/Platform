<?php

declare(strict_types=1);

namespace App\Core\Organization\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class OrganizationMembershipPersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string { return 'core.organization_membership'; }
    public function categories(): array { return ['identity']; }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT m.id::text AS "id", m.organization_id::text AS "organizationId",
                   o.code AS "organizationCode", m.status, m.joined_at::text AS "joinedAt",
                   m.left_at::text AS "leftAt", m.created_at::text AS "createdAt",
                   m.updated_at::text AS "updatedAt"
            FROM core.organization_memberships m
            JOIN core.organizations o ON o.id = m.organization_id
            WHERE m.person_id = CAST(:subject AS uuid)
            ORDER BY m.joined_at, m.id LIMIT 1001
            SQL, [':subject' => $context->subjectPersonId])->queryAll();
        return new PersonalDataSection($this->code(), $this->categories(), $rows);
    }
}
