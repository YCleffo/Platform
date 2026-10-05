<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Integration;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Identity\Security\TotpSecretCipher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;

final class MfaConcurrencyTest extends TestCase
{
    private Connection $db;
    protected function setUp(): void { $url=getenv('CONTROL_DATABASE_URL'); if(getenv('APP_ENV')!=='test'||getenv('CONTROL_TEST_ISOLATED')!=='1'||!is_string($url))throw new \RuntimeException('Requires isolated Control test database.'); $this->db=DriverManager::getConnection((new DsnParser(['postgresql'=>'pdo_pgsql']))->parse($url)); }
    protected function tearDown(): void { $this->db->close(); }

    public function testConcurrentMfaChallengeConsumptionIssuesExactlyOneSession(): void
    {
        [$auth,$security]=$this->services(); $correlation=$this->uuid(); $ip=$this->uniqueIp(); $email='mfa-race-'.bin2hex(random_bytes(5)).'@example.test'; $registered=$auth->register($email,'correct horse battery staple',$ip,$correlation); $auth->verifyEmail($registered['verification_token'],$correlation);
        $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; $factor=$this->uuid(); $cipher=new TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->db->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,confirmed_at) VALUES (?, ?, decode(?,'hex'),'v1','active',now())",[$factor,$registered['user_id'],bin2hex($cipher->encrypt($secret))]);
        $pending=$security->login($email,'correct horse battery staple',$ip,'race',$correlation); $this->race('mfa_verify',(string)$pending['challenge_token'],$this->totp($secret,time()).'|'.$correlation);
        self::assertNotNull($this->db->fetchOne('SELECT consumed_at FROM commercial.control_auth_challenges WHERE token_hash=decode(?,\'hex\')',[hash('sha256',$pending['challenge_token'])]));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM commercial.control_sessions WHERE control_user_id=? AND authentication_method='password_totp'",[$registered['user_id']]));
    }

    public function testConcurrentPasswordResetConfirmationConsumesOnce(): void
    {
        [$auth,$security]=$this->services(); $correlation=$this->uuid(); $ip=$this->uniqueIp(); $email='reset-race-'.bin2hex(random_bytes(5)).'@example.test'; $registered=$auth->register($email,'correct horse battery staple',$ip,$correlation); $auth->verifyEmail($registered['verification_token'],$correlation); $session=$auth->login($email,'correct horse battery staple',$ip,'race',$correlation);
        $security->requestPasswordReset($email,$ip,$correlation); $id=$this->db->fetchOne('SELECT id FROM commercial.control_user_password_resets WHERE control_user_id=? AND invalidated_at IS NULL',[$registered['user_id']]); $token=(new TokenCodec('test-only-control-token-secret-at-least-32-chars'))->passwordResetToken((string)$id);
        $this->race('reset_confirm',$token,'another correct horse battery staple|'.$correlation);
        self::assertNotNull($this->db->fetchOne('SELECT consumed_at FROM commercial.control_user_password_resets WHERE id=?',[$id])); self::assertNotNull($this->db->fetchOne('SELECT revoked_at FROM commercial.control_sessions WHERE access_token_hash=decode(?,\'hex\')',[hash('sha256',$session['access_token'])]));
    }

    public function testConcurrentRecoveryCodeConsumptionAllowsOneMfaLogin(): void
    {
        [$auth,$security]=$this->services(); $correlation=$this->uuid(); $ip=$this->uniqueIp(); $email='recovery-race-'.bin2hex(random_bytes(5)).'@example.test'; $registered=$auth->register($email,'correct horse battery staple',$ip,$correlation); $auth->verifyEmail($registered['verification_token'],$correlation);
        $factor=$this->activeFactor($registered['user_id']); $code='ABCD-'.strtoupper(bin2hex(random_bytes(2))); $this->db->executeStatement("INSERT INTO commercial.control_user_recovery_codes (control_user_id,factor_id,code_hash) VALUES (?, ?, decode(?,'hex'))",[$registered['user_id'],$factor,hash('sha256',$code)]);
        $pending=$security->login($email,'correct horse battery staple',$ip,'race',$correlation); $this->race('mfa_recovery_verify',(string)$pending['challenge_token'],$code.'|'.$correlation);
        self::assertNotNull($this->db->fetchOne('SELECT consumed_at FROM commercial.control_user_recovery_codes WHERE factor_id=? AND code_hash=decode(?,\'hex\')',[$factor,hash('sha256',$code)])); self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM commercial.control_sessions WHERE control_user_id=? AND authentication_method='password_recovery'",[$registered['user_id']]));
    }

    private function activeFactor(string $userId): string { $id=$this->uuid();$cipher=new TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');$this->db->executeStatement("INSERT INTO commercial.control_user_totp_factors (id,control_user_id,secret_ciphertext,key_version,status,confirmed_at) VALUES (?, ?, decode(?,'hex'),'v1','active',now())",[$id,$userId,bin2hex($cipher->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'))]);return $id; }

    /** @return array{AuthService,AuthSecurityService} */ private function services(): array { $tokens=new TokenCodec('test-only-control-token-secret-at-least-32-chars');$hasher=new UserPasswordHasher(new PasswordHasherFactory([ControlUserPrincipal::class=>['algorithm'=>'auto']]));$auth=new AuthService($this->db,$hasher,$tokens,new DatabaseRateLimiter($this->db,$tokens),3600,900,2592000);return [$auth,new AuthSecurityService($this->db,$hasher,$tokens,new TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='),new DatabaseRateLimiter($this->db,$tokens),$auth,3600,300,600,900)]; }
    private function uuid(): string { return \Symfony\Component\Uid\Uuid::v7()->toRfc4122(); }
    private function uniqueIp(): string { return '198.51.'.random_int(0, 255).'.'.random_int(1, 254); }
    private function race(string $mode,string $value,string $extra): void { $start=(string)(microtime(true)+.4);$workers=[$this->launch($mode,$value,$extra,$start),$this->launch($mode,$value,$extra,$start)];$codes=[$this->finish($workers[0]),$this->finish($workers[1])];sort($codes);self::assertSame([0,2],$codes); }
    /** @return array{resource,array<resource>} */ private function launch(string $mode,string $value,string $extra,string $start): array {$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/race-worker.php',$mode,$value,$extra,$start],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new \RuntimeException('Cannot launch worker.');return[$process,$pipes];}
    /** @param array{resource,array<resource>} $worker */ private function finish(array $worker): int {[$process,$pipes]=$worker;foreach($pipes as $pipe){stream_get_contents($pipe);fclose($pipe);}return proc_close($process);}
    private function totp(string $secret,int $time): string {$a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($secret)as$c){$bits.=str_pad(decbin((int)strpos($a,$c)),5,'0',STR_PAD_LEFT);}$key='';foreach(str_split(substr($bits,0,intdiv(strlen($bits),8)*8),8)as$b){$key.=chr(bindec($b));}$n=intdiv($time,30);$m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$key,true);$o=ord($m[19])&15;$v=((ord($m[$o])&127)<<24)|(ord($m[$o+1])<<16)|(ord($m[$o+2])<<8)|ord($m[$o+3]);return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);}
}
