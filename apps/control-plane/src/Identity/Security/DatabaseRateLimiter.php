<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

use Doctrine\DBAL\Connection;

final readonly class DatabaseRateLimiter
{
    public function __construct(private Connection $connection, private TokenCodec $tokens)
    {
    }

    public function consume(string $scope, string $key, int $limit, int $windowSeconds): void
    {
        $now = time();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $startedAt = gmdate('Y-m-d H:i:sP', $windowStart);
        $expiresAt = gmdate('Y-m-d H:i:sP', $windowStart + ($windowSeconds * 2));
        $hits = $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.rate_limit_counters (scope, key_hash, window_started_at, hits, expires_at)
VALUES (?, decode(?, 'hex'), ?::timestamptz, 1, ?::timestamptz)
ON CONFLICT (scope, key_hash, window_started_at)
DO UPDATE SET hits = commercial.rate_limit_counters.hits + 1
RETURNING hits
SQL, [$scope, $this->tokens->pseudonym($key), $startedAt, $expiresAt]);
        if ((int) $hits > $limit) {
            throw new RateLimitExceeded(max(1, $windowStart + $windowSeconds - $now));
        }
    }
}
