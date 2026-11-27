<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Application\ChatContextPolicyInterface;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DefaultChatContextPolicy implements ChatContextPolicyInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
    ) {}

    public function canCreateRoomWith(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomType,
        array $memberPersonIds,
        ?string $contextType = null,
        ?string $contextId = null,
    ): bool {
        $actorPerson = $identity->personId;
        if ($actorPerson === null || !$this->organizations->activeMember($actorPerson, $organizationId)) {
            return false;
        }
        foreach ($memberPersonIds as $pid) {
            if (!is_string($pid) || !$this->organizations->activeMember($pid, $organizationId)) {
                return false;
            }
        }
        return true;
    }

    public function listAvailableContacts(
        AuthenticatedIdentity $identity,
        string $organizationId,
        ?string $query = null,
    ): array {
        $actorPerson = $identity->personId;
        if ($actorPerson === null || !$this->organizations->activeMember($actorPerson, $organizationId)) {
            return [];
        }

        $searchSql = '';
        $params = [':org' => $organizationId, ':self' => $actorPerson];
        if ($query !== null && trim($query) !== '') {
            $searchSql = " AND (p.display_name ILIKE :q OR p.given_name ILIKE :q OR p.family_name ILIKE :q)";
            $params[':q'] = '%' . trim($query) . '%';
        }

        $rows = $this->db->createCommand(
            "SELECT DISTINCT
                p.id::text AS id,
                p.id::text AS \"personId\",
                COALESCE(NULLIF(p.display_name, ''), NULLIF(CONCAT_WS(' ', p.given_name, p.family_name), ''), 'Member') AS name,
                COALESCE(
                    (SELECT r.code FROM core.accounts a
                     JOIN core.account_roles ar ON ar.account_id = a.id
                     JOIN core.roles r ON r.id = ar.role_id
                     WHERE a.person_id = p.id
                     ORDER BY r.code LIMIT 1),
                    'member'
                ) AS role
             FROM core.organization_memberships om
             JOIN core.persons p ON p.id = om.person_id
             WHERE om.organization_id = CAST(:org AS uuid)
               AND om.status = 'active'
               AND p.status = 'active'
               AND p.id <> CAST(:self AS uuid)
               {$searchSql}
             ORDER BY name ASC
             LIMIT 100",
            $params,
        )->queryAll();

        $out = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'personId' => (string) $r['personId'],
                'name' => (string) $r['name'],
                'role' => (string) $r['role'],
            ];
        }
        return $out;
    }
}
