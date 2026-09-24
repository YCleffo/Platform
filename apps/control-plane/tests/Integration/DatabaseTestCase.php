<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Uid\Uuid;

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
        $user = $this->user();
        $id = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Test Account') RETURNING id", [$code]);
        self::assertIsString($id);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $id]);

        return $id;
    }

    protected function user(?string $email = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $email ??= 'user-' . str_replace('-', '', $id) . '@example.test';
        $this->connection->executeStatement("INSERT INTO commercial.control_users (id, email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, lower(?), '!test-hash!', 'active', now(), now())", [$id, $email, $email]);

        return $id;
    }

    protected function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    protected function authService(): AuthService
    {
        $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
        $factory = new PasswordHasherFactory([
            ControlUserPrincipal::class => ['algorithm' => 'auto'],
        ]);

        return new AuthService(
            $this->connection,
            new UserPasswordHasher($factory),
            $tokens,
            new DatabaseRateLimiter($this->connection, $tokens),
            3600,
            900,
            2592000,
        );
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
