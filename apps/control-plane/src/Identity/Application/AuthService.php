<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Application;

use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Identity\Domain\PasswordPolicy;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AuthService
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private TokenCodec $tokens,
        private DatabaseRateLimiter $rateLimiter,
        private int $verificationTtlSeconds,
        private int $accessTokenTtlSeconds,
        private int $sessionTtlSeconds,
    ) {
    }

    /** @return array{user_id: string, verification_token: string} */
    public function register(string $emailInput, string $password, string $ip, string $correlationId): array
    {
        $this->rateLimiter->consume('auth.register.ip', $ip, 5, 3600);
        $email = EmailPolicy::normalize($emailInput);
        PasswordPolicy::validate($password);
        $userId = Uuid::v7()->toRfc4122();
        $verificationId = Uuid::v7()->toRfc4122();
        $principal = new ControlUserPrincipal($userId, $email['normalized'], '');
        $passwordHash = $this->passwordHasher->hashPassword($principal, $password);
        $verificationToken = $this->tokens->verificationToken($verificationId);

        try {
            $this->connection->transactional(function (Connection $connection) use ($userId, $verificationId, $email, $passwordHash, $verificationToken, $correlationId): void {
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.control_users
 (id, email, email_normalized, password_hash, status, password_changed_at)
VALUES (?, ?, ?, ?, 'pending_verification', now())
SQL, [$userId, $email['email'], $email['normalized'], $passwordHash]);
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.control_user_email_verifications (id, control_user_id, token_hash, expires_at)
VALUES (?, ?, decode(?, 'hex'), now() + (? * interval '1 second'))
SQL, [$verificationId, $userId, $this->tokens->hash($verificationToken), $this->verificationTtlSeconds]);
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.audit_events
 (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome)
VALUES ('control_user', ?, 'control_user.registered', 'control_user', ?, ?, 'success')
SQL, [$userId, $userId, $correlationId]);
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload)
VALUES ('control_user.email_verification_requested', 'control_user', ?, jsonb_build_object(
  'user_id', ?::text, 'verification_id', ?::text
))
SQL, [$userId, $userId, $verificationId]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new AuthFailure('email_unavailable', 'The email cannot be registered.');
        }

        return ['user_id' => $userId, 'verification_token' => $verificationToken];
    }

    public function resendVerification(string $emailInput, string $ip, string $correlationId): ?string
    {
        $email = EmailPolicy::normalize($emailInput);
        $this->rateLimiter->consume('auth.verify_resend.ip', $ip, 5, 3600);
        $this->rateLimiter->consume('auth.verify_resend.identity', $email['normalized'], 3, 3600);

        return $this->connection->transactional(function (Connection $connection) use ($email, $correlationId): ?string {
            $user = $connection->fetchAssociative('SELECT id, email, status FROM commercial.control_users WHERE email_normalized = ? FOR UPDATE', [$email['normalized']]);
            if ($user === false || $user['status'] !== 'pending_verification' || !is_string($user['id']) || !is_string($user['email'])) {
                return null;
            }
            $connection->executeStatement('UPDATE commercial.control_user_email_verifications SET invalidated_at = now() WHERE control_user_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL', [$user['id']]);
            $verificationId = Uuid::v7()->toRfc4122();
            $token = $this->tokens->verificationToken($verificationId);
            $connection->executeStatement("INSERT INTO commercial.control_user_email_verifications (id, control_user_id, token_hash, expires_at) VALUES (?, ?, decode(?, 'hex'), now() + (? * interval '1 second'))", [$verificationId, $user['id'], $this->tokens->hash($token), $this->verificationTtlSeconds]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, 'control_user.email_verification_requested', 'control_user', ?, ?, 'success')", [$user['id'], $user['id'], $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('control_user.email_verification_requested', 'control_user', ?, jsonb_build_object('user_id', ?::text, 'verification_id', ?::text))", [$user['id'], $user['id'], $verificationId]);

            return $token;
        });
    }

    public function verifyEmail(string $token, string $correlationId): string
    {
        try {
            $this->tokens->validateVerificationToken($token);
        } catch (\InvalidArgumentException|\DomainException) {
            throw new AuthFailure('verification_invalid', 'Verification token is invalid, expired, or already used.');
        }

        /** @var array{status: string, user_id?: string} $result */
        $result = $this->connection->transactional(function (Connection $connection) use ($token, $correlationId): array {
            $verification = $connection->fetchAssociative(<<<'SQL'
SELECT v.id, v.control_user_id, v.expires_at, v.consumed_at, v.invalidated_at, u.status
FROM commercial.control_user_email_verifications v
JOIN commercial.control_users u ON u.id = v.control_user_id
WHERE v.token_hash = decode(?, 'hex')
FOR UPDATE OF v, u
SQL, [$this->tokens->hash($token)]);
            if ($verification === false) {
                return ['status' => 'invalid'];
            }
            if ($verification['consumed_at'] !== null) {
                return ['status' => 'used'];
            }
            if ($verification['invalidated_at'] !== null) {
                return ['status' => 'invalid'];
            }
            if (strtotime((string) $verification['expires_at']) <= time()) {
                return ['status' => 'expired'];
            }
            if ($verification['status'] !== 'pending_verification' || !is_string($verification['control_user_id'])) {
                return ['status' => 'invalid'];
            }
            $userId = $verification['control_user_id'];
            $connection->executeStatement('UPDATE commercial.control_user_email_verifications SET consumed_at = now() WHERE id = ?', [$verification['id']]);
            $connection->executeStatement("UPDATE commercial.control_users SET status = 'active', email_verified_at = now(), updated_at = now() WHERE id = ?", [$userId]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, 'control_user.email_verified', 'control_user', ?, ?, 'success')", [$userId, $userId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('control_user.email_verified', 'control_user', ?, jsonb_build_object('user_id', ?::text))", [$userId, $userId]);

            return ['status' => 'ok', 'user_id' => $userId];
        });
        if ($result['status'] !== 'ok' || !isset($result['user_id'])) {
            throw new AuthFailure('verification_' . $result['status'], 'Verification token is invalid, expired, or already used.');
        }

        return $result['user_id'];
    }

    /** @return array{access_token: string, refresh_token: string, access_expires_in: int, session_expires_in: int} */
    public function login(string $emailInput, string $password, string $ip, string $userAgent, string $correlationId): array
    {
        $email = EmailPolicy::normalize($emailInput);
        $this->rateLimiter->consume('auth.login.ip', $ip, 30, 300);
        $this->rateLimiter->consume('auth.login.identity', $email['normalized'], 10, 300);
        $user = $this->connection->fetchAssociative('SELECT id, email_normalized, password_hash, status FROM commercial.control_users WHERE email_normalized = ?', [$email['normalized']]);
        $valid = false;
        if ($user !== false && is_string($user['id']) && is_string($user['password_hash'])) {
            $valid = $this->passwordHasher->isPasswordValid(new ControlUserPrincipal($user['id'], $email['normalized'], $user['password_hash']), $password);
        } else {
            $this->passwordHasher->hashPassword(new ControlUserPrincipal(Uuid::v7()->toRfc4122(), $email['normalized'], ''), $password);
        }
        if (!$valid || $user === false || $user['status'] !== 'active' || !is_string($user['id'])) {
            $this->auditLoginFailure($email['normalized'], $correlationId);
            throw new AuthFailure('invalid_credentials', 'Invalid credentials.');
        }

        return $this->issueSession($user['id'], $email['normalized'], $ip, $userAgent, $correlationId);
    }

    /** @return array{access_token: string, refresh_token: string, access_expires_in: int, session_expires_in: int} */
    public function refresh(string $refreshToken, string $ip, string $userAgent, string $correlationId): array
    {
        if ($refreshToken === '' || strlen($refreshToken) > 200) {
            throw new AuthFailure('invalid_refresh_token');
        }
        $this->rateLimiter->consume('auth.refresh.ip', $ip, 60, 300);
        $hash = $this->tokens->hash($refreshToken);

        /** @var array{status: string, access_token?: string, refresh_token?: string} $result */
        $result = $this->connection->transactional(function (Connection $connection) use ($hash, $ip, $userAgent, $correlationId): array {
            $session = $connection->fetchAssociative(<<<'SQL'
SELECT s.*, u.email_normalized, u.status AS user_status
FROM commercial.control_sessions s
JOIN commercial.control_users u ON u.id = s.control_user_id
WHERE s.refresh_token_hash = decode(?, 'hex')
FOR UPDATE OF s, u
SQL, [$hash]);
            $reused = false;
            if ($session === false) {
                $session = $connection->fetchAssociative(<<<'SQL'
SELECT s.*, u.email_normalized, u.status AS user_status
FROM commercial.control_session_refresh_history h
JOIN commercial.control_sessions s ON s.id = h.control_session_id
JOIN commercial.control_users u ON u.id = s.control_user_id
WHERE h.token_hash = decode(?, 'hex')
FOR UPDATE OF s, u
SQL, [$hash]);
                $reused = $session !== false;
            }
            if ($session === false || !is_string($session['id'])) {
                return ['status' => 'invalid'];
            }
            if ($reused) {
                $connection->executeStatement("UPDATE commercial.control_sessions SET revoked_at = COALESCE(revoked_at, now()), revoke_reason = COALESCE(revoke_reason, 'refresh_reuse') WHERE id = ?", [$session['id']]);
                $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, 'control_session.refresh_reuse', 'control_session', ?, ?, 'denied')", [$session['control_user_id'], $session['id'], $correlationId]);

                return ['status' => 'reused'];
            }
            if ($session['revoked_at'] !== null || $session['user_status'] !== 'active') {
                return ['status' => 'revoked'];
            }
            if (strtotime((string) $session['expires_at']) <= time()) {
                $connection->executeStatement("UPDATE commercial.control_sessions SET revoked_at = now(), revoke_reason = 'expired' WHERE id = ?", [$session['id']]);

                return ['status' => 'expired'];
            }
            $accessToken = $this->tokens->randomToken();
            $newRefreshToken = $this->tokens->randomToken();
            $connection->executeStatement('INSERT INTO commercial.control_session_refresh_history (token_hash, control_session_id) SELECT refresh_token_hash, id FROM commercial.control_sessions WHERE id = ? ON CONFLICT (token_hash) DO NOTHING', [$session['id']]);
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.control_sessions
SET previous_refresh_token_hash = refresh_token_hash,
    refresh_token_hash = decode(?, 'hex'), access_token_hash = decode(?, 'hex'),
    access_expires_at = now() + (? * interval '1 second'), generation = generation + 1,
    rotated_at = now(), last_used_at = now(), ip_hash = decode(?, 'hex'), user_agent = ?
WHERE id = ?
SQL, [$this->tokens->hash($newRefreshToken), $this->tokens->hash($accessToken), $this->accessTokenTtlSeconds, $this->tokens->pseudonym($ip), mb_substr($userAgent, 0, 300), $session['id']]);

            return ['status' => 'ok', 'access_token' => $accessToken, 'refresh_token' => $newRefreshToken];
        });
        if ($result['status'] !== 'ok' || !isset($result['access_token'], $result['refresh_token'])) {
            throw new AuthFailure('refresh_' . $result['status'], 'Refresh token is invalid or no longer usable.');
        }

        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'access_expires_in' => $this->accessTokenTtlSeconds,
            'session_expires_in' => $this->sessionTtlSeconds,
        ];
    }

    public function logout(string $accessToken, string $correlationId): void
    {
        if ($accessToken === '' || strlen($accessToken) > 200) {
            return;
        }
        $session = $this->connection->fetchAssociative("UPDATE commercial.control_sessions SET revoked_at = COALESCE(revoked_at, now()), revoke_reason = COALESCE(revoke_reason, 'logout') WHERE access_token_hash = decode(?, 'hex') RETURNING id, control_user_id", [$this->tokens->hash($accessToken)]);
        if ($session !== false) {
            $this->connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, 'control_session.logout', 'control_session', ?, ?, 'success')", [$session['control_user_id'], $session['id'], $correlationId]);
        }
    }

    public function authenticateAccessToken(string $accessToken): ControlUserPrincipal
    {
        if ($accessToken === '' || strlen($accessToken) > 200) {
            throw new AuthFailure('invalid_access_token');
        }
        $user = $this->connection->fetchAssociative(<<<'SQL'
SELECT u.id, u.email_normalized, u.password_hash, u.status, s.id AS session_id,
       s.primary_authenticated_at, s.mfa_authenticated_at
FROM commercial.control_sessions s
JOIN commercial.control_users u ON u.id = s.control_user_id
WHERE s.access_token_hash = decode(?, 'hex') AND s.revoked_at IS NULL
  AND s.access_expires_at > now() AND s.expires_at > now()
SQL, [$this->tokens->hash($accessToken)]);
        if ($user === false || $user['status'] !== 'active' || !is_string($user['id']) || !is_string($user['email_normalized']) || !is_string($user['password_hash'])) {
            throw new AuthFailure('invalid_access_token');
        }
        // Usage is an approximate activity indicator, not an authorization cache.
        // Match the bounded M2M activity update: parallel reads must not serialize
        // on a session-row write/WAL flush for every request. Revocation and both
        // expiry timestamps are still checked above on every authentication.
        $this->connection->executeStatement(<<<'SQL'
UPDATE commercial.control_sessions SET last_used_at = now()
WHERE access_token_hash = decode(?, 'hex') AND revoked_at IS NULL
  AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')
SQL, [$this->tokens->hash($accessToken)]);

        return new ControlUserPrincipal($user['id'], $user['email_normalized'], $user['password_hash'],
            is_string($user['session_id'] ?? null) ? $user['session_id'] : null,
            isset($user['primary_authenticated_at']) ? new \DateTimeImmutable((string) $user['primary_authenticated_at']) : null,
            $user['mfa_authenticated_at'] === null ? null : new \DateTimeImmutable((string) $user['mfa_authenticated_at']));
    }

    /** @return array{access_token: string, refresh_token: string, access_expires_in: int, session_expires_in: int} */
    public function issueMfaSession(string $userId, string $emailNormalized, string $ip, string $userAgent, string $correlationId, string $method): array
    {
        if (!in_array($method, ['password_totp', 'password_recovery'], true)) {
            throw new \InvalidArgumentException('Invalid MFA session method.');
        }
        return $this->issueSession($userId, $emailNormalized, $ip, $userAgent, $correlationId, $method);
    }

    /** Internal application boundary: credentials were already checked and MFA was transactionally decided.
     * @return array{access_token: string, refresh_token: string, access_expires_in: int, session_expires_in: int}
     */
    public function issuePasswordSessionForAuthenticatedUser(string $userId, string $emailNormalized, string $ip, string $userAgent, string $correlationId): array
    {
        return $this->issueSession($userId, $emailNormalized, $ip, $userAgent, $correlationId);
    }

    public function recordLoginFailure(string $emailNormalized, string $correlationId): void
    {
        $this->auditLoginFailure($emailNormalized, $correlationId);
    }

    /** @return array{access_token: string, refresh_token: string, access_expires_in: int, session_expires_in: int} */
    private function issueSession(string $userId, string $emailNormalized, string $ip, string $userAgent, string $correlationId, string $method = 'password'): array
    {
        $accessToken = $this->tokens->randomToken();
        $refreshToken = $this->tokens->randomToken();
        $sessionId = Uuid::v7()->toRfc4122();
        $this->connection->transactional(function (Connection $connection) use ($userId, $accessToken, $refreshToken, $ip, $userAgent, $sessionId, $correlationId, $method): void {
            $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.control_sessions
 (id, control_user_id, access_token_hash, refresh_token_hash, access_expires_at, expires_at, ip_hash, user_agent, authentication_method, mfa_authenticated_at)
VALUES (?, ?, decode(?, 'hex'), decode(?, 'hex'), now() + (? * interval '1 second'), now() + (? * interval '1 second'), decode(?, 'hex'), ?, ?, CASE WHEN ? = 'password' THEN NULL ELSE now() END)
SQL, [$sessionId, $userId, $this->tokens->hash($accessToken), $this->tokens->hash($refreshToken), $this->accessTokenTtlSeconds, $this->sessionTtlSeconds, $this->tokens->pseudonym($ip), mb_substr($userAgent, 0, 300), $method, $method]);
            $connection->executeStatement('UPDATE commercial.control_users SET last_login_at = now(), updated_at = now() WHERE id = ?', [$userId]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, 'control_session.login', 'control_session', ?, ?, 'success')", [$userId, $sessionId, $correlationId]);
        });

        return ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'access_expires_in' => $this->accessTokenTtlSeconds, 'session_expires_in' => $this->sessionTtlSeconds];
    }

    private function auditLoginFailure(string $emailNormalized, string $correlationId): void
    {
        $this->connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, action, resource_type, resource_id, correlation_id, outcome, metadata) VALUES ('system', 'control_session.login', 'control_user', ?, ?, 'failure', '{\"reason\":\"invalid_credentials\"}'::jsonb)", [substr($this->tokens->pseudonym($emailNormalized), 0, 64), $correlationId]);
    }
}
