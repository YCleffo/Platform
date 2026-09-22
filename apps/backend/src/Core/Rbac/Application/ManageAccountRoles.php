<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Shared\Application\ConflictException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ManageAccountRoles
{
    public function __construct(
        private RoleRepository $roles,
        private AccountSecurityRepository $accounts,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    /** @return list<RoleView> */
    public function list(string $accountId): array
    {
        return $this->roles->findByAccountId($accountId);
    }

    public function assign(
        string $actorAccountId,
        string $accountId,
        string $roleId,
        ClientContext $context,
    ): ?bool {
        return $this->db->transaction(function () use (
            $actorAccountId,
            $accountId,
            $roleId,
            $context,
        ): ?bool {
            $role = $this->roles->findById($roleId);
            if ($role === null || $this->accounts->findSecurityState($accountId) === null) {
                return null;
            }
            $this->assertProtectedRoleAllowed($actorAccountId, $role);
            $changed = $this->roles->assign($accountId, $roleId, $actorAccountId);
            if ($changed) {
                $this->record(SecurityAction::ROLE_ASSIGNED, $actorAccountId, $accountId, $role, $context);
            }

            return $changed;
        });
    }

    public function revoke(
        string $actorAccountId,
        string $accountId,
        string $roleId,
        ClientContext $context,
    ): ?bool {
        return $this->db->transaction(function () use (
            $actorAccountId,
            $accountId,
            $roleId,
            $context,
        ): ?bool {
            $role = $this->roles->findById($roleId);
            if ($role === null || $this->accounts->findSecurityState($accountId) === null) {
                return null;
            }
            $this->assertProtectedRoleAllowed($actorAccountId, $role);
            $changed = $this->roles->revoke($accountId, $roleId);
            if ($changed) {
                $this->record(SecurityAction::ROLE_REVOKED, $actorAccountId, $accountId, $role, $context);
            }

            return $changed;
        });
    }

    private function assertProtectedRoleAllowed(string $actorAccountId, RoleView $role): void
    {
        if ($role->isProtected && !$this->roles->accountHasRoleCode($actorAccountId, 'core.superadmin')) {
            throw new ConflictException('Protected roles can only be managed by a super administrator.');
        }
    }

    private function record(
        string $action,
        string $actorAccountId,
        string $accountId,
        RoleView $role,
        ClientContext $context,
    ): void {
        $this->audit->record(new SecurityAuditCommand(
            action: $action,
            actorAccountId: $actorAccountId,
            resourceType: 'core.account_role',
            resourceId: $accountId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['roleId' => $role->id, 'roleCode' => $role->code],
        ));
    }
}
