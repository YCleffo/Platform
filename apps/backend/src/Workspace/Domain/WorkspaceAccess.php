<?php

declare(strict_types=1);

namespace App\Workspace\Domain;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Module\Application\ModuleService;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspaceAccess
{
    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private ModuleService $modules,
    ) {}

    public function assertAccess(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $permission = 'workspace.use',
    ): string {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        if (!$this->organizations->activeMember($personId, $orgId)) {
            throw new HttpError(403, 'organization_access_denied');
        }
        if (!$this->hasPermission($identity, $permission)) {
            throw new HttpError(403, 'access_denied');
        }
        return $personId;
    }

    public function hasPermission(AuthenticatedIdentity $identity, string $code): bool
    {
        return $this->permissions->hasPermission($identity->accountId, $code);
    }

    public function isSuperadmin(AuthenticatedIdentity $identity): bool
    {
        return $identity->hasRole('core.superadmin');
    }

    public function isOrgAdmin(AuthenticatedIdentity $identity): bool
    {
        return $identity->hasRole('core.superadmin')
            || $identity->hasRole('core.admin')
            || $this->hasPermission($identity, 'workspace.manage');
    }

    public function isModuleEnabled(string $moduleCode): bool
    {
        return $this->modules->isEnabled($moduleCode);
    }

    /** @return array<string, bool> */
    public function enabledModulesMap(): array
    {
        $map = [];
        foreach ($this->modules->list() as $mod) {
            $map[$mod->code] = $mod->enabled;
        }
        return $map;
    }

    /** @return list<string> */
    public function permittedOrganizationIds(string $personId): array
    {
        $rows = $this->db->createCommand(
            "SELECT m.organization_id::text AS id
             FROM core.organization_memberships m
             JOIN core.organizations o ON o.id = m.organization_id
             WHERE m.person_id = CAST(:person AS uuid)
               AND m.status = 'active'
               AND o.status = 'active'
             ORDER BY m.organization_id",
            [':person' => $personId],
        )->queryColumn();

        $out = [];
        foreach ($rows as $row) {
            if (is_string($row) && $row !== '') {
                $out[] = strtolower($row);
            }
        }
        return $out;
    }

    /**
     * Resolves Education actor roles within the organization without leaking or failing when Education is absent.
     *
     * @return array{teacher: bool, student: bool, parent: bool, admin: bool}
     */
    public function educationRoles(AuthenticatedIdentity $identity, string $organizationId, string $personId): array
    {
        $isAdmin = $this->isOrgAdmin($identity);
        if (!$this->isModuleEnabled('education') || !$this->tableExists('education.teachers')) {
            return ['teacher' => false, 'student' => false, 'parent' => false, 'admin' => $isAdmin];
        }

        $teacher = (bool) $this->db->createCommand(
            'SELECT EXISTS(
                SELECT 1 FROM education.teachers
                WHERE organization_id = CAST(:org AS uuid)
                  AND person_id = CAST(:person AS uuid)
            )',
            [':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        $student = (bool) $this->db->createCommand(
            'SELECT EXISTS(
                SELECT 1 FROM education.students s
                LEFT JOIN education.student_accounts sa
                  ON sa.student_id = s.id AND sa.organization_id = s.organization_id AND sa.revoked_at IS NULL
                WHERE s.organization_id = CAST(:org AS uuid)
                  AND s.person_id = CAST(:person AS uuid)
                  AND (sa.account_id = CAST(:account AS uuid) OR sa.account_id IS NULL)
            )',
            [':org' => $organizationId, ':person' => $personId, ':account' => $identity->accountId],
        )->queryScalar();

        $parent = false;
        if ($this->hasPermission($identity, 'education.parent.use') && $this->tableExists('education.parent_accounts')) {
            $parent = (bool) $this->db->createCommand(
                "SELECT EXISTS(
                    SELECT 1 FROM education.parent_accounts pa
                    JOIN education.student_guardians sg
                      ON sg.organization_id = pa.organization_id
                     AND sg.guardian_person_id = pa.guardian_person_id
                    WHERE pa.organization_id = CAST(:org AS uuid)
                      AND pa.guardian_person_id = CAST(:person AS uuid)
                      AND pa.account_id = CAST(:account AS uuid)
                      AND pa.revoked_at IS NULL
                      AND sg.status = 'confirmed'
                )",
                [':org' => $organizationId, ':person' => $personId, ':account' => $identity->accountId],
            )->queryScalar();
        }

        return [
            'teacher' => $teacher,
            'student' => $student,
            'parent' => $parent,
            'admin' => $isAdmin,
        ];
    }

    public function tableExists(string $schemaTable): bool
    {
        $res = $this->db->createCommand('SELECT to_regclass(:tbl) IS NOT NULL', [':tbl' => $schemaTable])->queryScalar();
        return in_array($res, [true, 1, '1', 't'], true);
    }

    /** @return list<string> */
    public function availablePermissionCodes(AuthenticatedIdentity $identity): array
    {
        $codes = [];
        foreach ($this->permissions->getPermissions($identity->accountId) as $perm) {
            $codes[] = $perm->code;
        }
        return array_values(array_unique($codes));
    }
}
