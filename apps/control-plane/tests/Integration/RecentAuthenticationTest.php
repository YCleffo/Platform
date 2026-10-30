<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Application\AuthFailure;

final class RecentAuthenticationTest extends DatabaseTestCase
{
    public function testExpiredEvidenceIsRejectedAndStepUpRefreshesTheSession(): void
    {
        $auth = $this->authService();
        $security = $this->authSecurityService();
        $correlation = $this->uuid();
        $email = 'recent-' . bin2hex(random_bytes(5)) . '@example.test';
        $password = 'correct horse battery staple';
        $registered = $auth->register($email, $password, '192.0.2.77', $correlation);
        $auth->verifyEmail($registered['verification_token'], $correlation);
        $session = $auth->login($email, $password, '192.0.2.77', 'recent test', $correlation);
        $principal = $auth->authenticateAccessToken($session['access_token']);
        $setup = $security->beginTotpSetup($principal, $correlation);
        $security->confirmTotpSetup($principal, $setup['factor_id'], $this->totp($setup['secret'], time()), $correlation);

        $this->expireEvidence((string) $principal->sessionId(), false, true);
        $staleMfa = $auth->authenticateAccessToken($session['access_token']);
        try {
            $security->requireSensitiveMfa($staleMfa);
            self::fail('Expired MFA evidence was accepted.');
        } catch (AuthFailure $failure) {
            self::assertSame('recent_mfa_required', $failure->errorCode);
        }

        $security->reauthenticate($staleMfa, $password, $this->totp($setup['secret'], time() + 30), null, $correlation);
        $security->requireSensitiveMfa($auth->authenticateAccessToken($session['access_token']));

        $this->expireEvidence((string) $principal->sessionId(), true, true);
        $stalePrimary = $auth->authenticateAccessToken($session['access_token']);
        try {
            $security->requireSensitiveMfa($stalePrimary);
            self::fail('Expired primary evidence was accepted.');
        } catch (AuthFailure $failure) {
            self::assertSame('recent_authentication_required', $failure->errorCode);
        }

        $security->reauthenticate($stalePrimary, $password, $this->totp($setup['secret'], time() + 60), null, $correlation);
        $security->requireSensitiveMfa($auth->authenticateAccessToken($session['access_token']));
    }

    public function testTotpCounterCannotBeReplayedForStepUp(): void
    {
        $auth = $this->authService();
        $security = $this->authSecurityService();
        $correlation = $this->uuid();
        $email = 'replay-' . bin2hex(random_bytes(5)) . '@example.test';
        $password = 'correct horse battery staple';
        $registered = $auth->register($email, $password, '192.0.2.78', $correlation);
        $auth->verifyEmail($registered['verification_token'], $correlation);
        $session = $auth->login($email, $password, '192.0.2.78', 'replay test', $correlation);
        $principal = $auth->authenticateAccessToken($session['access_token']);
        $setup = $security->beginTotpSetup($principal, $correlation);
        $security->confirmTotpSetup($principal, $setup['factor_id'], $this->totp($setup['secret'], time()), $correlation);

        $code = $this->totp($setup['secret'], time() + 30);
        $security->reauthenticate($principal, $password, $code, null, $correlation);
        try {
            $security->reauthenticate($principal, $password, $code, null, $correlation);
            self::fail('Replayed TOTP code was accepted.');
        } catch (AuthFailure $failure) {
            self::assertSame('mfa_code_invalid', $failure->errorCode);
        }
    }

    public function testWrongPasswordDoesNotRefreshEvidence(): void
    {
        $auth = $this->authService();
        $security = $this->authSecurityService();
        $correlation = $this->uuid();
        $email = 'wrong-' . bin2hex(random_bytes(5)) . '@example.test';
        $password = 'correct horse battery staple';
        $registered = $auth->register($email, $password, '192.0.2.79', $correlation);
        $auth->verifyEmail($registered['verification_token'], $correlation);
        $session = $auth->login($email, $password, '192.0.2.79', 'wrong test', $correlation);
        $principal = $auth->authenticateAccessToken($session['access_token']);
        $setup = $security->beginTotpSetup($principal, $correlation);
        $security->confirmTotpSetup($principal, $setup['factor_id'], $this->totp($setup['secret'], time()), $correlation);
        $this->expireEvidence((string) $principal->sessionId(), false, true);

        try {
            $security->reauthenticate($principal, 'wrong password', $this->totp($setup['secret'], time() + 30), null, $correlation);
            self::fail('Wrong password accepted for step-up.');
        } catch (AuthFailure $failure) {
            self::assertSame('invalid_credentials', $failure->errorCode);
        }

        $stale = $auth->authenticateAccessToken($session['access_token']);
        try {
            $security->requireSensitiveMfa($stale);
            self::fail('Failed step-up refreshed the session evidence.');
        } catch (AuthFailure $failure) {
            self::assertSame('recent_mfa_required', $failure->errorCode);
        }
    }

    private function expireEvidence(string $sessionId, bool $primary, bool $mfa): void
    {
        if ($primary) {
            $this->connection->executeStatement(
                "UPDATE commercial.control_sessions SET primary_authenticated_at = now() - interval '1 hour' WHERE id = ?",
                [$sessionId],
            );
        }
        if ($mfa) {
            $this->connection->executeStatement(
                "UPDATE commercial.control_sessions SET mfa_authenticated_at = now() - interval '1 hour' WHERE id = ?",
                [$sessionId],
            );
        }
    }

    private function totp(string $secret, int $timestamp): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $part) {
            $key .= chr(intval($part, 2));
        }
        $counter = intdiv($timestamp, 30);
        $mac = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($mac[19]) & 15;
        $value = ((ord($mac[$offset]) & 127) << 24) | ((ord($mac[$offset + 1]) & 255) << 16) | ((ord($mac[$offset + 2]) & 255) << 8) | (ord($mac[$offset + 3]) & 255);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
