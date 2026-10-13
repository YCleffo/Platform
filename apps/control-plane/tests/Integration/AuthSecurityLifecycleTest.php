<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Integration;
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Security\ControlUserPrincipal;
final class AuthSecurityLifecycleTest extends DatabaseTestCase
{
    public function testMfaChallengeIsSingleUseAndRefreshPreservesEvidence(): void
    {
        $auth=$this->authService(); $security=$this->authSecurityService(); $correlation=$this->uuid();
        $registered=$auth->register('mfa@example.test','correct horse battery staple','192.0.2.2',$correlation); $auth->verifyEmail($registered['verification_token'],$correlation);
        $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; $factor=$this->uuid(); $cipher=new \ControlPlane\Identity\Security\TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->connection->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,confirmed_at) VALUES (?, ?, decode(?, 'hex'),'v1','active',now())",[$factor,$registered['user_id'],bin2hex($cipher->encrypt($secret))]);
        $pending=$security->login('mfa@example.test','correct horse battery staple','192.0.2.2','test',$correlation);
        self::assertSame('mfa_required',$pending['status']); self::assertArrayNotHasKey('access_token',$pending);
        $session=$security->verifyMfaLogin((string)$pending['challenge_token'],$this->totp($secret,time()),null,'192.0.2.2','test',$correlation);
        $row=$this->connection->fetchAssociative("SELECT id,authentication_method,primary_authenticated_at,mfa_authenticated_at FROM commercial.control_sessions WHERE access_token_hash=decode(?,'hex')",[hash('sha256',$session['access_token'])]); self::assertIsArray($row); self::assertSame('password_totp',$row['authentication_method']);
        $before=[$row['id'],$row['authentication_method'],$row['primary_authenticated_at'],$row['mfa_authenticated_at']]; $rotated=$auth->refresh($session['refresh_token'],'192.0.2.2','other',$correlation);
        $after=$this->connection->fetchAssociative('SELECT id,authentication_method,primary_authenticated_at,mfa_authenticated_at FROM commercial.control_sessions WHERE id=?',[$row['id']]); self::assertIsArray($after); self::assertSame($before,[$after['id'],$after['authentication_method'],$after['primary_authenticated_at'],$after['mfa_authenticated_at']]);
        try{$security->verifyMfaLogin((string)$pending['challenge_token'],$this->totp($secret,time()),null,'192.0.2.2','test',$correlation);self::fail('Consumed challenge reused.');}catch(AuthFailure $e){self::assertSame('mfa_challenge_invalid',$e->errorCode);}
        self::assertNotSame($session['refresh_token'],$rotated['refresh_token']);
    }

    public function testInvalidMfaAttemptsPersistAndTerminallyInvalidateChallenge(): void
    {
        $auth=$this->authService();$security=$this->authSecurityService();$correlation=$this->uuid();$email='attempts-'.bin2hex(random_bytes(5)).'@example.test';$registered=$auth->register($email,'correct horse battery staple','192.0.2.5',$correlation);$auth->verifyEmail($registered['verification_token'],$correlation);
        $factor=$this->uuid();$cipher=new \ControlPlane\Identity\Security\TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');$this->connection->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,confirmed_at) VALUES (?, ?, decode(?,'hex'),'v1','active',now())",[$factor,$registered['user_id'],bin2hex($cipher->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'))]);
        $pending=$security->login($email,'correct horse battery staple','192.0.2.5','test',$correlation);
        for($i=1;$i<=8;++$i){try{$security->verifyMfaLogin((string)$pending['challenge_token'],'000000',null,'192.0.2.5','test',$correlation);self::fail('Invalid code accepted.');}catch(AuthFailure $e){self::assertSame('mfa_code_invalid',$e->errorCode);}}
        $row=$this->connection->fetchAssociative('SELECT attempts,invalidated_at FROM commercial.control_auth_challenges WHERE token_hash=decode(?,\'hex\')',[hash('sha256',$pending['challenge_token'])]);self::assertIsArray($row);self::assertSame(8,(int)$row['attempts']);self::assertNotNull($row['invalidated_at']);self::assertSame(8,(int)$this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='control_mfa.login_denied' AND outcome='denied'"));
    }
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

    public function testDatabaseAllowsOnlyOneActiveChallengeAndPendingSetup(): void
    {
        $user=$this->user(); $challenge=$this->uuid();
        $this->connection->executeStatement("INSERT INTO commercial.control_auth_challenges (id,control_user_id,token_hash,kind,expires_at) VALUES (?, ?, decode(?,'hex'),'mfa_login',now()+interval '5 minutes')",[$challenge,$user,hash('sha256','first')]);
        $this->expectDatabaseRejection(fn()=>$this->connection->executeStatement("INSERT INTO commercial.control_auth_challenges (id,control_user_id,token_hash,kind,expires_at) VALUES (?, ?, decode(?,'hex'),'mfa_login',now()+interval '5 minutes')",[$this->uuid(),$user,hash('sha256','second')]));
        $cipher=new \ControlPlane\Identity\Security\TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->connection->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,expires_at) VALUES (?, ?, decode(?,'hex'),'v1','pending',now()+interval '5 minutes')",[$this->uuid(),$user,bin2hex($cipher->encrypt('JBSWY3DPEHPK3PXP'))]);
        $this->expectDatabaseRejection(fn()=>$this->connection->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,expires_at) VALUES (?, ?, decode(?,'hex'),'v1','pending',now()+interval '5 minutes')",[$this->uuid(),$user,bin2hex($cipher->encrypt('JBSWY3DPEHPK3PXP'))]));
    }

    private function totp(string $secret, int $timestamp): string
    {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($secret) as $char){$bits.=str_pad(decbin((int)strpos($alphabet,$char)),5,'0',STR_PAD_LEFT);}$key='';foreach(str_split(substr($bits,0,intdiv(strlen($bits),8)*8),8) as $part){$key.=chr(intval($part,2));}$counter=intdiv($timestamp,30);$mac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),$key,true);$offset=ord($mac[19])&15;$value=((ord($mac[$offset])&127)<<24)|(ord($mac[$offset+1])<<16)|(ord($mac[$offset+2])<<8)|ord($mac[$offset+3]);return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
    }
}
