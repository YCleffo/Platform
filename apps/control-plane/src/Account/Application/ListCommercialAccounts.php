<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class ListCommercialAccounts
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function forUser(string $userId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($userId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $total = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_memberships m WHERE m.control_user_id = ? AND m.revoked_at IS NULL',
            [$userId],
        );

        $items = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.code, a.display_name, a.legal_name, a.status, a.onboarding_state, m.role, a.created_at, a.updated_at
             FROM commercial.commercial_account_memberships m
             JOIN commercial.commercial_accounts a ON a.id = m.commercial_account_id
             WHERE m.control_user_id = ? AND m.revoked_at IS NULL
             ORDER BY a.created_at DESC, a.id
             LIMIT ? OFFSET ?',
            [$userId, $limit, $offset],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
}
