<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\CreateAuthChallengeCommand;
use App\Core\Auth\Application\CreateSessionCommand;
use App\Core\Auth\Application\IdentifierRepository;
use App\Core\Auth\Application\PasswordCredentialRepository;
use App\Core\Auth\Application\RotateSessionTokensCommand;
use App\Core\Auth\Application\SessionRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class AuthPersistenceCest
{
    public function repositoriesPersistSensitiveStateInternally(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $account = $db->createCommand(
                <<<'SQL'
                INSERT INTO core.accounts (account_type, status, label)
                VALUES ('service', 'active', 'auth persistence integration')
                RETURNING id::text AS id, security_stamp::text AS security_stamp
                SQL,
            )->queryOne();
            assertTrue(is_array($account));
            $accountId = (string) $account['id'];
            $securityStamp = (string) $account['security_stamp'];
            $unique = bin2hex(random_bytes(8));
            $normalized = 'auth-' . $unique . '@example.test';

            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_identifiers (
                    account_id, identifier_type, value, normalized_value, is_primary
                )
                VALUES (CAST(:account_id AS uuid), 'email', :value, :normalized, true)
                SQL,
                [
                    ':account_id' => $accountId,
                    ':value' => $normalized,
                    ':normalized' => $normalized,
                ],
            )->execute();
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_password_credentials (
                    account_id, password_hash, password_algorithm
                )
                VALUES (CAST(:account_id AS uuid), :password_hash, 'argon2id')
                SQL,
                [':account_id' => $accountId, ':password_hash' => '$argon2id$internal-test-hash'],
            )->execute();

            $identifier = $container
                ->get(IdentifierRepository::class)
                ->findByNormalized('email', $normalized);
            assertNotNull($identifier);
            assertSame($accountId, $identifier->accountId);

            $credential = $container
                ->get(PasswordCredentialRepository::class)
                ->findByAccountId($accountId);
            assertNotNull($credential);
            assertSame('$argon2id$internal-test-hash', $credential->passwordHash);

            try {
                $db->transaction(static function (ConnectionInterface $db) use ($accountId, $normalized): void {
                    $db->createCommand(
                        <<<'SQL'
                        INSERT INTO core.auth_identifiers (
                            account_id, identifier_type, value, normalized_value
                        )
                        VALUES (CAST(:account_id AS uuid), 'email', :value, :normalized)
                        SQL,
                        [
                            ':account_id' => $accountId,
                            ':value' => 'duplicate-' . $normalized,
                            ':normalized' => $normalized,
                        ],
                    )->execute();
                });
                $tester->fail('Identifier uniqueness must be enforced by PostgreSQL.');
            } catch (IntegrityException) {
                // Expected; the nested transaction rolls back to its savepoint.
            }

            $sessionHash = bin2hex(random_bytes(32));
            $refreshHash = bin2hex(random_bytes(32));
            $sessions = $container->get(SessionRepository::class);
            $session = $sessions->storeSession(new CreateSessionCommand(
                accountId: $accountId,
                sessionTokenHashHex: $sessionHash,
                refreshTokenHashHex: $refreshHash,
                securityStamp: $securityStamp,
                authenticationMethod: 'password',
                mfaVerifiedAt: null,
                clientType: 'integration-test',
                deviceName: null,
                ipAddress: '127.0.0.1',
                userAgent: null,
                accessExpiresAt: (new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM),
                expiresAt: (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
            ));
            assertSame($accountId, $session->accountId);
            assertNotNull($sessions->findByTokenHashHex($sessionHash));
            $newSessionHash = bin2hex(random_bytes(32));
            $newRefreshHash = bin2hex(random_bytes(32));
            $rotated = $sessions->rotateTokens(new RotateSessionTokensCommand(
                currentRefreshTokenHashHex: $refreshHash,
                newAccessTokenHashHex: $newSessionHash,
                newRefreshTokenHashHex: $newRefreshHash,
                accessExpiresAt: (new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM),
                expectedSecurityStamp: $securityStamp,
            ));
            assertNotNull($rotated);
            assertNull($sessions->findByTokenHashHex($sessionHash));
            assertNotNull($sessions->findByTokenHashHex($newSessionHash));
            assertNull($sessions->rotateTokens(new RotateSessionTokensCommand(
                currentRefreshTokenHashHex: $refreshHash,
                newAccessTokenHashHex: bin2hex(random_bytes(32)),
                newRefreshTokenHashHex: bin2hex(random_bytes(32)),
                accessExpiresAt: (new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM),
                expectedSecurityStamp: $securityStamp,
            )));
            assertTrue($sessions->revoke($session->id, 'integration test'));
            assertNotNull($sessions->findByTokenHashHex($newSessionHash)?->revokedAt);

            $challengeHash = bin2hex(random_bytes(32));
            $challenges = $container->get(AuthChallengeRepository::class);
            $challenge = $challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $accountId,
                challengeType: 'integration-test',
                secretHashHex: $challengeHash,
                targetNormalized: $normalized,
                payload: ['purpose' => 'test'],
                maxAttempts: 3,
                expiresAt: (new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM),
            ));
            assertNotNull($challenges->findActiveBySecretHashHex($challengeHash));
            assertTrue($challenges->consume($challenge->id));
            assertNull($challenges->findActiveBySecretHashHex($challengeHash));
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }
}
