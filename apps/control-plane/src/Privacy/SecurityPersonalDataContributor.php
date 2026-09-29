<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class SecurityPersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private Connection $connection)
    {
    }

    public function code(): string
    {
        return 'control_security';
    }

    public function export(string $controlUserId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
SELECT id, issued_at, expires_at, revoked_at, revoke_reason
FROM commercial.control_sessions WHERE control_user_id = ? ORDER BY issued_at, id
SQL, [$controlUserId]);
    }
}
