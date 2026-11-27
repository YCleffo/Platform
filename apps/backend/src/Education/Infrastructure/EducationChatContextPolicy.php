<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Chat\Application\ChatContextPolicyInterface;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Enforces Education domain contact isolation in Universal Chat:
 * - Teachers can message colleague teachers, students in groups they teach, and confirmed parents of those students.
 * - Students can message teachers of their active (non-archived) groups.
 * - Parents can message teachers of active groups of their confirmed children.
 * - Arbitrary non-admin org members or unlinked students/parents cannot bypass isolation.
 */
final readonly class EducationChatContextPolicy implements ChatContextPolicyInterface
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
        $cleanTargetIds = [];
        foreach ($memberPersonIds as $pid) {
            if (!is_string($pid) || !$this->organizations->activeMember($pid, $organizationId)) {
                return false;
            }
            if (strcasecmp($pid, $actorPerson) !== 0) {
                $cleanTargetIds[] = strtolower($pid);
            }
        }
        $cleanTargetIds = array_values(array_unique($cleanTargetIds));

        if (!$this->isEducationModuleEnabled()) {
            return true;
        }

        $roles = $this->actorRoles($identity->accountId, $actorPerson, $organizationId);
        if ($roomType === 'organization' && !$roles['teacher'] && !$roles['admin']) {
            return false;
        }
        if ($roles['admin'] && !$roles['teacher'] && !$roles['student'] && !$roles['parent']) {
            return true;
        }
        if (!$roles['teacher'] && !$roles['student'] && !$roles['parent']) {
            return false;
        }
        if ($cleanTargetIds === []) {
            return true;
        }

        $allowed = $this->queryAllowedContacts($organizationId, $actorPerson, $roles, null, null);
        $allowedSet = [];
        foreach ($allowed as $contact) {
            $allowedSet[strtolower($contact['personId'])] = true;
        }
        foreach ($cleanTargetIds as $targetPid) {
            if (!isset($allowedSet[$targetPid])) {
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

        if (!$this->isEducationModuleEnabled()) {
            return $this->queryAllOrgMembers($organizationId, $actorPerson, $query);
        }

        $roles = $this->actorRoles($identity->accountId, $actorPerson, $organizationId);
        if ($roles['admin'] && !$roles['teacher'] && !$roles['student'] && !$roles['parent']) {
            return $this->queryAllOrgMembers($organizationId, $actorPerson, $query);
        }
        if (!$roles['teacher'] && !$roles['student'] && !$roles['parent']) {
            return [];
        }

        return $this->queryAllowedContacts($organizationId, $actorPerson, $roles, $query, 100);
    }

    private function isEducationModuleEnabled(): bool
    {
        $enabled = $this->db->createCommand(
            "SELECT enabled FROM core.modules WHERE code = 'education'",
        )->queryScalar();
        return (bool) $enabled;
    }

    /** @return array{teacher: bool, student: bool, parent: bool, admin: bool} */
    private function actorRoles(string $accountId, string $personId, string $organizationId): array
    {
        $row = $this->db->createCommand(
            "SELECT
                EXISTS (
                    SELECT 1 FROM education.teachers t
                    WHERE t.organization_id = CAST(:org AS uuid) AND t.person_id = CAST(:person AS uuid)
                ) AS is_teacher,
                EXISTS (
                    SELECT 1 FROM education.students s
                    WHERE s.organization_id = CAST(:org AS uuid) AND s.person_id = CAST(:person AS uuid)
                ) AS is_student,
                EXISTS (
                    SELECT 1 FROM education.parent_accounts pa
                    JOIN education.student_guardians sg
                      ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                    WHERE pa.organization_id = CAST(:org AS uuid)
                      AND pa.guardian_person_id = CAST(:person AS uuid)
                      AND pa.revoked_at IS NULL
                      AND sg.status = 'confirmed'
                ) AS is_parent,
                EXISTS (
                    SELECT 1 FROM core.account_roles ar
                    JOIN core.roles r ON r.id = ar.role_id
                    WHERE ar.account_id = CAST(:acc AS uuid)
                      AND r.code IN ('core.admin', 'core.superadmin')
                ) AS is_admin",
            [':org' => $organizationId, ':person' => $personId, ':acc' => $accountId],
        )->queryOne();

        if (!is_array($row)) {
            return ['teacher' => false, 'student' => false, 'parent' => false, 'admin' => false];
        }
        return [
            'teacher' => (bool) ($row['is_teacher'] ?? false),
            'student' => (bool) ($row['is_student'] ?? false),
            'parent' => (bool) ($row['is_parent'] ?? false),
            'admin' => (bool) ($row['is_admin'] ?? false),
        ];
    }

    /**
     * @param array{teacher: bool, student: bool, parent: bool, admin: bool} $roles
     * @return list<array{id: string, personId: string, name: string, role: string, contextType: ?string, contextId: ?string, contextName: ?string}>
     */
    private function queryAllowedContacts(
        string $organizationId,
        string $actorPerson,
        array $roles,
        ?string $query,
        ?int $limit,
    ): array {
        $unions = [];

        if ($roles['teacher']) {
            // 1. Colleague teachers in the same organization
            $unions[] = "SELECT
                    t.person_id::text AS person_id,
                    'teacher'::text AS role,
                    NULL::text AS context_type,
                    NULL::text AS context_id,
                    NULL::text AS context_name,
                    1 AS priority
                 FROM education.teachers t
                 WHERE t.organization_id = CAST(:org AS uuid)
                   AND t.person_id <> CAST(:self AS uuid)";

            // 2. Students in non-archived groups taught/owned by this teacher
            $unions[] = "SELECT
                    s.person_id::text AS person_id,
                    'student'::text AS role,
                    'group'::text AS context_type,
                    g.id::text AS context_id,
                    g.name AS context_name,
                    2 AS priority
                 FROM education.groups g
                 JOIN education.group_students gs ON gs.group_id = g.id AND gs.organization_id = g.organization_id
                 JOIN education.students s ON s.id = gs.student_id AND s.organization_id = g.organization_id
                 WHERE g.organization_id = CAST(:org AS uuid)
                   AND g.archived_at IS NULL
                   AND (
                       g.owner_person_id = CAST(:self AS uuid)
                       OR EXISTS (
                           SELECT 1 FROM education.group_teachers gt
                           JOIN education.teachers tt ON tt.organization_id = gt.organization_id AND tt.account_id = gt.account_id
                           WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND tt.person_id = CAST(:self AS uuid)
                       )
                   )";

            // 3. Confirmed parents of students in groups taught/owned by this teacher
            $unions[] = "SELECT
                    sg.guardian_person_id::text AS person_id,
                    'parent'::text AS role,
                    'student'::text AS context_type,
                    s.id::text AS context_id,
                    COALESCE(NULLIF(sp.display_name, ''), NULLIF(CONCAT_WS(' ', sp.given_name, sp.family_name), ''), 'Student') AS context_name,
                    3 AS priority
                 FROM education.groups g
                 JOIN education.group_students gs ON gs.group_id = g.id AND gs.organization_id = g.organization_id
                 JOIN education.students s ON s.id = gs.student_id AND s.organization_id = g.organization_id
                 LEFT JOIN core.persons sp ON sp.id = s.person_id
                 JOIN education.student_guardians sg ON sg.student_id = s.id AND sg.organization_id = g.organization_id
                 JOIN education.parent_accounts pa ON pa.organization_id = g.organization_id AND pa.guardian_person_id = sg.guardian_person_id
                 WHERE g.organization_id = CAST(:org AS uuid)
                   AND g.archived_at IS NULL
                   AND sg.status = 'confirmed'
                   AND pa.revoked_at IS NULL
                   AND (
                       g.owner_person_id = CAST(:self AS uuid)
                       OR EXISTS (
                           SELECT 1 FROM education.group_teachers gt
                           JOIN education.teachers tt ON tt.organization_id = gt.organization_id AND tt.account_id = gt.account_id
                           WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND tt.person_id = CAST(:self AS uuid)
                       )
                   )";
        }

        if ($roles['student']) {
            // Teachers of active groups the student is enrolled in
            $unions[] = "SELECT
                    t.person_id::text AS person_id,
                    'teacher'::text AS role,
                    'group'::text AS context_type,
                    g.id::text AS context_id,
                    g.name AS context_name,
                    1 AS priority
                 FROM education.students s
                 JOIN education.group_students gs ON gs.student_id = s.id AND gs.organization_id = s.organization_id
                 JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id
                 JOIN education.teachers t ON t.organization_id = g.organization_id AND (
                     t.person_id = g.owner_person_id
                     OR EXISTS (
                         SELECT 1 FROM education.group_teachers gt
                         WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                     )
                 )
                 WHERE s.organization_id = CAST(:org AS uuid)
                   AND s.person_id = CAST(:self AS uuid)
                   AND g.archived_at IS NULL";
        }

        if ($roles['parent']) {
            // Teachers of active groups of the parent's confirmed children
            $unions[] = "SELECT
                    t.person_id::text AS person_id,
                    'teacher'::text AS role,
                    'student'::text AS context_type,
                    s.id::text AS context_id,
                    COALESCE(NULLIF(sp.display_name, ''), NULLIF(CONCAT_WS(' ', sp.given_name, sp.family_name), ''), 'Student') AS context_name,
                    1 AS priority
                 FROM education.parent_accounts pa
                 JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                 JOIN education.students s ON s.id = sg.student_id AND s.organization_id = sg.organization_id
                 LEFT JOIN core.persons sp ON sp.id = s.person_id
                 JOIN education.group_students gs ON gs.student_id = s.id AND gs.organization_id = s.organization_id
                 JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id
                 JOIN education.teachers t ON t.organization_id = g.organization_id AND (
                     t.person_id = g.owner_person_id
                     OR EXISTS (
                         SELECT 1 FROM education.group_teachers gt
                         WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                     )
                 )
                 WHERE pa.organization_id = CAST(:org AS uuid)
                   AND pa.guardian_person_id = CAST(:self AS uuid)
                   AND pa.revoked_at IS NULL
                   AND sg.status = 'confirmed'
                   AND g.archived_at IS NULL";

            // Organization administration (core.admin) in the same organization
            $unions[] = "SELECT
                    a.person_id::text AS person_id,
                    'admin'::text AS role,
                    NULL::text AS context_type,
                    NULL::text AS context_id,
                    NULL::text AS context_name,
                    2 AS priority
                 FROM core.organization_memberships om
                 JOIN core.accounts a ON a.person_id = om.person_id AND a.status = 'active' AND a.disabled_at IS NULL AND a.archived_at IS NULL
                 JOIN core.account_roles ar ON ar.account_id = a.id
                 JOIN core.roles r ON r.id = ar.role_id AND r.code = 'core.admin'
                 WHERE om.organization_id = CAST(:org AS uuid)
                   AND om.status = 'active'
                   AND a.person_id <> CAST(:self AS uuid)";
        }

        if ($unions === []) {
            return [];
        }

        $params = [':org' => $organizationId, ':self' => $actorPerson];
        $searchSql = '';
        if ($query !== null && trim($query) !== '') {
            $searchSql = ' AND (p.display_name ILIKE :q OR p.given_name ILIKE :q OR p.family_name ILIKE :q OR c.context_name ILIKE :q)';
            $params[':q'] = '%' . trim($query) . '%';
        }
        $limitSql = $limit !== null ? ' LIMIT ' . max(1, $limit) : '';

        $sql = "WITH raw_contacts AS (" . implode(' UNION ALL ', $unions) . "),
                deduped AS (
                    SELECT DISTINCT ON (c.person_id)
                        c.person_id,
                        c.role,
                        c.context_type,
                        c.context_id,
                        c.context_name
                    FROM raw_contacts c
                    JOIN core.organization_memberships om
                      ON om.organization_id = CAST(:org AS uuid)
                     AND om.person_id = CAST(c.person_id AS uuid)
                     AND om.status = 'active'
                    JOIN core.persons p ON p.id = om.person_id AND p.status = 'active'
                    WHERE p.id <> CAST(:self AS uuid)
                    ORDER BY c.person_id, c.priority ASC
                )
                SELECT
                    d.person_id AS id,
                    d.person_id AS \"personId\",
                    COALESCE(NULLIF(p.display_name, ''), NULLIF(CONCAT_WS(' ', p.given_name, p.family_name), ''), 'Member') AS name,
                    d.role,
                    d.context_type AS \"contextType\",
                    d.context_id AS \"contextId\",
                    d.context_name AS \"contextName\"
                FROM deduped d
                JOIN core.persons p ON p.id = CAST(d.person_id AS uuid)
                LEFT JOIN LATERAL (SELECT d.context_name) c ON true
                WHERE 1=1 {$searchSql}
                ORDER BY name ASC{$limitSql}";

        $rows = $this->db->createCommand($sql, $params)->queryAll();

        $out = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'personId' => (string) $r['personId'],
                'name' => (string) $r['name'],
                'role' => (string) $r['role'],
                'contextType' => isset($r['contextType']) ? (string) $r['contextType'] : null,
                'contextId' => isset($r['contextId']) ? (string) $r['contextId'] : null,
                'contextName' => isset($r['contextName']) ? (string) $r['contextName'] : null,
            ];
        }
        return $out;
    }

    /** @return list<array{id: string, personId: string, name: string, role: string}> */
    private function queryAllOrgMembers(string $organizationId, string $actorPerson, ?string $query): array
    {
        $searchSql = '';
        $params = [':org' => $organizationId, ':self' => $actorPerson];
        if ($query !== null && trim($query) !== '') {
            $searchSql = ' AND (p.display_name ILIKE :q OR p.given_name ILIKE :q OR p.family_name ILIKE :q)';
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
