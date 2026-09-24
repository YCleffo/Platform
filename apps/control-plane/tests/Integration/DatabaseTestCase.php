<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('CONTROL_DATABASE_URL');
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1' || !is_string($url)) {
            throw new \RuntimeException('Commercial integration tests require APP_ENV=test and CONTROL_TEST_ISOLATED=1.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test' || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Refusing Commercial integration tests against a non-isolated database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->close();
        }
        parent::tearDown();
    }

    protected function account(string $code = 'test-account'): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Test Account') RETURNING id", [$code]);
        self::assertIsString($id);

        return $id;
    }

    protected function expectDatabaseRejection(callable $operation): void
    {
        $this->connection->beginTransaction();
        try {
            $operation();
            self::fail('Expected PostgreSQL to reject the write.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertNotSame('', $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
