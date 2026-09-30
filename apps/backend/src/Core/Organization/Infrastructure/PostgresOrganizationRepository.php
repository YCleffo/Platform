<?php

declare(strict_types=1);

namespace App\Core\Organization\Infrastructure;

use App\Core\Organization\Application\Organization;
use App\Core\Organization\Application\OrganizationMembership;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Privacy\Application\PrivacyResourceUsePolicy;
use Yiisoft\Db\Connection\ConnectionInterface;
use DomainException;

final readonly class PostgresOrganizationRepository implements OrganizationRepository
{
    public function __construct(private ConnectionInterface $db,
        private PrivacyResourceUsePolicy $privacy) {}

    /** @return list<Organization> */
    public function listFor(?string $personId): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT o.id::text, o.code, o.name, o.status, o.created_at::text, o.updated_at::text, o.archived_at::text
            FROM core.organizations o
            WHERE o.status = 'active' AND (
                CAST(:person AS uuid) IS NULL OR EXISTS (
                    SELECT 1 FROM core.organization_memberships m
                    JOIN core.persons p ON p.id = m.person_id
                    WHERE m.organization_id = o.id AND m.person_id = CAST(:person AS uuid)
                      AND m.status = 'active' AND p.status = 'active' AND p.archived_at IS NULL
                )
            ) ORDER BY o.code LIMIT 1000
            SQL, [':person' => $personId])->queryAll();
        $organizations = array_map(self::organization(...), $rows);
        return $personId === null ? $organizations : array_values(array_filter($organizations,
            fn(Organization $organization): bool => $this->activeMember($personId, $organization->id)));
    }

    public function create(string $code, string $name): ?Organization
    {
        $row = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.organizations (code, name) VALUES (:code, :name)
            ON CONFLICT (code) DO NOTHING
            RETURNING id::text, code, name, status, created_at::text, updated_at::text, archived_at::text
            SQL, [':code' => $code, ':name' => $name])->queryOne();
        return is_array($row) ? self::organization($row) : null;
    }

    public function activeOrganizationExists(string $id): bool
    {
        return (bool) $this->db->createCommand(
            "SELECT EXISTS(SELECT 1 FROM core.organizations WHERE id = CAST(:id AS uuid) AND status = 'active')",
            [':id' => $id],
        )->queryScalar();
    }

    public function activePerson(string $personId): bool
    {
        return (bool) $this->db->createCommand(
            "SELECT EXISTS(SELECT 1 FROM core.persons WHERE id = CAST(:id AS uuid) AND status = 'active' AND archived_at IS NULL)",
            [':id' => $personId],
        )->queryScalar();
    }

    public function activeMember(string $personId, string $organizationId): bool
    {
        $membershipId = $this->db->createCommand(<<<'SQL'
            SELECT id::text FROM core.organization_memberships
            WHERE person_id = CAST(:person AS uuid) AND organization_id = CAST(:organization AS uuid)
                AND status = 'active' LIMIT 1
            SQL, [':person' => $personId, ':organization' => $organizationId])->queryScalar();
        if (is_string($membershipId) && !$this->privacy->allowed($personId,
            'core.organization_membership', $membershipId, 'identity')) {
            return false;
        }
        return (bool) $this->db->createCommand(<<<'SQL'
            SELECT EXISTS(SELECT 1 FROM core.organization_memberships m
                JOIN core.organizations o ON o.id = m.organization_id
                JOIN core.persons p ON p.id = m.person_id
                WHERE m.person_id = CAST(:person AS uuid)
                AND m.organization_id = CAST(:organization AS uuid)
                AND m.status = 'active' AND o.status = 'active'
                AND p.status = 'active' AND p.archived_at IS NULL)
            SQL, [':person' => $personId, ':organization' => $organizationId])->queryScalar();
    }

    /** @return list<OrganizationMembership> */
    public function members(string $organizationId): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT id::text, organization_id::text, person_id::text, status,
                   joined_at::text, left_at::text, created_at::text, updated_at::text
            FROM core.organization_memberships WHERE organization_id = CAST(:organization AS uuid)
            ORDER BY joined_at, id LIMIT 1000
            SQL, [':organization' => $organizationId])->queryAll();
        return array_values(array_filter(array_map(self::membership(...), $rows),
            fn(OrganizationMembership $membership): bool => $this->privacy->allowed(
                $membership->personId, 'core.organization_membership', $membership->id, 'identity')));
    }

    public function addMember(string $organizationId, string $personId): ?OrganizationMembership
    {
        if (!$this->privacy->allowed($personId, 'core.organization_membership',
            $organizationId, 'identity')) {
            throw new DomainException('Organization membership processing is restricted.');
        }
        $row = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.organization_memberships (organization_id, person_id)
            SELECT o.id, p.id FROM core.organizations o CROSS JOIN core.persons p
            WHERE o.id = CAST(:organization AS uuid) AND o.status = 'active'
              AND p.id = CAST(:person AS uuid) AND p.status = 'active' AND p.archived_at IS NULL
            ON CONFLICT (organization_id, person_id) WHERE status = 'active' DO NOTHING
            RETURNING id::text, organization_id::text, person_id::text, status,
                      joined_at::text, left_at::text, created_at::text, updated_at::text
            SQL, [':organization' => $organizationId, ':person' => $personId])->queryOne();
        return is_array($row) ? self::membership($row) : null;
    }

    public function leave(string $organizationId, string $personId): ?OrganizationMembership
    {
        $row = $this->db->createCommand(<<<'SQL'
            UPDATE core.organization_memberships SET status = 'left', left_at = now(), updated_at = now()
            WHERE organization_id = CAST(:organization AS uuid) AND person_id = CAST(:person AS uuid)
              AND status = 'active'
            RETURNING id::text, organization_id::text, person_id::text, status,
                      joined_at::text, left_at::text, created_at::text, updated_at::text
            SQL, [':organization' => $organizationId, ':person' => $personId])->queryOne();
        return is_array($row) ? self::membership($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function organization(array $row): Organization
    {
        return new Organization((string) $row['id'], (string) $row['code'], (string) $row['name'],
            (string) $row['status'], (string) $row['created_at'], (string) $row['updated_at'],
            $row['archived_at'] === null ? null : (string) $row['archived_at']);
    }

    /** @param array<string, mixed> $row */
    private static function membership(array $row): OrganizationMembership
    {
        return new OrganizationMembership((string) $row['id'], (string) $row['organization_id'],
            (string) $row['person_id'], (string) $row['status'], (string) $row['joined_at'],
            $row['left_at'] === null ? null : (string) $row['left_at'],
            (string) $row['created_at'], (string) $row['updated_at']);
    }
}
