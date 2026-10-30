<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final readonly class GetCommercialAccount
{
    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
    ) {
    }

    /** @return array<string, mixed> */
    public function forUser(string $userId, string $accountId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId)) {
            throw new AccessDeniedException('Account resource is not available.');
        }
        $role = $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $account = $this->connection->fetchAssociative(
            'SELECT id, code, display_name, legal_name, status, onboarding_state, created_at, updated_at FROM commercial.commercial_accounts WHERE id = ?',
            [$accountId],
        );
        if ($account === false) {
            throw new AccessDeniedException('Account resource is not available.');
        }
        $account['role'] = $role;
        $account['permissions'] = AccountAuthorization::permissionsForRole($role);

        return $account;
    }
}
