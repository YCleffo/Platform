<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final readonly class ListAccountMemberships
{
    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function forUser(string $userId, string $accountId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId)) {
            throw new AccessDeniedException('Account resource is not available.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'membership.read');

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $total = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_memberships m WHERE m.commercial_account_id = ? AND m.revoked_at IS NULL',
            [$accountId],
        );

        $items = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.control_user_id AS user_id, u.email, m.role, m.created_at
             FROM commercial.commercial_account_memberships m
             JOIN commercial.control_users u ON u.id = m.control_user_id
             WHERE m.commercial_account_id = ? AND m.revoked_at IS NULL
             ORDER BY m.created_at ASC, m.id
             LIMIT ? OFFSET ?',
            [$accountId, $limit, $offset],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
}
