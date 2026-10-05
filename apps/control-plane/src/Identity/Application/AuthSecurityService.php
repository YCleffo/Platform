<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Application;

use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Identity\Domain\PasswordPolicy;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Identity\Security\Totp;
use ControlPlane\Identity\Security\TotpSecretCipher;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AuthSecurityService
{
    public function __construct(
        private Connection $connection, private UserPasswordHasherInterface $passwordHasher,
        private TokenCodec $tokens, private TotpSecretCipher $cipher, private DatabaseRateLimiter $rateLimiter,
        private AuthService $sessions, private int $passwordResetTtlSeconds, private int $mfaChallengeTtlSeconds,
        private int $totpSetupTtlSeconds, private int $recentAuthenticationTtlSeconds,
    ) {}

    /** @return array<string, mixed> */
    public function login(string $emailInput, string $password, string $ip, string $agent, string $correlation): array
    {
        $email = EmailPolicy::normalize($emailInput)['normalized'];
        $this->rateLimiter->consume('auth.login.ip', $ip, 30, 300);
        $this->rateLimiter->consume('auth.login.identity', $email, 10, 300);
        $user = $this->connection->fetchAssociative('SELECT id, email_normalized, password_hash, status FROM commercial.control_users WHERE email_normalized = ?', [$email]);
        $valid = $user !== false && is_string($user['id']) && is_string($user['password_hash'])
            && $this->passwordHasher->isPasswordValid(new ControlUserPrincipal($user['id'], $email, $user['password_hash']), $password);
        if (!$valid || $user === false || $user['status'] !== 'active' || !is_string($user['id'])) {
            throw new AuthFailure('invalid_credentials', 'Invalid credentials.');
        }
        $factor = $this->connection->fetchAssociative("SELECT id FROM commercial.control_user_totp_factors WHERE control_user_id = ? AND status = 'active'", [$user['id']]);
        if ($factor === false) { return $this->sessions->login($emailInput, $password, $ip, $agent, $correlation); }
        $challengeId = Uuid::v7()->toRfc4122();
        $token = $this->tokens->mfaChallengeToken($challengeId);
        $this->connection->transactional(function (Connection $db) use ($user, $challengeId, $token, $correlation): void {
            $db->executeStatement("UPDATE commercial.control_auth_challenges SET invalidated_at = now() WHERE control_user_id = ? AND kind = 'mfa_login' AND consumed_at IS NULL AND invalidated_at IS NULL", [$user['id']]);
            $db->executeStatement("INSERT INTO commercial.control_auth_challenges (id, control_user_id, token_hash, kind, expires_at) VALUES (?, ?, decode(?, 'hex'), 'mfa_login', now() + (? * interval '1 second'))", [$challengeId, $user['id'], $this->tokens->hash($token), $this->mfaChallengeTtlSeconds]);
            $this->audit($db, $user['id'], 'control_mfa.challenge_created', 'control_auth_challenge', $challengeId, $correlation);
        });
        return ['status' => 'mfa_required', 'challenge_token' => $token, 'expires_in' => $this->mfaChallengeTtlSeconds];
    }

    public function requestPasswordReset(string $emailInput, string $ip, string $correlation): void
    {
        $email = EmailPolicy::normalize($emailInput)['normalized'];
        $this->rateLimiter->consume('auth.password_reset.ip', $ip, 5, 3600);
        $this->rateLimiter->consume('auth.password_reset.identity', $email, 3, 3600);
        $this->connection->transactional(function (Connection $db) use ($email, $correlation): void {
            $user = $db->fetchAssociative("SELECT id FROM commercial.control_users WHERE email_normalized = ? AND status = 'active' FOR UPDATE", [$email]);
            if ($user === false || !is_string($user['id'])) { return; }
            $id = Uuid::v7()->toRfc4122(); $token = $this->tokens->passwordResetToken($id);
            $db->executeStatement('UPDATE commercial.control_user_password_resets SET invalidated_at = now() WHERE control_user_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL', [$user['id']]);
            $db->executeStatement("INSERT INTO commercial.control_user_password_resets (id, control_user_id, token_hash, expires_at) VALUES (?, ?, decode(?, 'hex'), now() + (? * interval '1 second'))", [$id, $user['id'], $this->tokens->hash($token), $this->passwordResetTtlSeconds]);
            $this->audit($db, $user['id'], 'control_password.reset_requested', 'control_password_reset', $id, $correlation);
            $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('control_user.password_reset_requested', 'control_user', ?, jsonb_build_object('user_id', ?::text, 'reset_id', ?::text))", [$user['id'], $user['id'], $id]);
        });
    }

    public function confirmPasswordReset(string $token, string $password, string $correlation): void
    {
        try { $this->tokens->validatePasswordResetToken($token); PasswordPolicy::validate($password); } catch (\Throwable) { throw new AuthFailure('password_reset_invalid'); }
        $this->rateLimiter->consume('auth.password_reset.confirm', $this->tokens->hash($token), 10, 3600);
        $this->connection->transactional(function (Connection $db) use ($token, $password, $correlation): void {
            $reset = $db->fetchAssociative("SELECT r.*, u.email_normalized, u.status FROM commercial.control_user_password_resets r JOIN commercial.control_users u ON u.id = r.control_user_id WHERE r.token_hash = decode(?, 'hex') FOR UPDATE OF r, u", [$this->tokens->hash($token)]);
            if ($reset === false || $reset['consumed_at'] !== null || $reset['invalidated_at'] !== null || strtotime((string) $reset['expires_at']) <= time() || $reset['status'] !== 'active') throw new AuthFailure('password_reset_invalid');
            $principal = new ControlUserPrincipal((string) $reset['control_user_id'], (string) $reset['email_normalized'], '');
            $db->executeStatement('UPDATE commercial.control_users SET password_hash = ?, password_changed_at = now(), updated_at = now() WHERE id = ?', [$this->passwordHasher->hashPassword($principal, $password), $reset['control_user_id']]);
            $db->executeStatement('UPDATE commercial.control_user_password_resets SET consumed_at = now() WHERE id = ?', [$reset['id']]);
            $this->revokeSecurityArtifacts($db, (string) $reset['control_user_id'], 'password_reset');
            $this->audit($db, (string) $reset['control_user_id'], 'control_password.reset_completed', 'control_user', (string) $reset['control_user_id'], $correlation);
            $this->notify($db, (string) $reset['control_user_id'], 'control_user.password_changed');
        });
    }

    /** @return array{factor_id:string,secret:string,otpauth_uri:string} */
    public function beginTotpSetup(ControlUserPrincipal $user, string $correlation): array
    {
        $this->requireRecentPrimary($user); $this->rateLimiter->consume('auth.mfa.totp_setup', $user->id(), 10, 3600); $id = Uuid::v7()->toRfc4122(); $secret = Totp::newSecret();
        $this->connection->transactional(function (Connection $db) use ($user, $id, $secret, $correlation): void {
            $db->executeStatement("UPDATE commercial.control_user_totp_factors SET status = 'revoked', revoked_at = now() WHERE control_user_id = ? AND status = 'pending'", [$user->id()]);
            $db->executeStatement("INSERT INTO commercial.control_user_totp_factors (id, control_user_id, secret_ciphertext, key_version, expires_at) VALUES (?, ?, ?, 'v1', now() + (? * interval '1 second'))", [$id, $user->id(), $this->cipher->encrypt($secret), $this->totpSetupTtlSeconds]);
            $this->audit($db, $user->id(), 'control_mfa.setup_started', 'control_totp_factor', $id, $correlation);
        });
        return ['factor_id' => $id, 'secret' => $secret, 'otpauth_uri' => 'otpauth://totp/' . rawurlencode('Platform:' . $user->getUserIdentifier()) . '?secret=' . rawurlencode($secret) . '&issuer=Platform&algorithm=SHA1&digits=6&period=30'];
    }

    /** @return list<string> */
    public function confirmTotpSetup(ControlUserPrincipal $user, string $factorId, string $code, string $correlation): array
    {
        $this->requireRecentPrimary($user); $this->rateLimiter->consume('auth.mfa.totp_confirm', $user->id(), 10, 3600);
        return $this->connection->transactional(function (Connection $db) use ($user, $factorId, $code, $correlation): array {
            $factor = $db->fetchAssociative("SELECT * FROM commercial.control_user_totp_factors WHERE id = ? AND control_user_id = ? FOR UPDATE", [$factorId, $user->id()]);
            if ($factor === false || $factor['status'] !== 'pending' || strtotime((string) $factor['expires_at']) <= time()) throw new AuthFailure('totp_setup_invalid');
            $counter = Totp::counterFor($this->cipher->decrypt((string) $factor['secret_ciphertext']), $code, time());
            if ($counter === null || $counter <= (int) $factor['last_accepted_counter']) throw new AuthFailure('totp_code_invalid');
            $db->executeStatement("UPDATE commercial.control_user_totp_factors SET status = 'revoked', revoked_at = now() WHERE control_user_id = ? AND status = 'active'", [$user->id()]);
            if ($db->executeStatement("UPDATE commercial.control_user_totp_factors SET status = 'active', confirmed_at = now(), expires_at = NULL, last_accepted_counter = ? WHERE id = ? AND last_accepted_counter < ?", [$counter, $factorId, $counter]) !== 1) throw new AuthFailure('totp_code_replayed');
            $codes = $this->createRecoveryCodes($db, $user->id(), $factorId); $this->audit($db, $user->id(), 'control_mfa.enabled', 'control_totp_factor', $factorId, $correlation); $this->notify($db, $user->id(), 'control_user.mfa_enabled');
            return $codes;
        });
    }

    /** @return array{enabled:bool,factor_id:?string,recovery_codes_remaining:int} */
    public function mfaStatus(ControlUserPrincipal $user): array
    {
        $factor = $this->connection->fetchAssociative("SELECT id FROM commercial.control_user_totp_factors WHERE control_user_id = ? AND status = 'active'", [$user->id()]);
        if ($factor === false) return ['enabled' => false, 'factor_id' => null, 'recovery_codes_remaining' => 0];
        return ['enabled' => true, 'factor_id' => (string) $factor['id'], 'recovery_codes_remaining' => (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_user_recovery_codes WHERE factor_id = ? AND consumed_at IS NULL AND revoked_at IS NULL', [$factor['id']])];
    }

    /** @return array<string, mixed> */
    public function verifyMfaLogin(string $token, ?string $code, ?string $recoveryCode, string $ip, string $agent, string $correlation): array
    {
        try { $this->tokens->validateMfaChallengeToken($token); } catch (\Throwable) { throw new AuthFailure('mfa_challenge_invalid'); }
        if (($code === null) === ($recoveryCode === null)) throw new AuthFailure('mfa_method_required');
        $this->rateLimiter->consume('auth.mfa.login_verify.ip', $ip, 30, 300);
        $this->rateLimiter->consume('auth.mfa.login_verify.challenge', $this->tokens->hash($token), 10, 300);
        return $this->connection->transactional(function (Connection $db) use ($token, $code, $recoveryCode, $ip, $agent, $correlation): array {
            $challenge = $db->fetchAssociative("SELECT c.*, u.email_normalized FROM commercial.control_auth_challenges c JOIN commercial.control_users u ON u.id=c.control_user_id WHERE c.token_hash=decode(?, 'hex') FOR UPDATE OF c, u", [$this->tokens->hash($token)]);
            if ($challenge === false || $challenge['consumed_at'] !== null || $challenge['invalidated_at'] !== null || strtotime((string) $challenge['expires_at']) <= time()) throw new AuthFailure('mfa_challenge_invalid');
            $factor = $db->fetchAssociative("SELECT * FROM commercial.control_user_totp_factors WHERE control_user_id=? AND status='active' FOR UPDATE", [$challenge['control_user_id']]);
            $method = null;
            if ($factor !== false && $code !== null) { $counter = Totp::counterFor($this->cipher->decrypt((string)$factor['secret_ciphertext']), $code, time()); if ($counter !== null && $counter > (int)$factor['last_accepted_counter'] && $db->executeStatement('UPDATE commercial.control_user_totp_factors SET last_accepted_counter=? WHERE id=? AND last_accepted_counter < ?', [$counter, $factor['id'], $counter]) === 1) $method='password_totp'; }
            if ($factor !== false && $recoveryCode !== null) { $id=$db->fetchOne("UPDATE commercial.control_user_recovery_codes SET consumed_at=now() WHERE id=(SELECT id FROM commercial.control_user_recovery_codes WHERE factor_id=? AND code_hash=decode(?, 'hex') AND consumed_at IS NULL AND revoked_at IS NULL FOR UPDATE) RETURNING id", [$factor['id'], $this->tokens->hash($recoveryCode)]); if ($id !== false) { $method='password_recovery'; $this->audit($db, (string) $challenge['control_user_id'], 'control_mfa.recovery_code_consumed', 'control_user_recovery_code', (string) $id, $correlation); } }
            if ($method === null) { $db->executeStatement("UPDATE commercial.control_auth_challenges SET attempts=attempts+1, invalidated_at=CASE WHEN attempts+1 >= 8 THEN now() ELSE invalidated_at END WHERE id=?", [$challenge['id']]); $this->audit($db, (string) $challenge['control_user_id'], 'control_mfa.login_denied', 'control_auth_challenge', (string) $challenge['id'], $correlation, 'denied'); throw new AuthFailure('mfa_code_invalid'); }
            $db->executeStatement('UPDATE commercial.control_auth_challenges SET consumed_at=now() WHERE id=?', [$challenge['id']]); $this->audit($db, (string)$challenge['control_user_id'], 'control_mfa.login_verified', 'control_auth_challenge', (string)$challenge['id'], $correlation);
            return $this->sessions->issueMfaSession((string)$challenge['control_user_id'], (string)$challenge['email_normalized'], $ip, $agent, $correlation, $method);
        });
    }

    public function requireRecentPrimary(ControlUserPrincipal $user): void
    {
        if ($user->sessionId() === null || $user->primaryAuthenticatedAt() === null || $user->primaryAuthenticatedAt()->getTimestamp() < time() - $this->recentAuthenticationTtlSeconds) throw new AuthFailure('recent_authentication_required');
    }

    /** Requires enrolled MFA plus current-session primary and MFA evidence. */
    public function requireSensitiveMfa(ControlUserPrincipal $user): void
    {
        if (!$this->mfaStatus($user)['enabled']) throw new AuthFailure('mfa_enrollment_required');
        $this->requireRecentPrimary($user);
        if ($user->mfaAuthenticatedAt() === null || $user->mfaAuthenticatedAt()->getTimestamp() < time() - $this->recentAuthenticationTtlSeconds) throw new AuthFailure('recent_mfa_required');
    }

    /** @return list<array<string, mixed>> */
    public function sessions(ControlUserPrincipal $user): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, issued_at, last_used_at, expires_at, revoked_at, revoke_reason, user_agent, authentication_method, primary_authenticated_at, mfa_authenticated_at, (id = ?::uuid) AS current FROM commercial.control_sessions WHERE control_user_id = ? ORDER BY issued_at DESC', [$user->sessionId(), $user->id()]);
    }

    public function revokeSession(ControlUserPrincipal $user, string $sessionId, string $correlation): void
    {
        $this->requireSensitiveMfa($user); $this->rateLimiter->consume('auth.session.revoke', $user->id(), 30, 3600);
        if (!Uuid::isValid($sessionId) || $this->connection->executeStatement("UPDATE commercial.control_sessions SET revoked_at=COALESCE(revoked_at, now()), revoke_reason=COALESCE(revoke_reason, 'user_revoked') WHERE id=? AND control_user_id=?", [$sessionId, $user->id()]) !== 1) throw new AuthFailure('session_not_found');
        $this->audit($this->connection, $user->id(), 'control_session.revoked', 'control_session', $sessionId, $correlation);
    }

    public function revokeOtherSessions(ControlUserPrincipal $user, string $correlation): void
    {
        $this->requireSensitiveMfa($user); $this->rateLimiter->consume('auth.session.revoke_others', $user->id(), 10, 3600);
        $this->connection->executeStatement("UPDATE commercial.control_sessions SET revoked_at=COALESCE(revoked_at, now()), revoke_reason=COALESCE(revoke_reason, 'user_revoked_others') WHERE control_user_id=? AND id<>?::uuid", [$user->id(), $user->sessionId()]);
        $this->audit($this->connection, $user->id(), 'control_session.others_revoked', 'control_user', $user->id(), $correlation);
    }

    public function changePassword(ControlUserPrincipal $user, string $currentPassword, string $newPassword, string $correlation): void
    {
        $this->requireSensitiveMfa($user); PasswordPolicy::validate($newPassword);
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) throw new AuthFailure('current_password_invalid');
        $this->connection->transactional(function (Connection $db) use ($user, $newPassword, $correlation): void {
            $db->executeStatement('UPDATE commercial.control_users SET password_hash=?, password_changed_at=now(), updated_at=now() WHERE id=?', [$this->passwordHasher->hashPassword($user, $newPassword), $user->id()]);
            $this->revokeSecurityArtifacts($db, $user->id(), 'password_changed'); $this->audit($db, $user->id(), 'control_password.changed', 'control_user', $user->id(), $correlation); $this->notify($db, $user->id(), 'control_user.password_changed');
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(ControlUserPrincipal $user, string $correlation): array
    {
        $this->requireSensitiveMfa($user); $this->rateLimiter->consume('auth.mfa.recovery_regenerate', $user->id(), 10, 3600);
        return $this->connection->transactional(function (Connection $db) use ($user, $correlation): array {
            $factor=$db->fetchAssociative("SELECT id FROM commercial.control_user_totp_factors WHERE control_user_id=? AND status='active' FOR UPDATE",[$user->id()]); if($factor===false)throw new AuthFailure('mfa_not_enabled');
            $db->executeStatement('UPDATE commercial.control_user_recovery_codes SET revoked_at=now() WHERE factor_id=? AND consumed_at IS NULL AND revoked_at IS NULL',[$factor['id']]); $codes=$this->createRecoveryCodes($db,$user->id(),(string)$factor['id']); $this->audit($db,$user->id(),'control_mfa.recovery_codes_regenerated','control_totp_factor',(string)$factor['id'],$correlation); $this->notify($db,$user->id(),'control_user.recovery_codes_regenerated'); return $codes;
        });
    }

    public function disableMfa(ControlUserPrincipal $user, string $correlation): void
    {
        $this->requireSensitiveMfa($user); $this->rateLimiter->consume('auth.mfa.disable', $user->id(), 5, 3600);
        $this->connection->transactional(function (Connection $db) use ($user, $correlation): void {
            $factor=$db->fetchAssociative("SELECT id FROM commercial.control_user_totp_factors WHERE control_user_id=? AND status='active' FOR UPDATE",[$user->id()]); if($factor===false)throw new AuthFailure('mfa_not_enabled');
            $db->executeStatement("UPDATE commercial.control_user_totp_factors SET status='revoked',revoked_at=now() WHERE id=?",[$factor['id']]); $db->executeStatement('UPDATE commercial.control_user_recovery_codes SET revoked_at=now() WHERE factor_id=? AND consumed_at IS NULL AND revoked_at IS NULL',[$factor['id']]); $this->revokeSecurityArtifacts($db,$user->id(),'mfa_disabled'); $this->audit($db,$user->id(),'control_mfa.disabled','control_totp_factor',(string)$factor['id'],$correlation); $this->notify($db,$user->id(),'control_user.mfa_disabled');
        });
    }

    public function reauthenticate(ControlUserPrincipal $user, string $password, ?string $code, ?string $recoveryCode, string $correlation): void
    {
        $this->rateLimiter->consume('auth.mfa.reauthenticate', $user->id(), 20, 3600);
        if ($user->sessionId() === null || !$this->passwordHasher->isPasswordValid($user, $password)) throw new AuthFailure('invalid_credentials');
        $mfaEnabled = $this->mfaStatus($user)['enabled'];
        if ($mfaEnabled && (($code === null) === ($recoveryCode === null))) throw new AuthFailure('mfa_method_required');
        if (!$mfaEnabled && ($code !== null || $recoveryCode !== null)) throw new AuthFailure('mfa_not_enabled');
        $this->connection->transactional(function (Connection $db) use ($user, $code, $recoveryCode, $correlation): void {
            $factor=$db->fetchAssociative("SELECT * FROM commercial.control_user_totp_factors WHERE control_user_id=? AND status='active' FOR UPDATE",[$user->id()]); $mfaAt=null;
            if($factor!==false){$ok=false;if($code!==null){$counter=Totp::counterFor($this->cipher->decrypt((string)$factor['secret_ciphertext']),$code,time());$ok=$counter!==null&&$counter>(int)$factor['last_accepted_counter']&&$db->executeStatement('UPDATE commercial.control_user_totp_factors SET last_accepted_counter=? WHERE id=? AND last_accepted_counter < ?',[$counter,$factor['id'],$counter])===1;}elseif($recoveryCode!==null){$ok=$db->executeStatement("UPDATE commercial.control_user_recovery_codes SET consumed_at=now() WHERE factor_id=? AND code_hash=decode(?, 'hex') AND consumed_at IS NULL AND revoked_at IS NULL",[$factor['id'],$this->tokens->hash($recoveryCode)])===1;}if(!$ok)throw new AuthFailure('mfa_code_invalid');$mfaAt=true;}
            $db->executeStatement('UPDATE commercial.control_sessions SET primary_authenticated_at=now(), mfa_authenticated_at=CASE WHEN ?::boolean THEN now() ELSE mfa_authenticated_at END WHERE id=? AND control_user_id=? AND revoked_at IS NULL',[$mfaAt ? 'true':'false',$user->sessionId(),$user->id()]); $this->audit($db,$user->id(),'control_session.reauthenticated','control_session',$user->sessionId(),$correlation);
        });
    }

    private function revokeSecurityArtifacts(Connection $db, string $userId, string $reason): void
    { $db->executeStatement("UPDATE commercial.control_sessions SET revoked_at=COALESCE(revoked_at, now()), revoke_reason=COALESCE(revoke_reason, ?) WHERE control_user_id=?", [$reason, $userId]); $db->executeStatement('UPDATE commercial.control_user_password_resets SET invalidated_at=now() WHERE control_user_id=? AND consumed_at IS NULL AND invalidated_at IS NULL', [$userId]); $db->executeStatement('UPDATE commercial.control_auth_challenges SET invalidated_at=now() WHERE control_user_id=? AND consumed_at IS NULL AND invalidated_at IS NULL', [$userId]); }
    /** @return list<string> */ private function createRecoveryCodes(Connection $db, string $userId, string $factorId): array
    { $codes=[]; for($i=0;$i<10;++$i){$code=strtoupper(substr(bin2hex(random_bytes(8)),0,4).'-'.substr(bin2hex(random_bytes(8)),0,4));$codes[]=$code;$db->executeStatement("INSERT INTO commercial.control_user_recovery_codes (control_user_id,factor_id,code_hash) VALUES (?, ?, decode(?, 'hex'))",[$userId,$factorId,$this->tokens->hash($code)]);} return $codes; }
    private function audit(Connection $db,string $user,string $action,string $type,string $resource,string $correlation,string $outcome = 'success'): void { $db->executeStatement("INSERT INTO commercial.audit_events (actor_type,actor_id,action,resource_type,resource_id,correlation_id,outcome) VALUES ('control_user', ?, ?, ?, ?, ?, ?)",[$user,$action,$type,$resource,$correlation,$outcome]); }
    private function notify(Connection $db,string $user,string $event): void { $db->executeStatement("INSERT INTO commercial.outbox_events (event_type,aggregate_type,aggregate_id,payload) VALUES (?, 'control_user', ?, jsonb_build_object('user_id', ?::text))",[$event,$user,$user]); }
}
