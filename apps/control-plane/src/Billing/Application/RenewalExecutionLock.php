<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use Doctrine\DBAL\Connection;

/** Session lock for the narrow provider execution window; never opens a transaction. */
final class RenewalExecutionLock
{
    public static function acquire(Connection $connection, string $subscriptionId): void
    {
        $connection->executeQuery('SELECT pg_advisory_lock(hashtextextended(?, 0))', ['renewal-execution:' . $subscriptionId]);
    }

    public static function release(Connection $connection, string $subscriptionId): void
    {
        $connection->executeQuery('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['renewal-execution:' . $subscriptionId]);
    }
}
