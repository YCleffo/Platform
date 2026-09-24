<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Application\AuthService;

final class ControlAuthTest extends DatabaseTestCase
{
    public function testRegistrationVerificationLoginRefreshAndLogoutLifecycle(): void
    {
        $auth = $this->authService();
        $correlation = $this->uuid();
        $registered = $auth->register(' User@Example.com ', 'correct horse battery staple', '192.0.2.1', $correlation);
        $row = $this->connection->fetchAssociative('SELECT email, email_normalized, password_hash, status FROM commercial.control_users WHERE id = ?', [$registered['user_id']]);
        self::assertIsArray($row);
        self::assertSame('User@Example.com', $row['email']);
        self::assertSame('user@example.com', $row['email_normalized']);
        self::assertSame('pending_verification', $row['status']);
        self::assertNotSame('correct horse battery staple', $row['password_hash']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_user_email_verifications WHERE encode(token_hash, \'hex\') = ?', [bin2hex($registered['verification_token'])]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE payload::text LIKE '%' || ? || '%'", [$registered['verification_token']]));

        $this->expectAuthFailure(fn () => $auth->login('user@example.com', 'correct horse battery staple', '192.0.2.2', 'PHPUnit', $correlation), 'invalid_credentials');
        self::assertSame($registered['user_id'], $auth->verifyEmail($registered['verification_token'], $correlation));
        $this->expectAuthFailure(fn () => $auth->verifyEmail($registered['verification_token'], $correlation), 'verification_used');

        $session = $auth->login('USER@example.com', 'correct horse battery staple', '192.0.2.2', 'PHPUnit', $correlation);
        self::assertSame($registered['user_id'], $auth->authenticateAccessToken($session['access_token'])->id());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.control_sessions WHERE encode(access_token_hash, 'hex') = ? OR encode(refresh_token_hash, 'hex') = ?", [bin2hex($session['access_token']), bin2hex($session['refresh_token'])]));

        $rotated = $auth->refresh($session['refresh_token'], '192.0.2.2', 'PHPUnit', $correlation);
        self::assertNotSame($session['refresh_token'], $rotated['refresh_token']);
        $this->expectAuthFailure(fn () => $auth->refresh($session['refresh_token'], '192.0.2.2', 'PHPUnit', $correlation), 'refresh_reused');
        $this->expectAuthFailure(fn () => $auth->authenticateAccessToken($rotated['access_token']), 'invalid_access_token');

        $second = $auth->login('user@example.com', 'correct horse battery staple', '192.0.2.2', 'PHPUnit', $correlation);
        $auth->logout($second['access_token'], $correlation);
        $auth->logout($second['access_token'], $correlation);
        $this->expectAuthFailure(fn () => $auth->authenticateAccessToken($second['access_token']), 'invalid_access_token');
    }

    public function testCaseInsensitiveDuplicateAndWrongPasswordAreRejected(): void
    {
        $auth = $this->authService();
        $correlation = $this->uuid();
        $auth->register('Mixed@Example.com', 'correct horse battery staple', '192.0.2.10', $correlation);
        $this->expectAuthFailure(fn () => $auth->register('mixed@example.com', 'another correct horse battery', '192.0.2.11', $correlation), 'email_unavailable');
    }

    public function testMalformedVerificationTokenIsRejectedAsAnAuthFailure(): void
    {
        $this->expectAuthFailure(
            fn () => $this->authService()->verifyEmail('not-a-verification-token', $this->uuid()),
            'verification_invalid',
        );
    }

    public function testExpiredVerificationAndRefreshAreRejected(): void
    {
        $auth = $this->authService();
        $correlation = $this->uuid();
        $registered = $auth->register('expired@example.com', 'correct horse battery staple', '192.0.2.20', $correlation);
        $this->connection->executeStatement("UPDATE commercial.control_user_email_verifications SET created_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE control_user_id = ?", [$registered['user_id']]);
        $this->expectAuthFailure(fn () => $auth->verifyEmail($registered['verification_token'], $correlation), 'verification_expired');

        $fresh = $auth->register('session@example.com', 'correct horse battery staple', '192.0.2.21', $correlation);
        $auth->verifyEmail($fresh['verification_token'], $correlation);
        $session = $auth->login('session@example.com', 'correct horse battery staple', '192.0.2.21', 'PHPUnit', $correlation);
        $this->connection->executeStatement("UPDATE commercial.control_sessions SET issued_at = now() - interval '2 hours', expires_at = now() - interval '1 hour', access_expires_at = now() - interval '1 hour 1 second' WHERE control_user_id = ?", [$fresh['user_id']]);
        $this->expectAuthFailure(fn () => $auth->refresh($session['refresh_token'], '192.0.2.21', 'PHPUnit', $correlation), 'refresh_expired');
    }

    private function expectAuthFailure(callable $operation, string $code): void
    {
        try {
            $operation();
            self::fail('Expected authentication failure.');
        } catch (AuthFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }
}
