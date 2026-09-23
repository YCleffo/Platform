<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use App\Core\Auth\Application\RecoveryCodeService;
use App\Core\Auth\Application\TotpSecretCipher;
use Predis\Client as RedisClient;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;

/**
 * Inherited Methods
 * @method void wantTo($text)
 * @method void wantToTest($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHMD)
*/
class ApiTester extends \Codeception\Actor
{
    use _generated\ApiTesterActions;

    private static ?ContainerInterface $testContainer = null;

    public static function getTestContainer(): ContainerInterface
    {
        if (self::$testContainer === null) {
            $runner = new HttpApplicationRunner(
                rootPath: dirname(__DIR__, 2),
                environment: Environment::appEnv(),
            );
            self::$testContainer = $runner->getContainer();
        }

        return self::$testContainer;
    }

    public static function getDb(): ConnectionInterface
    {
        return self::getTestContainer()->get(ConnectionInterface::class);
    }

    public static function flushTestRedis(): void
    {
        try {
            $client = new RedisClient([
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            ]);
            $client->flushdb();
        } catch (\Throwable) {
        }
    }

    public function resetClientState(): void
    {
        $this->deleteHeader('Authorization');
        $this->deleteHeader('X-Client-Locale');
        $this->deleteHeader('Accept-Language');
        $this->deleteHeader('Content-Type');
    }

    /** @return array{accountId: string, token: string} */
    public function amAuthenticatedSuperadmin(?string $locale = null): array
    {
        $db = self::getDb();
        $suffix = bin2hex(random_bytes(6));
        $account = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.accounts (account_type, status, label, locale)
            VALUES ('service', 'active', :label, :locale)
            RETURNING id::text AS id, security_stamp::text AS security_stamp
            SQL,
            [':label' => 'API authorization fixture ' . $suffix, ':locale' => $locale],
        )->queryOne();
        if (!is_array($account)) {
            throw new \RuntimeException('Authentication fixture account could not be created.');
        }
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.account_roles (account_id, role_id)
            SELECT CAST(:account_id AS uuid), id
            FROM core.roles
            WHERE code = 'core.superadmin'
            SQL,
            [':account_id' => $account['id']],
        )->execute();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_sessions (
                account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at
            )
            VALUES (
                CAST(:account_id AS uuid), decode(:token_hash, 'hex'), NULL,
                CAST(:security_stamp AS uuid), 'test', 'api-test',
                now() + interval '15 minutes', now() + interval '1 hour'
            )
            SQL,
            [
                ':account_id' => $account['id'],
                ':security_stamp' => $account['security_stamp'],
                ':token_hash' => hash('sha256', $token),
            ],
        )->execute();
        $this->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return ['accountId' => (string) $account['id'], 'token' => $token];
    }

    /** @return array{accountId: string, token: string} */
    public function amAuthenticatedRole(string $roleCode): array
    {
        return $this->authenticateConfigurationFixture($roleCode, []);
    }

    /** @param list<string> $permissions @return array{accountId: string, token: string} */
    public function amAuthenticatedWithPermissions(array $permissions): array
    {
        return $this->authenticateConfigurationFixture(null, $permissions);
    }

    /** @return array{id: string, code: string} */
    public function haveConfigurationModule(bool $enabled = false): array
    {
        $db = self::getDb();
        $suffix = bin2hex(random_bytes(6));
        $code = 'test.configuration.' . $suffix;
        $id = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.modules (
                code, name, description, version, settings_schema_version,
                enabled, enabled_at, disabled_at
            )
            VALUES (
                :code, 'Configuration fixture', 'Configuration runtime API fixture',
                '1.2.3', 2, :enabled,
                CASE WHEN :enabled_at THEN now() ELSE NULL END,
                CASE WHEN :disabled_at THEN NULL ELSE now() END
            )
            RETURNING id::text
            SQL,
            [
                ':code' => $code,
                ':enabled' => $enabled,
                ':enabled_at' => $enabled,
                ':disabled_at' => $enabled,
            ],
        )->queryScalar();
        if (!is_string($id)) {
            throw new \RuntimeException('Configuration module fixture could not be created.');
        }

        return ['id' => $id, 'code' => $code];
    }

    /** @return list<string> */
    public function grabConfigurationAuditActions(): array
    {
        $rows = self::getDb()->createCommand(
            <<<'SQL'
            SELECT action
            FROM core.audit_events
            WHERE action LIKE 'core.module.%'
               OR action LIKE 'core.system_setting.%'
               OR action LIKE 'core.module_setting.%'
               OR action LIKE 'core.feature_flag.%'
            ORDER BY occurred_at, id
            SQL,
        )->queryColumn();

        return array_values(array_map('strval', $rows));
    }

    /** @return array{accountId: string, email: string, password: string} */
    public function havePasswordAccount(
        string $status = 'active',
        bool $locked = false,
        ?string $locale = null,
    ): array {
        $db = self::getDb();
        $suffix = bin2hex(random_bytes(8));
        $email = 'auth-' . $suffix . '@example.test';
        $password = 'Correct Horse ' . $suffix;
        $account = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.accounts (
                account_type, status, label, locale, locked_until, disabled_at, archived_at
            )
            VALUES (
                'service', CAST(:status AS core.account_status), :label, :locale,
                CASE WHEN :locked THEN now() + interval '1 hour' ELSE NULL END,
                CASE WHEN :status = 'disabled' THEN now() ELSE NULL END,
                CASE WHEN :status = 'archived' THEN now() ELSE NULL END
            )
            RETURNING id::text AS id
            SQL,
            [
                ':status' => $status,
                ':label' => 'API password fixture ' . $suffix,
                ':locale' => $locale,
                ':locked' => $locked,
            ],
        )->queryOne();
        if (!is_array($account)) {
            throw new \RuntimeException('Password fixture account could not be created.');
        }
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_identifiers (
                account_id, identifier_type, value, normalized_value,
                is_primary, can_login, can_recover, verified_at
            )
            VALUES (
                CAST(:account_id AS uuid), 'email', :email, :email,
                true, true, true, now()
            )
            SQL,
            [':account_id' => $account['id'], ':email' => $email],
        )->execute();
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_password_credentials (
                account_id, password_hash, password_algorithm
            )
            VALUES (CAST(:account_id AS uuid), :hash, 'argon2id')
            SQL,
            [':account_id' => $account['id'], ':hash' => password_hash($password, PASSWORD_ARGON2ID)],
        )->execute();

        return ['accountId' => (string) $account['id'], 'email' => $email, 'password' => $password];
    }

    /** @return array{totpCode: string, recoveryCode: string} */
    public function enableTotp(string $accountId): array
    {
        $container = self::getTestContainer();
        $db = $container->get(ConnectionInterface::class);
        $secret = random_bytes(20);
        $encrypted = $container->get(TotpSecretCipher::class)->encrypt($secret);
        $counter = intdiv(time(), 30);
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_totp_factors (
                account_id, label, secret_encrypted, algorithm, digits, period_seconds,
                verified_at, secret_key_version
            )
            VALUES (
                CAST(:account_id AS uuid), 'API TOTP fixture', decode(:secret, 'hex'),
                'sha1', 6, 30, now(), :key_version
            )
            SQL,
            [
                ':account_id' => $accountId,
                ':secret' => $encrypted->ciphertextHex,
                ':key_version' => $encrypted->keyVersion,
            ],
        )->execute();
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 0x1_0000_0000), $counter % 0x1_0000_0000), $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $totpCode = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
        $recovery = new RecoveryCodeService();
        $recoveryCode = $recovery->generate(1)[0];
        $db->createCommand(
            "INSERT INTO core.auth_recovery_codes (account_id, code_hash) VALUES (CAST(:account_id AS uuid), decode(:hash, 'hex'))",
            [':account_id' => $accountId, ':hash' => $recovery->hashHex($recoveryCode)],
        )->execute();

        return ['totpCode' => $totpCode, 'recoveryCode' => $recoveryCode];
    }

    public function sessionHasMfa(string $sessionId): bool
    {
        $value = self::getDb()->createCommand(
            'SELECT mfa_verified_at IS NOT NULL FROM core.auth_sessions WHERE id = CAST(:id AS uuid)',
            [':id' => $sessionId],
        )->queryScalar();
        return $value === true || $value === 't' || $value === 1;
    }

    public function storedTotpEqualsPlaintext(string $accountId, string $plaintext): bool
    {
        $value = self::getDb()->createCommand(
            <<<'SQL'
            SELECT secret_encrypted = decode(:plaintext_hex, 'hex')
            FROM core.auth_totp_factors
            WHERE account_id = CAST(:account_id AS uuid) AND revoked_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
            SQL,
            [':account_id' => $accountId, ':plaintext_hex' => bin2hex($plaintext)],
        )->queryScalar();
        return $value === true || $value === 't' || $value === 1;
    }

    /** @return array{activeFactors: int, availableCodes: int, activeSessions: int} */
    public function mfaStorageState(string $accountId): array
    {
        $row = self::getDb()->createCommand(
            <<<'SQL'
            SELECT
                (SELECT count(*) FROM core.auth_totp_factors WHERE account_id = CAST(:id AS uuid) AND verified_at IS NOT NULL AND revoked_at IS NULL) AS active_factors,
                (SELECT count(*) FROM core.auth_recovery_codes WHERE account_id = CAST(:id AS uuid) AND used_at IS NULL AND revoked_at IS NULL) AS available_codes,
                (SELECT count(*) FROM core.auth_sessions WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL) AS active_sessions
            SQL,
            [':id' => $accountId],
        )->queryOne();
        if (!is_array($row)) {
            throw new \RuntimeException('MFA storage state could not be read.');
        }
        return [
            'activeFactors' => (int) $row['active_factors'],
            'availableCodes' => (int) $row['available_codes'],
            'activeSessions' => (int) $row['active_sessions'],
        ];
    }

    /** @return array{accountId: string, email: string} */
    public function haveInvitableHumanAccount(?string $locale = null): array
    {
        $db = self::getDb();
        $suffix = bin2hex(random_bytes(8));
        $email = 'invite-' . $suffix . '@example.test';
        $personId = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.persons (given_name, family_name)
            VALUES ('Invite', :family_name)
            RETURNING id::text
            SQL,
            [':family_name' => 'Fixture ' . $suffix],
        )->queryScalar();
        $accountId = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.accounts (person_id, account_type, status, label, locale)
            VALUES (CAST(:person_id AS uuid), 'human', 'pending_verification', :label, :locale)
            RETURNING id::text
            SQL,
            [
                ':person_id' => $personId,
                ':label' => 'API invitation fixture ' . $suffix,
                ':locale' => $locale,
            ],
        )->queryScalar();
        if (!is_string($accountId)) {
            throw new \RuntimeException('Invitation fixture account could not be created.');
        }

        return ['accountId' => $accountId, 'email' => $email];
    }

    public function grabAuthActionToken(string $email): string
    {
        $db = self::getDb();
        $payload = $db->createCommand(
            <<<'SQL'
            SELECT payload::text
            FROM core.outbox_events
            WHERE producer_module = 'core.auth'
              AND payload #>> '{recipient,address}' = :email
            ORDER BY id DESC
            LIMIT 1
            SQL,
            [':email' => $email],
        )->queryScalar();
        if (!is_string($payload)) {
            throw new \RuntimeException('Authentication email event was not queued.');
        }
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $url = $decoded['variables']['actionUrl'] ?? null;
        if (!is_string($url)) {
            throw new \RuntimeException('Authentication action URL is missing.');
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        if (!is_string($token)) {
            throw new \RuntimeException('Authentication action token is missing.');
        }

        return $token;
    }

    public function grabLatestAuthEmailLocale(string $email): string
    {
        $locale = self::getDb()->createCommand(
            <<<'SQL'
            SELECT payload #>> '{locale}'
            FROM core.outbox_events
            WHERE producer_module = 'core.auth'
              AND payload #>> '{recipient,address}' = :email
            ORDER BY id DESC
            LIMIT 1
            SQL,
            [':email' => $email],
        )->queryScalar();
        if (!is_string($locale)) {
            throw new \RuntimeException('Authentication email locale was not queued.');
        }

        return $locale;
    }

    public function invalidateAuthentication(string $accountId, string $mode): void
    {
        $db = self::getDb();
        $sql = match ($mode) {
            'stamp' => 'UPDATE core.accounts SET security_stamp = uuidv4() WHERE id = CAST(:id AS uuid)',
            'access_expired' => "UPDATE core.auth_sessions SET access_expires_at = now() - interval '1 second' WHERE account_id = CAST(:id AS uuid)",
            'absolute_expired' => "UPDATE core.auth_sessions SET created_at = now() - interval '2 hours', access_expires_at = now() - interval '1 hour', expires_at = now() - interval '1 hour' WHERE account_id = CAST(:id AS uuid)",
            'revoked' => "UPDATE core.auth_sessions SET revoked_at = now(), revoke_reason = 'test' WHERE account_id = CAST(:id AS uuid)",
            default => throw new \InvalidArgumentException('Unsupported authentication invalidation mode.'),
        };
        $db->createCommand($sql, [':id' => $accountId])->execute();
    }

    public function cleanupCoreTestFixtures(): void
    {
        $this->resetClientState();
        $db = self::getDb();
        $db->transaction(static function (ConnectionInterface $db): void {
            $db->createCommand('DELETE FROM core.auth_challenges')->execute();
            $db->createCommand('DELETE FROM core.outbox_events')->execute();
            $db->createCommand('DELETE FROM core.inbox_events')->execute();
            $db->createCommand('DELETE FROM core.audit_events')->execute();
            $db->createCommand('DELETE FROM core.accounts')->execute();
            $db->createCommand('DELETE FROM core.persons')->execute();
            $db->createCommand('DELETE FROM core.module_settings')->execute();
            $db->createCommand('DELETE FROM core.system_settings')->execute();
            $db->createCommand('DELETE FROM core.feature_flags')->execute();
            $db->createCommand('DELETE FROM core.modules')->execute();
            $db->createCommand('DELETE FROM core.role_permissions WHERE role_id NOT IN (SELECT id FROM core.roles WHERE is_system = true)')->execute();
            $db->createCommand('DELETE FROM core.roles WHERE is_system = false')->execute();
        });
        self::flushTestRedis();
    }

    /**
     * @param list<string> $permissions
     * @return array{accountId: string, token: string}
     */
    private function authenticateConfigurationFixture(?string $roleCode, array $permissions): array
    {
        $db = self::getDb();
        $suffix = bin2hex(random_bytes(6));
        $account = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.accounts (account_type, status, label)
            VALUES ('service', 'active', :label)
            RETURNING id::text AS id, security_stamp::text AS security_stamp
            SQL,
            [':label' => 'API authorization fixture ' . $suffix],
        )->queryOne();
        if (!is_array($account)) {
            throw new \RuntimeException('Authentication fixture account could not be created.');
        }

        $assignedRoleCode = $roleCode;
        if ($assignedRoleCode === null) {
            $assignedRoleCode = 'test.configuration.' . $suffix;
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.roles (code, name)
                VALUES (:code, 'Configuration authorization fixture')
                SQL,
                [':code' => $assignedRoleCode],
            )->execute();
            foreach ($permissions as $permission) {
                $db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.role_permissions (role_id, permission_id)
                    SELECT role.id, permission.id
                    FROM core.roles role
                    CROSS JOIN core.permissions permission
                    WHERE role.code = :role_code AND permission.code = :permission_code
                    SQL,
                    [':role_code' => $assignedRoleCode, ':permission_code' => $permission],
                )->execute();
            }
        }

        $assigned = $db->createCommand(
            <<<'SQL'
            INSERT INTO core.account_roles (account_id, role_id)
            SELECT CAST(:account_id AS uuid), id FROM core.roles WHERE code = :role_code
            SQL,
            [':account_id' => $account['id'], ':role_code' => $assignedRoleCode],
        )->execute();
        if ($assigned !== 1) {
            throw new \RuntimeException('Authentication fixture role could not be assigned.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_sessions (
                account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at
            )
            VALUES (
                CAST(:account_id AS uuid), decode(:token_hash, 'hex'), NULL,
                CAST(:security_stamp AS uuid), 'test', 'api-test',
                now() + interval '15 minutes', now() + interval '1 hour'
            )
            SQL,
            [
                ':account_id' => $account['id'],
                ':security_stamp' => $account['security_stamp'],
                ':token_hash' => hash('sha256', $token),
            ],
        )->execute();
        $this->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return ['accountId' => (string) $account['id'], 'token' => $token];
    }

    public function amAuthenticatedAccount(string $accountId): string
    {
        $db = self::getDb();
        $account = $db->createCommand(
            'SELECT security_stamp FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $accountId],
        )->queryOne();
        if (!is_array($account)) {
            throw new \RuntimeException('Account not found.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_sessions (
                account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at
            )
            VALUES (
                CAST(:account_id AS uuid), decode(:token_hash, 'hex'), NULL,
                CAST(:security_stamp AS uuid), 'test', 'api-test',
                now() + interval '15 minutes', now() + interval '1 hour'
            )
            SQL,
            [
                ':account_id' => $accountId,
                ':security_stamp' => $account['security_stamp'],
                ':token_hash' => hash('sha256', $token),
            ],
        )->execute();
        $this->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return $token;
    }
}
