<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class ControlPrivacyOperator
{
    public function __construct(private Connection $connection) {}

    public function assertAuthorized(string $userId): void
    {
        if ($this->connection->fetchOne(<<<'SQL'
SELECT 1 FROM commercial.privacy_operators o JOIN commercial.control_users u ON u.id = o.control_user_id
WHERE o.control_user_id = ? AND o.authorization_reference <> '' AND u.status = 'active' LIMIT 1
SQL, [$userId]) === false) {
            throw new AccessDeniedException('Privacy operator authorization required.');
        }
    }
}
