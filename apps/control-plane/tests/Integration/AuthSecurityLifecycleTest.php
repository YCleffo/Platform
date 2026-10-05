<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Integration;
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Security\ControlUserPrincipal;
final class AuthSecurityLifecycleTest extends DatabaseTestCase
{
    public function testPasswordResetUsesHashOnlyArtifactsAndRevokesSessions(): void
    {
        $auth=$this->authService(); $security=$this->authSecurityService(); $id=$this->uuid();
        $registered=$auth->register('reset@example.test','correct horse battery staple','192.0.2.1',$id); $auth->verifyEmail($registered['verification_token'],$id);
        $session=$auth->login('reset@example.test','correct horse battery staple','192.0.2.1','test',$id);
        $security->requestPasswordReset('reset@example.test','192.0.2.1',$id);
        $reset=$this->connection->fetchAssociative('SELECT id, encode(token_hash,\'hex\') hash FROM commercial.control_user_password_resets WHERE control_user_id=?',[$registered['user_id']]); self::assertIsArray($reset);
        self::assertNotSame('',(string)$reset['hash']); self::assertSame(0,(int)$this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE payload::text LIKE '%password_reset%' AND payload::text LIKE '%r1.%'"));
        $token=(new \ControlPlane\Identity\Security\TokenCodec('test-only-control-token-secret-at-least-32-chars'))->passwordResetToken((string)$reset['id']);
        $security->confirmPasswordReset($token,'another correct horse battery staple',$id);
        self::assertSame(1,(int)$this->connection->fetchOne('SELECT count(*) FROM commercial.control_sessions WHERE control_user_id=? AND revoked_at IS NOT NULL',[$registered['user_id']]));
        try{$auth->authenticateAccessToken($session['access_token']);self::fail('Revoked session authenticated.');}catch(AuthFailure $e){self::assertSame('invalid_access_token',$e->errorCode);}
    }

    public function testSensitiveMfaPolicyAndSessionMetadataArePerSession(): void
    {
        $security=$this->authSecurityService(); $user=$this->user(); $sessionA=$this->uuid(); $sessionB=$this->uuid();
        foreach([$sessionA,$sessionB] as $session){$this->connection->executeStatement("INSERT INTO commercial.control_sessions (id,control_user_id,access_token_hash,refresh_token_hash,access_expires_at,expires_at,ip_hash,user_agent,authentication_method,mfa_authenticated_at) VALUES (?,?,decode(?,'hex'),decode(?,'hex'),now()+interval '1 hour',now()+interval '1 day',decode(?,'hex'),'test','password_totp',now())",[$session,$user,hash('sha256',$session.'a'),hash('sha256',$session.'r'),hash('sha256',$session)]);}
        $principal=new ControlUserPrincipal($user,'test@example.test','!test-hash!',$sessionA,new \DateTimeImmutable('now'),new \DateTimeImmutable('now'));
        try{$security->requireSensitiveMfa($principal);self::fail('Unenrolled MFA accepted.');}catch(AuthFailure $e){self::assertSame('mfa_enrollment_required',$e->errorCode);}
        $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; $cipher=new \ControlPlane\Identity\Security\TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='); $factor=$this->uuid();
        $this->connection->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,confirmed_at) VALUES (?, ?, decode(?, 'hex'),'v1','active',now())",[$factor,$user,bin2hex($cipher->encrypt($secret))]);
        $security->requireSensitiveMfa($principal);
        $rows=$security->sessions($principal); self::assertCount(2,$rows); self::assertArrayHasKey('mfa_authenticated_at',$rows[0]);
    }
}
