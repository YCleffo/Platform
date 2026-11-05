<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Shared\Application\ConflictException;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class ManageCustomRoles
{
    public function __construct(
        private ConnectionInterface $db,
        private RoleRepository $roles,
        private PermissionService $permissions,
        private SecurityAuditService $audit,
    ) {}

    public function create(string $actor, string $code, string $name, ?string $description, ClientContext $context): RoleView
    {
        self::validate($code, $name, $description);
        try {
            return $this->db->transaction(function () use ($actor, $code, $name, $description, $context): RoleView {
                $this->authorize($actor);
                if ($this->db->createCommand('SELECT 1 FROM core.roles WHERE code = :code', [':code' => $code])->queryScalar()) {
                    throw new ConflictException('Role code already exists.');
                }
                $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.roles (code, name, description, is_system, is_protected)
                VALUES (:code, :name, :description, false, false) RETURNING id::text
                SQL, [':code' => $code, ':name' => $name, ':description' => $description])->queryScalar();
                if (!is_string($id)) {
                    throw new \LogicException('Created role identity is unavailable.');
                }
                $this->audit($actor, $id, SecurityAction::ROLE_CREATED, $context);
                return $this->roles->findById($id) ?? throw new \LogicException('Created role is unavailable.');
            });
        } catch (IntegrityException $exception) {
            throw new ConflictException('Role code already exists.', previous: $exception);
        }
    }

    public function update(string $actor, string $id, string $code, string $name, ?string $description, ClientContext $context): ?RoleView
    {
        try {
            return $this->db->transaction(function () use ($actor, $id, $code, $name, $description, $context): ?RoleView {
                $this->authorize($actor);
                $row = $this->lock($id);
                if ($row === null) {
                    return null;
                }
                self::assertMutable($row);
                self::validate($code, $name, $description);
                $this->db->createCommand(<<<'SQL'
                UPDATE core.roles SET code = :code, name = :name, description = :description, updated_at = now()
                WHERE id = CAST(:id AS uuid)
                SQL, [':id' => $id, ':code' => $code, ':name' => $name, ':description' => $description])->execute();
                $this->audit($actor, $id, SecurityAction::ROLE_UPDATED, $context);
                return $this->roles->findById($id);
            });
        } catch (IntegrityException $exception) {
            throw new ConflictException('Role code already exists.', previous: $exception);
        }
    }

    public function delete(string $actor, string $id, ClientContext $context): bool
    {
        return $this->db->transaction(function () use ($actor, $id, $context): bool {
            $this->authorize($actor);
            $row = $this->lock($id);
            if ($row === null) {
                return false;
            }
            self::assertMutable($row);
            if ((int) $this->db->createCommand('SELECT count(*) FROM core.account_roles WHERE role_id = CAST(:id AS uuid)', [':id' => $id])->queryScalar() > 0) {
                throw new ConflictException('Assigned role cannot be deleted.');
            }
            $this->db->createCommand('DELETE FROM core.roles WHERE id = CAST(:id AS uuid)', [':id' => $id])->execute();
            $this->audit($actor, $id, SecurityAction::ROLE_DELETED, $context);
            return true;
        });
    }

    /** @return list<string>|null */
    public function permissionCodes(string $id): ?array
    {
        if ($this->roles->findById($id) === null) {
            return null;
        }
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT p.code FROM core.role_permissions rp
            JOIN core.permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = CAST(:id AS uuid) ORDER BY p.code
            SQL, [':id' => $id])->queryColumn();
        return array_values(array_map(strval(...), $rows));
    }

    /** @param list<mixed> $codes
     *  @return list<string>|null
     */
    public function replacePermissions(string $actor, string $id, array $codes, ClientContext $context): ?array
    {
        if (count($codes) > 100) {
            throw new DomainException('Permission list is invalid.');
        }
        $validCodes = [];
        foreach ($codes as $code) {
            if (!is_string($code) || !preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code)) {
                throw new DomainException('Permission code is invalid.');
            }
            $validCodes[] = $code;
        }
        if (count($validCodes) !== count(array_unique($validCodes))) {
            throw new DomainException('Permission list contains duplicates.');
        }
        return $this->db->transaction(function () use ($actor, $id, $validCodes, $context): ?array {
            $this->authorize($actor);
            $row = $this->lock($id);
            if ($row === null) {
                return null;
            }
            self::assertMutable($row);
            $permissionIds = [];
            foreach ($validCodes as $code) {
                $permissionId = $this->db->createCommand('SELECT id::text FROM core.permissions WHERE code = :code', [':code' => $code])->queryScalar();
                if (!is_string($permissionId)) {
                    throw new DomainException('Unknown permission code.');
                }
                $permissionIds[] = $permissionId;
            }
            $this->db->createCommand('DELETE FROM core.role_permissions WHERE role_id = CAST(:id AS uuid)', [':id' => $id])->execute();
            foreach ($permissionIds as $permissionId) {
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.role_permissions (role_id, permission_id, granted_by_account_id)
                    VALUES (CAST(:role AS uuid), CAST(:permission AS uuid), CAST(:actor AS uuid))
                    SQL, [':role' => $id, ':permission' => $permissionId, ':actor' => $actor])->execute();
            }
            $this->audit($actor, $id, SecurityAction::ROLE_PERMISSIONS_UPDATED, $context);
            return $this->permissionCodes($id);
        });
    }

    /** @return array<string, mixed>|null */
    private function lock(string $id): ?array
    {
        $row = $this->db->createCommand('SELECT id, is_system, is_protected FROM core.roles WHERE id = CAST(:id AS uuid) FOR UPDATE', [':id' => $id])->queryOne();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private static function assertMutable(array $row): void
    {
        if (self::boolean($row['is_system']) || self::boolean($row['is_protected'])) {
            throw new ConflictException('Protected or system role cannot be changed.');
        }
    }

    private function authorize(string $actor): void
    {
        if (!$this->permissions->hasPermission($actor, 'core.rbac.manage')) {
            throw new DomainException('RBAC management permission is required.');
        }
    }

    private function audit(string $actor, string $id, string $action, ClientContext $context): void
    {
        $this->audit->record(new SecurityAuditCommand(
            action: $action,
            actorAccountId: $actor,
            resourceType: 'core.role',
            resourceId: $id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
        ));
    }

    private static function validate(string $code, string $name, ?string $description): void
    {
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code)
            || strlen($code) > 120 || str_starts_with($code, 'core.')
            || trim($name) === '' || strlen($name) > 160 || preg_match('/[[:cntrl:]]/', $name)
            || ($description !== null && (strlen($description) > 2000 || preg_match('/[[:cntrl:]]/', $description)))) {
            throw new DomainException('Role definition is invalid.');
        }
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }
}
