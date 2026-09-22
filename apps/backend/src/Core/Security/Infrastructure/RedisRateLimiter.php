<?php

declare(strict_types=1);

namespace App\Core\Security\Infrastructure;

use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Predis\ClientInterface;
use Throwable;

final readonly class RedisRateLimiter implements RateLimiterInterface
{
    private const string SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return count
        LUA;

    public function __construct(
        private ClientInterface $redis,
        private string $prefix,
    ) {}

    public function consume(string $scope, string $subjectDigest, int $limit, int $windowSeconds): bool
    {
        try {
            $count = $this->redis->eval(
                self::SCRIPT,
                1,
                sprintf('%s:%s:%s', $this->prefix, $scope, $subjectDigest),
                (string) $windowSeconds,
            );
        } catch (Throwable $exception) {
            throw new RateLimiterUnavailableException(
                'Authentication rate limiting is unavailable.',
                previous: $exception,
            );
        }

        return (int) $count <= $limit;
    }
}
