<?php

declare(strict_types=1);

namespace ControlPlane\Account\Authorization;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AccountAuthorization
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'owner' => ['account.read', 'account.manage', 'membership.read', 'membership.manage', 'ownership.manage', 'billing.manage', 'technical.manage'],
        'admin' => ['account.read', 'account.manage', 'membership.read', 'membership.manage', 'technical.manage'],
        'billing' => ['account.read', 'billing.manage'],
        'technical' => ['account.read', 'technical.manage'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function requirePermission(string $userId, string $accountId, string $permission): string
    {
        $role = $this->connection->fetchOne('SELECT role FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ? AND revoked_at IS NULL', [$userId, $accountId]);
        if (!is_string($role) || !in_array($permission, self::PERMISSIONS[$role] ?? [], true)) {
            throw new AccessDeniedException('Account resource is not available.');
        }

        return $role;
    }

    public static function assertRole(string $role): void
    {
        if (!array_key_exists($role, self::PERMISSIONS)) {
            throw new \InvalidArgumentException('Invalid account role.');
        }
    }

    /**
     * Read-only capabilities derived from the single RBAC source of truth.
     *
     * @return array{read_account: bool, view_members: bool, manage_members: bool, manage_ownership: bool, manage_billing: bool, manage_technical: bool}
     */
    public static function permissionsForRole(string $role): array
    {
        $permissions = self::PERMISSIONS[$role] ?? [];

        return [
            'read_account' => in_array('account.read', $permissions, true),
            'view_members' => in_array('membership.read', $permissions, true),
            'manage_members' => in_array('membership.manage', $permissions, true),
            'manage_ownership' => in_array('ownership.manage', $permissions, true),
            'manage_billing' => in_array('billing.manage', $permissions, true),
            'manage_technical' => in_array('technical.manage', $permissions, true),
        ];
    }
}
