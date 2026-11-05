<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class OrganizationService
{
    public function __construct(
        private OrganizationRepository $organizations,
        private AuditWriter $audit,
        private ConnectionInterface $db,
    ) {}

    /** @return list<Organization> */
    public function listFor(?string $personId): array
    {
        return $this->organizations->listFor($personId);
    }

    /** @return list<OrganizationMembership> */
    public function members(string $organizationId): array
    {
        return $this->organizations->members($organizationId);
    }

    public function create(string $code, string $name, string $actorAccountId): ?Organization
    {
        return $this->db->transaction(function () use ($code, $name, $actorAccountId): ?Organization {
            $organization = $this->organizations->create($code, $name);
            if ($organization !== null) {
                $this->record(
                    'organization.created',
                    'core.organization',
                    $organization->id,
                    $actorAccountId,
                    ['organizationId' => $organization->id, 'organizationCode' => $organization->code],
                );
            }
            return $organization;
        });
    }

    public function addMember(string $organizationId, string $personId, string $actorAccountId): ?OrganizationMembership
    {
        return $this->db->transaction(function () use ($organizationId, $personId, $actorAccountId): ?OrganizationMembership {
            $membership = $this->organizations->addMember($organizationId, $personId);
            if ($membership !== null) {
                $this->record(
                    'organization_membership.added',
                    'core.organization_membership',
                    $membership->id,
                    $actorAccountId,
                    ['organizationId' => $organizationId, 'personId' => $personId],
                );
            }
            return $membership;
        });
    }

    public function leave(string $organizationId, string $personId, string $actorAccountId): ?OrganizationMembership
    {
        return $this->db->transaction(function () use ($organizationId, $personId, $actorAccountId): ?OrganizationMembership {
            $membership = $this->organizations->leave($organizationId, $personId);
            if ($membership !== null) {
                $this->record(
                    'organization_membership.left',
                    'core.organization_membership',
                    $membership->id,
                    $actorAccountId,
                    ['organizationId' => $organizationId, 'personId' => $personId],
                );
            }
            return $membership;
        });
    }

    /** @param array<string, string> $metadata */
    private function record(string $action, string $resourceType, string $resourceId, string $actorAccountId, array $metadata): void
    {
        $this->audit->write(new WriteAuditEventCommand(
            actorType: 'account',
            actorAccountId: $actorAccountId,
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            metadata: $metadata,
        ));
    }
}
