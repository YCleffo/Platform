<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class IdentityPersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private Connection $connection)
    {
    }

    public function code(): string
    {
        return 'control_identity';
    }

    public function export(string $controlUserId): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, email, status, created_at, archived_at, email_verified_at, last_login_at
FROM commercial.control_users WHERE id = ?
SQL, [$controlUserId]);

        return $row === false ? [] : [$row];
    }
}
