<?php

declare(strict_types=1);

namespace ControlPlane\Account\Authorization;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AccountAuthorization
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'owner' => ['account.read', 'account.manage', 'membership.manage', 'ownership.manage', 'billing.manage', 'technical.manage'],
        'admin' => ['account.read', 'account.manage', 'membership.manage', 'technical.manage'],
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
}
