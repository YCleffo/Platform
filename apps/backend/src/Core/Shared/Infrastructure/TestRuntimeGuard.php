<?php

declare(strict_types=1);

namespace App\Core\Shared\Infrastructure;

use App\Environment;
use Predis\ClientInterface as PredisClientInterface;
use Predis\Connection\NodeConnectionInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

use function getenv;
use function sprintf;

final class TestRuntimeGuard
{
    public const EXPECTED_ENV = 'test';
    public const EXPECTED_ISOLATED_RUNTIME = '1';
    public const EXPECTED_DATABASE = 'platform_test';
    public const EXPECTED_REDIS_HOST = 'redis-test';

    /**
     * Verifies that the current execution context satisfies all four mandatory safety invariants
     * before any destructive database (DELETE/TRUNCATE) or Redis (FLUSHDB) operations are executed.
     *
     * @throws DestructiveOperationBlockedException if any condition fails.
     */
    public static function assertDestructiveOperationsAllowed(
        ConnectionInterface $db,
        ?PredisClientInterface $redis = null,
        ?string $overrideRedisHost = null,
    ): void {
        // 1. APP_ENV === "test"
        $currentEnv = self::resolveAppEnv();
        if ($currentEnv !== self::EXPECTED_ENV) {
            throw new DestructiveOperationBlockedException(
                sprintf(
                    'Destructive operation blocked: APP_ENV must be "%s", got "%s".',
                    self::EXPECTED_ENV,
                    $currentEnv,
                ),
            );
        }

        // 2. TEST_ISOLATED_RUNTIME === "1"
        $isolatedRuntime = self::getEnvVar('TEST_ISOLATED_RUNTIME');
        if ($isolatedRuntime !== self::EXPECTED_ISOLATED_RUNTIME) {
            throw new DestructiveOperationBlockedException(
                sprintf(
                    'Destructive operation blocked: TEST_ISOLATED_RUNTIME must be "%s", got "%s".',
                    self::EXPECTED_ISOLATED_RUNTIME,
                    $isolatedRuntime ?? 'null',
                ),
            );
        }

        // 3. PostgreSQL current_database() === "platform_test"
        $currentDatabase = self::queryCurrentDatabase($db);
        if ($currentDatabase !== self::EXPECTED_DATABASE) {
            throw new DestructiveOperationBlockedException(
                sprintf(
                    'Destructive operation blocked: PostgreSQL database must be "%s", got "%s".',
                    self::EXPECTED_DATABASE,
                    $currentDatabase,
                ),
            );
        }

        // 4. REDIS_HOST === "redis-test"
        $redisHost = $overrideRedisHost ?? self::resolveRedisHost($redis);
        if ($redisHost !== self::EXPECTED_REDIS_HOST) {
            throw new DestructiveOperationBlockedException(
                sprintf(
                    'Destructive operation blocked: REDIS_HOST must point to "%s", got "%s".',
                    self::EXPECTED_REDIS_HOST,
                    $redisHost,
                ),
            );
        }
    }

    private static function resolveAppEnv(): string
    {
        $env = self::getEnvVar('APP_ENV');
        if ($env !== null && $env !== '') {
            return $env;
        }

        Environment::prepare();
        return Environment::appEnv();
    }

    private static function queryCurrentDatabase(ConnectionInterface $db): string
    {
        $dbName = $db->createCommand('SELECT current_database()')->queryScalar();
        if (!is_string($dbName) || $dbName === '') {
            throw new DestructiveOperationBlockedException(
                'Destructive operation blocked: Unable to determine PostgreSQL current_database().',
            );
        }

        return $dbName;
    }

    private static function resolveRedisHost(?PredisClientInterface $redis): string
    {
        if ($redis !== null) {
            $connection = $redis->getConnection();
            if ($connection instanceof NodeConnectionInterface) {
                $parameters = $connection->getParameters();
                /** @var array<string, mixed> $params */
                $params = $parameters->toArray();
                if (isset($params['host']) && is_string($params['host'])) {
                    return $params['host'];
                }
            }
        }

        return self::getEnvVar('REDIS_HOST') ?? '';
    }

    private static function getEnvVar(string $name): ?string
    {
        $val = getenv($name, true);
        if (is_string($val) && $val !== '') {
            return $val;
        }

        $val = getenv($name);
        if (is_string($val) && $val !== '') {
            return $val;
        }

        return isset($_ENV[$name]) && (string) $_ENV[$name] !== '' ? (string) $_ENV[$name] : null;
    }
}
