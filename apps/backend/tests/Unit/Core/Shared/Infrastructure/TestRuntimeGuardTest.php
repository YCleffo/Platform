<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared\Infrastructure;

use App\Core\Shared\Infrastructure\DestructiveOperationBlockedException;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class TestRuntimeGuardTest extends TestCase
{
    private ?string $origAppEnv = null;
    private ?string $origIsolated = null;
    private ?string $origRedisHost = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origAppEnv = getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : null;
        $this->origIsolated = getenv('TEST_ISOLATED_RUNTIME') !== false ? (string) getenv('TEST_ISOLATED_RUNTIME') : null;
        $this->origRedisHost = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('APP_ENV', $this->origAppEnv);
        $this->restoreEnv('TEST_ISOLATED_RUNTIME', $this->origIsolated);
        $this->restoreEnv('REDIS_HOST', $this->origRedisHost);
        parent::tearDown();
    }

    public function testAllowsDestructiveOperationsWhenAllConditionsAreMet(): void
    {
        putenv('APP_ENV=test');
        putenv('TEST_ISOLATED_RUNTIME=1');
        putenv('REDIS_HOST=redis-test');

        $db = $this->createMockDb('platform_test');

        $this->expectNotToPerformAssertions();
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    }

    public function testBlocksWhenAppEnvIsNotTest(): void
    {
        putenv('APP_ENV=dev');
        putenv('TEST_ISOLATED_RUNTIME=1');
        putenv('REDIS_HOST=redis-test');

        $db = $this->createMockDb('platform_test');

        $this->expectException(DestructiveOperationBlockedException::class);
        $this->expectExceptionMessage('APP_ENV must be "test", got "dev"');
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    }

    public function testBlocksWhenIsolatedRuntimeMissing(): void
    {
        putenv('APP_ENV=test');
        $_ENV['APP_ENV'] = 'test';
        putenv('TEST_ISOLATED_RUNTIME');
        putenv('TEST_ISOLATED_RUNTIME=');
        unset($_ENV['TEST_ISOLATED_RUNTIME'], $_SERVER['TEST_ISOLATED_RUNTIME']);
        putenv('REDIS_HOST=redis-test');
        $_ENV['REDIS_HOST'] = 'redis-test';

        $db = $this->createMockDb('platform_test');

        $this->expectException(DestructiveOperationBlockedException::class);
        $this->expectExceptionMessage('TEST_ISOLATED_RUNTIME must be "1"');
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    }

    public function testBlocksWhenDatabaseIsNotPlatformTest(): void
    {
        putenv('APP_ENV=test');
        putenv('TEST_ISOLATED_RUNTIME=1');
        putenv('REDIS_HOST=redis-test');

        $db = $this->createMockDb('platform'); // dev database!

        $this->expectException(DestructiveOperationBlockedException::class);
        $this->expectExceptionMessage('PostgreSQL database must be "platform_test", got "platform"');
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    }

    public function testBlocksWhenRedisHostIsNotRedisTest(): void
    {
        putenv('APP_ENV=test');
        putenv('TEST_ISOLATED_RUNTIME=1');
        putenv('REDIS_HOST=redis'); // dev redis!

        $db = $this->createMockDb('platform_test');

        $this->expectException(DestructiveOperationBlockedException::class);
        $this->expectExceptionMessage('REDIS_HOST must point to "redis-test", got "redis"');
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    }

    private function createMockDb(string $databaseName): ConnectionInterface
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryScalar')->willReturn($databaseName);

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->with('SELECT current_database()')->willReturn($command);

        return $db;
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
