<?php

declare(strict_types=1);

namespace ControlPlane\Catalog\Authorization;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class CatalogOperator
{
    public function __construct(private Connection $connection) {}

    public function isAuthorized(string $controlUserId): bool
    {
        return $this->connection->fetchOne(<<<'SQL'
            SELECT 1
            FROM commercial.catalog_operators o
            JOIN commercial.control_users u ON u.id = o.control_user_id
            WHERE o.control_user_id = ?
                AND o.revoked_at IS NULL
                AND u.status = 'active'
            LIMIT 1
            SQL, [$controlUserId]) !== false;
    }

    public function assertAuthorized(string $controlUserId): void
    {
        if (!$this->isAuthorized($controlUserId)) {
            throw new AccessDeniedException('Catalog operator authorization required.');
        }
    }
}
