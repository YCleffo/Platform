<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CurrentUser
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function profile(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, status, email_verified_at, last_login_at, created_at, updated_at FROM commercial.control_users WHERE id = ?',
            [$userId],
        );
        if ($row === false) {
            throw new \DomainException('User was not found.');
        }

        return $row;
    }
}
