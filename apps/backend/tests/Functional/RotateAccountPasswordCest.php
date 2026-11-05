<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\CreateAuthChallengeCommand;
use App\Core\Auth\Application\OpaqueTokenService;
use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Auth\Application\RotateAccountPassword;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Client\Application\ClientContextResolver;
use App\Tests\Support\ApiTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertFalse;

final readonly class RotateAccountPasswordCest
{
    public function rotationRevokesSessionsAndAllChallengesPreservingIdentityRolesAndMfa(): void
    {
        $container = ApiTester::getTestContainer();
        $db = ApiTester::getDb();
        $tx = $db->beginTransaction();
        try {
            $hasher = $container->get(PasswordHasherInterface::class);
            $old = 'Old operator fixture password';
            $new = 'New operator fixture password';
            $person = $db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES('Rotation','Fixture') RETURNING id::text")->queryScalar();
            $id = $db->createCommand("INSERT INTO core.accounts(person_id,account_type,status) VALUES(CAST(:person AS uuid),'human','active') RETURNING id::text", [':person' => $person])->queryScalar();
            $email = 'rotation-' . bin2hex(random_bytes(8)) . '@example.test';
            $db->createCommand("INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value,is_primary,can_login,can_recover,verified_at) VALUES(CAST(:id AS uuid),'email',:email,:email,true,true,true,now())", [':id' => $id, ':email' => $email])->execute();
            $db->createCommand("INSERT INTO core.auth_password_credentials(account_id,password_hash,password_algorithm) VALUES(CAST(:id AS uuid),:hash,'argon2id')", [':id' => $id, ':hash' => $hasher->hash($old)])->execute();
            $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:id AS uuid),id FROM core.roles WHERE code='core.superadmin'", [':id' => $id])->execute();
            $stamp = $db->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
            $db->createCommand("INSERT INTO core.auth_sessions(account_id,session_token_hash,security_stamp,authentication_method,client_type,access_expires_at,expires_at) SELECT id,decode(:hash,'hex'),security_stamp,'password','api-test',now()+interval '1 hour',now()+interval '1 day' FROM core.accounts WHERE id=CAST(:id AS uuid)", [':id' => $id, ':hash' => hash('sha256', random_bytes(32))])->execute();
            $encrypted = $container->get(TotpSecretCipher::class)->encrypt(random_bytes(20));
            $db->createCommand("INSERT INTO core.auth_totp_factors(account_id,label,secret_encrypted,algorithm,digits,period_seconds,verified_at,secret_key_version) VALUES(CAST(:id AS uuid),'Keep MFA',decode(:secret,'hex'),'sha1',6,30,now(),:version)", [':id' => $id, ':secret' => $encrypted->ciphertextHex, ':version' => $encrypted->keyVersion])->execute();
            $challenges = $container->get(AuthChallengeRepository::class);
            $tokens = $container->get(OpaqueTokenService::class);
            $token = $tokens->issue();
            $challenges->storeChallenge(new CreateAuthChallengeCommand($id, 'mfa_recovery_reauth_pending', $token->hashHex, null, ['purpose' => 'mfa_recovery_reauth_pending'], 3, gmdate('Y-m-d\TH:i:s\Z', time() + 300)));
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(serverParams: ['REMOTE_ADDR' => '127.0.0.1'], headers: ['User-Agent' => 'Operator rotation test']));
            $container->get(RotateAccountPassword::class)->execute($id, $new, $context);
            $hash = $db->createCommand('SELECT password_hash FROM core.auth_password_credentials WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
            assertTrue($hasher->verify($new, $hash));
            assertFalse($hasher->verify($old, $hash));
            assertNotSame($stamp, $db->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar());
            assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.auth_sessions WHERE account_id=CAST(:id AS uuid) AND revoked_at IS NULL', [':id' => $id])->queryScalar());
            assertNull($challenges->findActiveBySecretHashHex($token->hashHex));
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.auth_totp_factors WHERE account_id=CAST(:id AS uuid) AND revoked_at IS NULL', [':id' => $id])->queryScalar());
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.account_roles WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->queryScalar());
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.audit_events WHERE action='auth.password.reset' AND resource_id=:id", [':id' => $id])->queryScalar());
            // Deterministic interleaving: rotate after the old password verifies,
            // before Login may issue a session or an MFA challenge.
            foreach ([true, false] as $mfa) {
                if (!$mfa) {
                    $db->createCommand('UPDATE core.auth_totp_factors SET revoked_at=now() WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->execute();
                }
                $next = $new . '-rotated';
                $rotate = fn() => $container->get(RotateAccountPassword::class)->execute($id, $next, $context);
                $interleaved = new class ($hasher, $rotate) implements PasswordHasherInterface {
                    public function __construct(private PasswordHasherInterface $base, private \Closure $rotate) {}
                    public function hash(string $password): string
                    {
                        return $this->base->hash($password);
                    }
                    public function needsRehash(string $hash): bool
                    {
                        return $this->base->needsRehash($hash);
                    }
                    public function verify(string $password, string $hash): bool
                    {
                        $valid = $this->base->verify($password, $hash);
                        if ($valid) {
                            ($this->rotate)();
                        }
                        return $valid;
                    }
                };
                $arguments = [];
                foreach ((new \ReflectionMethod(\App\Core\Auth\Application\Login::class, '__construct'))->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    assert($type instanceof \ReflectionNamedType);
                    $arguments[] = $type->getName() === PasswordHasherInterface::class ? $interleaved : $container->get($type->getName());
                }
                $login = new \App\Core\Auth\Application\Login(...$arguments);
                $rejected = false;
                try {
                    $login->execute(new \App\Core\Auth\Application\LoginCommand('email', $email, $new), $context);
                } catch (\App\Core\Auth\Application\InvalidCredentialsException) {
                    $rejected = true;
                }
                assertTrue($rejected, 'An old verified first factor survived rotation');
                assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.auth_sessions WHERE account_id=CAST(:id AS uuid) AND revoked_at IS NULL', [':id' => $id])->queryScalar());
                assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.auth_challenges WHERE account_id=CAST(:id AS uuid) AND consumed_at IS NULL AND cancelled_at IS NULL', [':id' => $id])->queryScalar());
                $new = $next;
            }
        } finally {
            $tx->rollBack();
        }
    }
}
