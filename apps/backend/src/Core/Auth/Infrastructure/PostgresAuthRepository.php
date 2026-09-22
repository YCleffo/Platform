<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\AuthChallenge;
use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\AuthIdentifier;
use App\Core\Auth\Application\AuthProvider;
use App\Core\Auth\Application\AuthProviderRepository;
use App\Core\Auth\Application\AuthSession;
use App\Core\Auth\Application\CreateAuthChallengeCommand;
use App\Core\Auth\Application\CreateSessionCommand;
use App\Core\Auth\Application\CreateTotpFactorCommand;
use App\Core\Auth\Application\ExternalIdentity;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\IdentifierRepository;
use App\Core\Auth\Application\Passkey;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasswordCredential;
use App\Core\Auth\Application\PasswordCredentialRepository;
use App\Core\Auth\Application\RecoveryCode;
use App\Core\Auth\Application\RecoveryCodeRepository;
use App\Core\Auth\Application\RotateSessionTokensCommand;
use App\Core\Auth\Application\SessionRepository;
use App\Core\Auth\Application\TouchSessionCommand;
use App\Core\Auth\Application\TotpFactor;
use App\Core\Auth\Application\TotpFactorRepository;
use App\Core\Shared\Application\ConflictException;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class PostgresAuthRepository implements
    IdentifierRepository,
    PasswordCredentialRepository,
    AuthProviderRepository,
    ExternalIdentityRepository,
    PasskeyRepository,
    TotpFactorRepository,
    RecoveryCodeRepository,
    SessionRepository,
    AuthChallengeRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findByNormalized(string $type, string $normalizedValue): ?AuthIdentifier
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                identifier_type::text AS identifier_type,
                value,
                normalized_value,
                is_primary,
                can_login,
                can_recover,
                to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_identifiers
            WHERE identifier_type = CAST(:type AS core.auth_identifier_type)
                AND normalized_value = :normalized_value
            SQL,
            [':type' => $type, ':normalized_value' => $normalizedValue],
        )->queryOne();

        return is_array($row) ? self::identifier($row) : null;
    }

    public function findRecoveryEmailByAccount(string $accountId): ?AuthIdentifier
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                identifier_type::text AS identifier_type,
                value,
                normalized_value,
                is_primary,
                can_login,
                can_recover,
                to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_identifiers
            WHERE account_id = CAST(:account_id AS uuid)
              AND identifier_type = 'email'
              AND verified_at IS NOT NULL
              AND can_recover = true
            ORDER BY is_primary DESC, verified_at, id
            LIMIT 1
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::identifier($row) : null;
    }

    public function findDisplayIdentifierByAccount(string $accountId): ?AuthIdentifier
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                identifier_type::text AS identifier_type,
                value,
                normalized_value,
                is_primary,
                can_login,
                can_recover,
                to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_identifiers
            WHERE account_id = CAST(:account_id AS uuid)
              AND verified_at IS NOT NULL
            ORDER BY is_primary DESC, can_login DESC, verified_at, id
            LIMIT 1
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::identifier($row) : null;
    }

    public function findByAccountId(string $accountId): ?PasswordCredential
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                password_hash,
                password_algorithm,
                must_change_password,
                to_char(password_changed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS password_changed_at,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_password_credentials
            WHERE account_id = CAST(:account_id AS uuid)
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::password($row) : null;
    }

    public function saveHash(
        string $accountId,
        string $passwordHash,
        string $algorithm,
        bool $mustChangePassword = false,
    ): void {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_password_credentials (
                account_id,
                password_hash,
                password_algorithm,
                must_change_password
            )
            VALUES (
                CAST(:account_id AS uuid),
                :password_hash,
                :password_algorithm,
                :must_change_password
            )
            ON CONFLICT (account_id) DO UPDATE
            SET
                password_hash = EXCLUDED.password_hash,
                password_algorithm = EXCLUDED.password_algorithm,
                must_change_password = EXCLUDED.must_change_password,
                password_changed_at = now(),
                updated_at = now()
            SQL,
            [
                ':account_id' => $accountId,
                ':password_hash' => $passwordHash,
                ':password_algorithm' => $algorithm,
                ':must_change_password' => $mustChangePassword,
            ],
        )->execute();
    }

    public function findByCode(string $code): ?AuthProvider
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                code,
                protocol,
                display_name,
                enabled,
                allow_login,
                allow_registration,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_providers
            WHERE code = :code
            SQL,
            [':code' => $code],
        )->queryOne();

        return is_array($row) ? self::provider($row) : null;
    }

    public function findEnabled(): array
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                code,
                protocol,
                display_name,
                enabled,
                allow_login,
                allow_registration,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
            FROM core.auth_providers
            WHERE enabled = true
            ORDER BY code
            SQL,
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::provider(...), $rows);
    }

    public function findByProviderSubject(string $providerId, string $subject): ?ExternalIdentity
    {
        $row = $this->db->createCommand(
            self::EXTERNAL_IDENTITY_COLUMNS . <<<'SQL'

            FROM core.auth_external_identities identity
            JOIN core.auth_providers provider ON provider.id = identity.provider_id
            WHERE identity.provider_id = CAST(:provider_id AS uuid)
                AND identity.provider_subject = :subject
            SQL,
            [':provider_id' => $providerId, ':subject' => $subject],
        )->queryOne();

        return is_array($row) ? self::externalIdentity($row) : null;
    }

    public function findByAccountProvider(string $accountId, string $providerId): ?ExternalIdentity
    {
        $row = $this->db->createCommand(
            self::EXTERNAL_IDENTITY_COLUMNS . <<<'SQL'

            FROM core.auth_external_identities identity
            JOIN core.auth_providers provider ON provider.id = identity.provider_id
            WHERE identity.account_id = CAST(:account_id AS uuid)
              AND identity.provider_id = CAST(:provider_id AS uuid)
            SQL,
            [':account_id' => $accountId, ':provider_id' => $providerId],
        )->queryOne();

        return is_array($row) ? self::externalIdentity($row) : null;
    }

    public function findByAccount(string $accountId): array
    {
        $rows = $this->db->createCommand(
            self::EXTERNAL_IDENTITY_COLUMNS . <<<'SQL'

            FROM core.auth_external_identities identity
            JOIN core.auth_providers provider ON provider.id = identity.provider_id
            WHERE identity.account_id = CAST(:account_id AS uuid)
            ORDER BY provider.code, identity.id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::externalIdentity(...), $rows);
    }

    public function link(string $accountId, string $providerId, ExternalIdentityProfile $profile): ExternalIdentity
    {
        try {
            $id = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_external_identities (
                    account_id, provider_id, provider_subject, email, email_verified,
                    display_name, avatar_url, claims
                )
                VALUES (
                    CAST(:account_id AS uuid), CAST(:provider_id AS uuid), :provider_subject,
                    :email, :email_verified, :display_name, :avatar_url, CAST(:claims AS jsonb)
                )
                RETURNING id::text
                SQL,
                [
                    ':account_id' => $accountId,
                    ':provider_id' => $providerId,
                    ':provider_subject' => $profile->subject,
                    ':email' => $profile->email,
                    ':email_verified' => $profile->emailVerified,
                    ':display_name' => $profile->displayName,
                    ':avatar_url' => $profile->avatarUrl,
                    ':claims' => json_encode($profile->claims, JSON_THROW_ON_ERROR),
                ],
            )->queryScalar();
        } catch (IntegrityException $exception) {
            throw new ConflictException('External identity is already linked.', previous: $exception);
        }
        if (!is_string($id)) {
            throw new RuntimeException('PostgreSQL did not return the linked external identity.');
        }

        return $this->findByAccountProvider($accountId, $providerId)
            ?? throw new RuntimeException('Linked external identity could not be read.');
    }

    public function updateSnapshotAndLogin(string $id, ExternalIdentityProfile $profile): ?ExternalIdentity
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_external_identities
            SET
                email = :email,
                email_verified = :email_verified,
                display_name = :display_name,
                avatar_url = :avatar_url,
                claims = CAST(:claims AS jsonb),
                last_login_at = now()
            WHERE id = CAST(:id AS uuid)
              AND provider_subject = :provider_subject
            RETURNING account_id::text AS account_id, provider_id::text AS provider_id
            SQL,
            [
                ':id' => $id,
                ':provider_subject' => $profile->subject,
                ':email' => $profile->email,
                ':email_verified' => $profile->emailVerified,
                ':display_name' => $profile->displayName,
                ':avatar_url' => $profile->avatarUrl,
                ':claims' => json_encode($profile->claims, JSON_THROW_ON_ERROR),
            ],
        )->queryOne();
        if (!is_array($row)) {
            return null;
        }

        return $this->findByAccountProvider((string) $row['account_id'], (string) $row['provider_id']);
    }

    public function unlink(string $accountId, string $providerId): ?ExternalIdentity
    {
        $identity = $this->findByAccountProvider($accountId, $providerId);
        if ($identity === null) {
            return null;
        }
        $deleted = $this->db->createCommand(
            <<<'SQL'
            DELETE FROM core.auth_external_identities
            WHERE id = CAST(:id AS uuid) AND account_id = CAST(:account_id AS uuid)
            SQL,
            [':id' => $identity->id, ':account_id' => $accountId],
        )->execute();

        return $deleted === 1 ? $identity : null;
    }

    public function countByAccount(string $accountId): int
    {
        return (int) $this->db->createCommand(
            'SELECT count(*) FROM core.auth_external_identities WHERE account_id = CAST(:account_id AS uuid)',
            [':account_id' => $accountId],
        )->queryScalar();
    }

    public function findById(string $id): ?Passkey
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                encode(credential_id, 'hex') AS credential_id_hex,
                encode(public_key, 'hex') AS public_key_hex,
                sign_count,
                aaguid::text AS aaguid,
                to_json(transports)::text AS transports,
                label,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                backup_eligible,
                backup_state
            FROM core.auth_passkeys
            WHERE id = CAST(:id AS uuid)
            SQL,
            [':id' => $id],
        )->queryOne();

        return is_array($row) ? self::passkey($row) : null;
    }

    public function findByCredentialIdHex(string $credentialIdHex): ?Passkey
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                encode(credential_id, 'hex') AS credential_id_hex,
                encode(public_key, 'hex') AS public_key_hex,
                sign_count,
                aaguid::text AS aaguid,
                to_json(transports)::text AS transports,
                label,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                backup_eligible,
                backup_state
            FROM core.auth_passkeys
            WHERE credential_id = decode(:credential_id_hex, 'hex')
            SQL,
            [':credential_id_hex' => $credentialIdHex],
        )->queryOne();

        return is_array($row) ? self::passkey($row) : null;
    }

    public function findActivePasskeysByAccountId(string $accountId): array
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                encode(credential_id, 'hex') AS credential_id_hex,
                encode(public_key, 'hex') AS public_key_hex,
                sign_count,
                aaguid::text AS aaguid,
                to_json(transports)::text AS transports,
                label,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                backup_eligible,
                backup_state
            FROM core.auth_passkeys
            WHERE account_id = CAST(:account_id AS uuid) AND revoked_at IS NULL
            ORDER BY created_at, id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::passkey(...), $rows);
    }

    public function findAllByAccountId(string $accountId): array
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                encode(credential_id, 'hex') AS credential_id_hex,
                encode(public_key, 'hex') AS public_key_hex,
                sign_count,
                aaguid::text AS aaguid,
                to_json(transports)::text AS transports,
                label,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                backup_eligible,
                backup_state
            FROM core.auth_passkeys
            WHERE account_id = CAST(:account_id AS uuid)
            ORDER BY created_at, id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::passkey(...), $rows);
    }

    public function create(
        string $accountId,
        string $credentialIdHex,
        string $publicKeyHex,
        int $signCount,
        ?string $aaguid,
        array $transports,
        ?string $label,
        bool $backupEligible,
        bool $backupState,
    ): Passkey {
        $validAaguid = null;
        if ($aaguid !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $aaguid) === 1) {
            $validAaguid = $aaguid;
        }

        try {
            $row = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_passkeys (
                    account_id,
                    credential_id,
                    public_key,
                    sign_count,
                    aaguid,
                    transports,
                    label,
                    backup_eligible,
                    backup_state
                )
                VALUES (
                    CAST(:account_id AS uuid),
                    decode(:credential_id_hex, 'hex'),
                    decode(:public_key_hex, 'hex'),
                    :sign_count,
                    CASE WHEN :aaguid IS NULL THEN NULL ELSE CAST(:aaguid AS uuid) END,
                    ARRAY(SELECT json_array_elements_text(CAST(:transports_json AS json))),
                    :label,
                    :backup_eligible,
                    :backup_state
                )
                RETURNING
                    id::text AS id,
                    account_id::text AS account_id,
                    encode(credential_id, 'hex') AS credential_id_hex,
                    encode(public_key, 'hex') AS public_key_hex,
                    sign_count,
                    aaguid::text AS aaguid,
                    to_json(transports)::text AS transports,
                    label,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                    to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                    to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                    backup_eligible,
                    backup_state
                SQL,
                [
                    ':account_id' => $accountId,
                    ':credential_id_hex' => $credentialIdHex,
                    ':public_key_hex' => $publicKeyHex,
                    ':sign_count' => $signCount,
                    ':aaguid' => $validAaguid,
                    ':transports_json' => json_encode(array_values($transports), JSON_THROW_ON_ERROR),
                    ':label' => $label,
                    ':backup_eligible' => $backupEligible ? 't' : 'f',
                    ':backup_state' => $backupState ? 't' : 'f',
                ],
            )->queryOne();
        } catch (IntegrityException $exception) {
            throw new ConflictException('Passkey credential already registered.', previous: $exception);
        }

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the stored passkey.');
        }

        return self::passkey($row);
    }

    public function updateLabel(string $id, string $accountId, ?string $label): ?Passkey
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_passkeys
            SET label = :label
            WHERE id = CAST(:id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
            RETURNING
                id::text AS id,
                account_id::text AS account_id,
                encode(credential_id, 'hex') AS credential_id_hex,
                encode(public_key, 'hex') AS public_key_hex,
                sign_count,
                aaguid::text AS aaguid,
                to_json(transports)::text AS transports,
                label,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                backup_eligible,
                backup_state
            SQL,
            [
                ':id' => $id,
                ':account_id' => $accountId,
                ':label' => $label,
            ],
        )->queryOne();

        return is_array($row) ? self::passkey($row) : null;
    }

    public function recordUsage(string $id, int $signCount, bool $backupState): void
    {
        $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_passkeys
            SET
                sign_count = :sign_count,
                backup_state = :backup_state,
                last_used_at = now()
            WHERE id = CAST(:id AS uuid)
            SQL,
            [
                ':id' => $id,
                ':sign_count' => $signCount,
                ':backup_state' => $backupState ? 't' : 'f',
            ],
        )->execute();
    }

    public function revoke(string $id, string $accountId): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_passkeys
            SET revoked_at = now()
            WHERE id = CAST(:id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
            SQL,
            [
                ':id' => $id,
                ':account_id' => $accountId,
            ],
        )->execute() === 1;
    }

    public function findActiveByAccountId(string $accountId): array
    {
        $rows = $this->db->createCommand(
            self::TOTP_COLUMNS . <<<'SQL'

            FROM core.auth_totp_factors
            WHERE account_id = CAST(:account_id AS uuid)
                AND revoked_at IS NULL
                AND verified_at IS NOT NULL
            ORDER BY created_at, id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::totp(...), $rows);
    }

    public function findActive(string $accountId): ?TotpFactor
    {
        $row = $this->db->createCommand(
            self::TOTP_COLUMNS . <<<'SQL'

            FROM core.auth_totp_factors
            WHERE account_id = CAST(:account_id AS uuid)
              AND verified_at IS NOT NULL
              AND revoked_at IS NULL
            ORDER BY verified_at DESC, id DESC
            LIMIT 1
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::totp($row) : null;
    }

    public function findPending(string $id, string $accountId): ?TotpFactor
    {
        $row = $this->db->createCommand(
            self::TOTP_COLUMNS . <<<'SQL'

            FROM core.auth_totp_factors
            WHERE id = CAST(:id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND verified_at IS NULL
              AND revoked_at IS NULL
              AND setup_expires_at > now()
            SQL,
            [':id' => $id, ':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::totp($row) : null;
    }

    public function createPending(CreateTotpFactorCommand $command): TotpFactor
    {
        $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_totp_factors
            SET revoked_at = now()
            WHERE account_id = CAST(:account_id AS uuid)
              AND verified_at IS NULL
              AND revoked_at IS NULL
            SQL,
            [':account_id' => $command->accountId],
        )->execute();
        try {
            $id = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_totp_factors (
                    account_id, label, secret_encrypted, algorithm, digits,
                    period_seconds, secret_key_version, setup_expires_at
                )
                VALUES (
                    CAST(:account_id AS uuid), :label, decode(:secret_encrypted_hex, 'hex'),
                    CAST(:algorithm AS core.totp_algorithm), :digits, :period_seconds,
                    :secret_key_version, CAST(:setup_expires_at AS timestamptz)
                )
                RETURNING id::text
                SQL,
                [
                    ':account_id' => $command->accountId,
                    ':label' => $command->label,
                    ':secret_encrypted_hex' => $command->secretEncryptedHex,
                    ':algorithm' => $command->algorithm,
                    ':digits' => $command->digits,
                    ':period_seconds' => $command->periodSeconds,
                    ':secret_key_version' => $command->secretKeyVersion,
                    ':setup_expires_at' => $command->setupExpiresAt,
                ],
            )->queryScalar();
        } catch (IntegrityException $exception) {
            throw new ConflictException('TOTP setup persistence conflict.', previous: $exception);
        }
        if (!is_string($id)) {
            throw new RuntimeException('PostgreSQL did not return the pending TOTP factor.');
        }

        return $this->findPending($id, $command->accountId)
            ?? throw new RuntimeException('Pending TOTP factor could not be read.');
    }

    public function activate(string $id, string $accountId, int $counter): ?TotpFactor
    {
        try {
            $updated = $this->db->createCommand(
                <<<'SQL'
                UPDATE core.auth_totp_factors
                SET
                    verified_at = now(),
                    last_used_at = now(),
                    last_used_counter = :counter,
                    setup_expires_at = NULL
                WHERE id = CAST(:id AS uuid)
                  AND account_id = CAST(:account_id AS uuid)
                  AND verified_at IS NULL
                  AND revoked_at IS NULL
                  AND setup_expires_at > now()
                SQL,
                [':id' => $id, ':account_id' => $accountId, ':counter' => $counter],
            )->execute();
        } catch (IntegrityException $exception) {
            throw new ConflictException('An active TOTP factor already exists.', previous: $exception);
        }

        return $updated === 1 ? $this->findActive($accountId) : null;
    }

    public function acceptCounter(string $id, int $counter): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_totp_factors
            SET last_used_at = now(), last_used_counter = :counter
            WHERE id = CAST(:id AS uuid)
              AND verified_at IS NOT NULL
              AND revoked_at IS NULL
              AND (last_used_counter IS NULL OR last_used_counter < :previous_counter)
            SQL,
            [':id' => $id, ':counter' => $counter, ':previous_counter' => $counter],
        )->execute() === 1;
    }

    public function revokeAllFactors(string $accountId): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_totp_factors
            SET revoked_at = now()
            WHERE account_id = CAST(:account_id AS uuid) AND revoked_at IS NULL
            SQL,
            [':account_id' => $accountId],
        )->execute();
    }

    public function findUnusedByHashHex(string $codeHashHex): ?RecoveryCode
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                id::text AS id,
                account_id::text AS account_id,
                encode(code_hash, 'hex') AS code_hash_hex,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at
            FROM core.auth_recovery_codes
            WHERE code_hash = decode(:code_hash_hex, 'hex')
                AND used_at IS NULL
                AND revoked_at IS NULL
            SQL,
            [':code_hash_hex' => $codeHashHex],
        )->queryOne();

        return is_array($row) ? self::recoveryCode($row) : null;
    }

    public function store(string $accountId, array $codeHashHexes): void
    {
        foreach ($codeHashHexes as $codeHashHex) {
            try {
                $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.auth_recovery_codes (account_id, code_hash)
                    VALUES (CAST(:account_id AS uuid), decode(:code_hash_hex, 'hex'))
                    SQL,
                    [':account_id' => $accountId, ':code_hash_hex' => $codeHashHex],
                )->execute();
            } catch (IntegrityException $exception) {
                throw new ConflictException('Recovery code persistence conflict.', previous: $exception);
            }
        }
    }

    public function consumeCode(string $accountId, string $codeHashHex): ?RecoveryCode
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_recovery_codes
            SET used_at = now()
            WHERE account_id = CAST(:account_id AS uuid)
              AND code_hash = decode(:code_hash_hex, 'hex')
              AND used_at IS NULL
              AND revoked_at IS NULL
            RETURNING
                id::text AS id,
                account_id::text AS account_id,
                encode(code_hash, 'hex') AS code_hash_hex,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS used_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at
            SQL,
            [':account_id' => $accountId, ':code_hash_hex' => $codeHashHex],
        )->queryOne();

        return is_array($row) ? self::recoveryCode($row) : null;
    }

    public function revokeAllCodes(string $accountId): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_recovery_codes
            SET revoked_at = now()
            WHERE account_id = CAST(:account_id AS uuid)
              AND used_at IS NULL
              AND revoked_at IS NULL
            SQL,
            [':account_id' => $accountId],
        )->execute();
    }

    public function countAvailable(string $accountId): int
    {
        return (int) $this->db->createCommand(
            <<<'SQL'
            SELECT count(*)
            FROM core.auth_recovery_codes
            WHERE account_id = CAST(:account_id AS uuid)
              AND used_at IS NULL
              AND revoked_at IS NULL
            SQL,
            [':account_id' => $accountId],
        )->queryScalar();
    }

    public function storeSession(CreateSessionCommand $command): AuthSession
    {
        try {
            $row = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_sessions (
                    account_id,
                    session_token_hash,
                    refresh_token_hash,
                    security_stamp,
                    authentication_method,
                    mfa_verified_at,
                    client_type,
                    device_name,
                    ip_address,
                    user_agent,
                    browser_name,
                    browser_version,
                    os_name,
                    os_version,
                    device_type,
                    device_model,
                    client_locale,
                    client_timezone,
                    client_instance_id,
                    device_fingerprint_hash,
                    geo_country_code,
                    geo_region,
                    geo_city,
                    geo_timezone,
                    access_expires_at,
                    expires_at
                )
                VALUES (
                    CAST(:account_id AS uuid),
                    decode(:session_token_hash_hex, 'hex'),
                    decode(:refresh_token_hash_hex, 'hex'),
                    CAST(:security_stamp AS uuid),
                    :authentication_method,
                    CAST(:mfa_verified_at AS timestamptz),
                    :client_type,
                    :device_name,
                    CAST(:ip_address AS inet),
                    :user_agent,
                    :browser_name,
                    :browser_version,
                    :os_name,
                    :os_version,
                    :device_type,
                    :device_model,
                    :client_locale,
                    :client_timezone,
                    CAST(:client_instance_id AS uuid),
                    decode(:device_fingerprint_hash_hex, 'hex'),
                    :geo_country_code,
                    :geo_region,
                    :geo_city,
                    :geo_timezone,
                    CAST(:access_expires_at AS timestamptz),
                    CAST(:expires_at AS timestamptz)
                )
                RETURNING
                    id::text AS id,
                    account_id::text AS account_id,
                    encode(session_token_hash, 'hex') AS session_token_hash_hex,
                    encode(refresh_token_hash, 'hex') AS refresh_token_hash_hex,
                    security_stamp::text AS security_stamp,
                    authentication_method,
                    to_char(mfa_verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS mfa_verified_at,
                    client_type,
                    device_name,
                    host(ip_address) AS ip_address,
                    user_agent,
                    browser_name,
                    browser_version,
                    os_name,
                    os_version,
                    device_type,
                    device_model,
                    client_locale,
                    client_timezone,
                    client_instance_id::text AS client_instance_id,
                    encode(device_fingerprint_hash, 'hex') AS device_fingerprint_hash_hex,
                    geo_country_code,
                    geo_region,
                    geo_city,
                    geo_timezone,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                    to_char(last_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_seen_at,
                    to_char(access_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS access_expires_at,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expires_at,
                    to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                    revoke_reason
                SQL,
                [
                    ':account_id' => $command->accountId,
                    ':session_token_hash_hex' => $command->sessionTokenHashHex,
                    ':refresh_token_hash_hex' => $command->refreshTokenHashHex,
                    ':security_stamp' => $command->securityStamp,
                    ':authentication_method' => $command->authenticationMethod,
                    ':mfa_verified_at' => $command->mfaVerifiedAt,
                    ':client_type' => $command->clientType,
                    ':device_name' => $command->deviceName,
                    ':ip_address' => $command->ipAddress,
                    ':user_agent' => $command->userAgent,
                    ':browser_name' => $command->browserName,
                    ':browser_version' => $command->browserVersion,
                    ':os_name' => $command->osName,
                    ':os_version' => $command->osVersion,
                    ':device_type' => $command->deviceType,
                    ':device_model' => $command->deviceModel,
                    ':client_locale' => $command->clientLocale,
                    ':client_timezone' => $command->clientTimezone,
                    ':client_instance_id' => $command->clientInstanceId,
                    ':device_fingerprint_hash_hex' => $command->deviceFingerprintHashHex,
                    ':geo_country_code' => $command->geoCountryCode,
                    ':geo_region' => $command->geoRegion,
                    ':geo_city' => $command->geoCity,
                    ':geo_timezone' => $command->geoTimezone,
                    ':access_expires_at' => $command->accessExpiresAt,
                    ':expires_at' => $command->expiresAt,
                ],
            )->queryOne();
        } catch (IntegrityException $exception) {
            throw new ConflictException('Session persistence conflict.', previous: $exception);
        }

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the stored session.');
        }
        return self::session($row);
    }

    public function findByTokenHashHex(string $tokenHashHex): ?AuthSession
    {
        $row = $this->db->createCommand(
            self::SESSION_COLUMNS . <<<'SQL'

            FROM core.auth_sessions
            WHERE session_token_hash = decode(:token_hash_hex, 'hex')
            SQL,
            [':token_hash_hex' => $tokenHashHex],
        )->queryOne();

        return is_array($row) ? self::session($row) : null;
    }

    public function findByRefreshTokenHashHex(string $tokenHashHex): ?AuthSession
    {
        $row = $this->db->createCommand(
            self::SESSION_COLUMNS . <<<'SQL'

            FROM core.auth_sessions
            WHERE refresh_token_hash = decode(:token_hash_hex, 'hex')
            SQL,
            [':token_hash_hex' => $tokenHashHex],
        )->queryOne();

        return is_array($row) ? self::session($row) : null;
    }

    public function rotateTokens(RotateSessionTokensCommand $command): ?AuthSession
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            WITH rotated AS (
                UPDATE core.auth_sessions
                SET
                    session_token_hash = decode(:new_access_token_hash_hex, 'hex'),
                    refresh_token_hash = decode(:new_refresh_token_hash_hex, 'hex'),
                    access_expires_at = CAST(:access_expires_at AS timestamptz),
                    last_seen_at = now()
                WHERE refresh_token_hash = decode(:current_refresh_token_hash_hex, 'hex')
                  AND revoked_at IS NULL
                  AND expires_at > now()
                  AND security_stamp = CAST(:expected_security_stamp AS uuid)
                  AND EXISTS (
                      SELECT 1
                      FROM core.accounts a
                      WHERE a.id = core.auth_sessions.account_id
                        AND a.security_stamp = CAST(:expected_security_stamp AS uuid)
                        AND a.status = 'active'
                        AND a.disabled_at IS NULL
                        AND a.archived_at IS NULL
                        AND (a.locked_until IS NULL OR a.locked_until <= now())
                  )
                RETURNING *
            )
            SQL . self::SESSION_COLUMNS . ' FROM rotated',
            [
                ':current_refresh_token_hash_hex' => $command->currentRefreshTokenHashHex,
                ':new_access_token_hash_hex' => $command->newAccessTokenHashHex,
                ':new_refresh_token_hash_hex' => $command->newRefreshTokenHashHex,
                ':access_expires_at' => $command->accessExpiresAt,
                ':expected_security_stamp' => $command->expectedSecurityStamp,
            ],
        )->queryOne();

        return is_array($row) ? self::session($row) : null;
    }

    public function findForAccount(string $sessionId, string $accountId): ?AuthSession
    {
        $row = $this->db->createCommand(
            self::SESSION_COLUMNS . <<<'SQL'

            FROM core.auth_sessions
            WHERE id = CAST(:session_id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
            SQL,
            [':session_id' => $sessionId, ':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::session($row) : null;
    }

    public function findActiveForAccount(string $accountId): array
    {
        $rows = $this->db->createCommand(
            self::SESSION_COLUMNS . <<<'SQL'

            FROM core.auth_sessions
            WHERE account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
              AND expires_at > now()
            ORDER BY last_seen_at DESC, id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::session(...), $rows);
    }

    public function touch(TouchSessionCommand $command): ?AuthSession
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET
                last_seen_at = now(),
                ip_address = CAST(:ip_address AS inet),
                user_agent = :user_agent,
                browser_name = :browser_name,
                browser_version = :browser_version,
                os_name = :os_name,
                os_version = :os_version,
                device_type = :device_type,
                device_model = :device_model,
                client_locale = :client_locale,
                client_timezone = :client_timezone,
                client_instance_id = CAST(:client_instance_id AS uuid),
                device_fingerprint_hash = decode(:device_fingerprint_hash_hex, 'hex'),
                geo_country_code = :geo_country_code,
                geo_region = :geo_region,
                geo_city = :geo_city,
                geo_timezone = :geo_timezone
            WHERE id = CAST(:session_id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
              AND expires_at > now()
              AND (
                last_seen_at <= CAST(:touch_before AS timestamptz)
                OR ip_address IS DISTINCT FROM CAST(:ip_address_compare AS inet)
                OR user_agent IS DISTINCT FROM :user_agent_compare
                OR device_fingerprint_hash IS DISTINCT FROM decode(:fingerprint_compare, 'hex')
                OR client_instance_id IS DISTINCT FROM CAST(:client_instance_compare AS uuid)
              )
            RETURNING
                id::text AS id,
                account_id::text AS account_id,
                encode(session_token_hash, 'hex') AS session_token_hash_hex,
                encode(refresh_token_hash, 'hex') AS refresh_token_hash_hex,
                security_stamp::text AS security_stamp,
                authentication_method,
                to_char(mfa_verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS mfa_verified_at,
                client_type,
                device_name,
                host(ip_address) AS ip_address,
                user_agent,
                browser_name,
                browser_version,
                os_name,
                os_version,
                device_type,
                device_model,
                client_locale,
                client_timezone,
                client_instance_id::text AS client_instance_id,
                encode(device_fingerprint_hash, 'hex') AS device_fingerprint_hash_hex,
                geo_country_code,
                geo_region,
                geo_city,
                geo_timezone,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                to_char(last_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_seen_at,
                to_char(access_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS access_expires_at,
                to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expires_at,
                to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
                revoke_reason
            SQL,
            [
                ':session_id' => $command->sessionId,
                ':account_id' => $command->accountId,
                ':touch_before' => $command->touchBefore,
                ':ip_address' => $command->ipAddress,
                ':ip_address_compare' => $command->ipAddress,
                ':user_agent' => $command->userAgent,
                ':user_agent_compare' => $command->userAgent,
                ':browser_name' => $command->browserName,
                ':browser_version' => $command->browserVersion,
                ':os_name' => $command->osName,
                ':os_version' => $command->osVersion,
                ':device_type' => $command->deviceType,
                ':device_model' => $command->deviceModel,
                ':client_locale' => $command->clientLocale,
                ':client_timezone' => $command->clientTimezone,
                ':client_instance_id' => $command->clientInstanceId,
                ':client_instance_compare' => $command->clientInstanceId,
                ':device_fingerprint_hash_hex' => $command->deviceFingerprintHashHex,
                ':fingerprint_compare' => $command->deviceFingerprintHashHex,
                ':geo_country_code' => $command->geoCountryCode,
                ':geo_region' => $command->geoRegion,
                ':geo_city' => $command->geoCity,
                ':geo_timezone' => $command->geoTimezone,
            ],
        )->queryOne();

        return is_array($row) ? self::session($row) : null;
    }

    public function revoke(string $id, string $reason): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET revoked_at = COALESCE(revoked_at, now()), revoke_reason = :reason
            WHERE id = CAST(:id AS uuid) AND revoked_at IS NULL
            SQL,
            [':id' => $id, ':reason' => $reason],
        )->execute() === 1;
    }

    public function revokeForAccount(string $id, string $accountId, string $reason): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET revoked_at = now(), revoke_reason = :reason
            WHERE id = CAST(:id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
            SQL,
            [':id' => $id, ':account_id' => $accountId, ':reason' => $reason],
        )->execute() === 1;
    }

    public function revokeOthers(string $accountId, string $currentSessionId, string $reason): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET revoked_at = now(), revoke_reason = :reason
            WHERE account_id = CAST(:account_id AS uuid)
              AND id <> CAST(:current_session_id AS uuid)
              AND revoked_at IS NULL
            SQL,
            [
                ':account_id' => $accountId,
                ':current_session_id' => $currentSessionId,
                ':reason' => $reason,
            ],
        )->execute();
    }

    public function revokeAll(string $accountId, string $reason): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET revoked_at = now(), revoke_reason = :reason
            WHERE account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
            SQL,
            [':account_id' => $accountId, ':reason' => $reason],
        )->execute();
    }

    public function secureCurrentAndRevokeOthers(
        string $accountId,
        string $currentSessionId,
        string $securityStamp,
        string $reason,
        bool $markMfaVerified = false,
    ): int {
        $currentUpdated = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_sessions
            SET
                security_stamp = CAST(:security_stamp AS uuid),
                last_seen_at = now(),
                mfa_verified_at = CASE WHEN CAST(:mark_mfa_verified AS boolean) THEN now() ELSE mfa_verified_at END,
                authentication_method = CASE
                    WHEN CAST(:mark_mfa_verified AS boolean) AND position('+' IN authentication_method) = 0
                        THEN authentication_method || '+totp'
                    ELSE authentication_method
                END
            WHERE id = CAST(:current_session_id AS uuid)
              AND account_id = CAST(:account_id AS uuid)
              AND revoked_at IS NULL
            SQL,
            [
                ':security_stamp' => $securityStamp,
                ':current_session_id' => $currentSessionId,
                ':account_id' => $accountId,
                ':mark_mfa_verified' => $markMfaVerified,
            ],
        )->execute();
        if ($currentUpdated !== 1) {
            return 0;
        }

        return $this->revokeOthers($accountId, $currentSessionId, $reason);
    }

    public function storeChallenge(CreateAuthChallengeCommand $command): AuthChallenge
    {
        try {
            $row = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_challenges (
                    account_id,
                    challenge_type,
                    secret_hash,
                    target_normalized,
                    payload,
                    max_attempts,
                    expires_at
                )
                VALUES (
                    CAST(:account_id AS uuid),
                    :challenge_type,
                    decode(:secret_hash_hex, 'hex'),
                    :target_normalized,
                    CAST(:payload AS jsonb),
                    :max_attempts,
                    CAST(:expires_at AS timestamptz)
                )
                RETURNING
                    id::text AS id,
                    account_id::text AS account_id,
                    challenge_type,
                    encode(secret_hash, 'hex') AS secret_hash_hex,
                    target_normalized,
                    payload::text AS payload,
                    attempts,
                    max_attempts,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expires_at,
                    to_char(consumed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS consumed_at,
                    to_char(cancelled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS cancelled_at
                SQL,
                [
                    ':account_id' => $command->accountId,
                    ':challenge_type' => $command->challengeType,
                    ':secret_hash_hex' => $command->secretHashHex,
                    ':target_normalized' => $command->targetNormalized,
                    ':payload' => json_encode($command->payload, JSON_THROW_ON_ERROR),
                    ':max_attempts' => $command->maxAttempts,
                    ':expires_at' => $command->expiresAt,
                ],
            )->queryOne();
        } catch (IntegrityException $exception) {
            throw new ConflictException('Challenge persistence conflict.', previous: $exception);
        }

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the stored challenge.');
        }
        return self::challenge($row);
    }

    public function findActiveBySecretHashHex(string $secretHashHex): ?AuthChallenge
    {
        $row = $this->db->createCommand(
            self::CHALLENGE_COLUMNS . <<<'SQL'

            FROM core.auth_challenges
            WHERE secret_hash = decode(:secret_hash_hex, 'hex')
                AND consumed_at IS NULL
                AND cancelled_at IS NULL
                AND expires_at > now()
                AND attempts < max_attempts
            SQL,
            [':secret_hash_hex' => $secretHashHex],
        )->queryOne();

        return is_array($row) ? self::challenge($row) : null;
    }

    public function consume(string $id): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_challenges
            SET consumed_at = now()
            WHERE id = CAST(:id AS uuid)
                AND consumed_at IS NULL
                AND cancelled_at IS NULL
                AND expires_at > now()
                AND attempts < max_attempts
            SQL,
            [':id' => $id],
        )->execute() === 1;
    }

    public function cancel(string $id): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_challenges
            SET cancelled_at = now()
            WHERE id = CAST(:id AS uuid)
                AND consumed_at IS NULL
                AND cancelled_at IS NULL
            SQL,
            [':id' => $id],
        )->execute() === 1;
    }

    public function registerFailedAttempt(string $id): ?AuthChallenge
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            WITH challenge_attempt AS (
                UPDATE core.auth_challenges
                SET
                    attempts = attempts + 1,
                    cancelled_at = CASE
                        WHEN attempts + 1 >= max_attempts THEN now()
                        ELSE cancelled_at
                    END
                WHERE id = CAST(:id AS uuid)
                  AND consumed_at IS NULL
                  AND cancelled_at IS NULL
                  AND expires_at > now()
                  AND attempts < max_attempts
                RETURNING *
            )
            SQL . self::CHALLENGE_COLUMNS . ' FROM challenge_attempt',
            [':id' => $id],
        )->queryOne();

        return is_array($row) ? self::challenge($row) : null;
    }

    public function cancelAllForAccount(string $accountId, ?string $exceptId = null): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.auth_challenges
            SET cancelled_at = now()
            WHERE account_id = CAST(:account_id AS uuid)
              AND consumed_at IS NULL
              AND cancelled_at IS NULL
              AND (CAST(:except_id AS uuid) IS NULL OR id <> CAST(:except_id AS uuid))
            SQL,
            [':account_id' => $accountId, ':except_id' => $exceptId],
        )->execute();
    }

    private const string SESSION_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            account_id::text AS account_id,
            encode(session_token_hash, 'hex') AS session_token_hash_hex,
            encode(refresh_token_hash, 'hex') AS refresh_token_hash_hex,
            security_stamp::text AS security_stamp,
            authentication_method,
            to_char(mfa_verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS mfa_verified_at,
            client_type,
            device_name,
            host(ip_address) AS ip_address,
            user_agent,
            browser_name,
            browser_version,
            os_name,
            os_version,
            device_type,
            device_model,
            client_locale,
            client_timezone,
            client_instance_id::text AS client_instance_id,
            encode(device_fingerprint_hash, 'hex') AS device_fingerprint_hash_hex,
            geo_country_code,
            geo_region,
            geo_city,
            geo_timezone,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(last_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_seen_at,
            to_char(access_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS access_expires_at,
            to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expires_at,
            to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
            revoke_reason
        SQL;

    private const string EXTERNAL_IDENTITY_COLUMNS = <<<'SQL'
        SELECT
            identity.id::text AS id,
            identity.account_id::text AS account_id,
            identity.provider_id::text AS provider_id,
            provider.code AS provider_code,
            identity.provider_subject,
            identity.email,
            identity.email_verified,
            identity.display_name,
            identity.avatar_url,
            identity.claims::text AS claims,
            to_char(identity.linked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS linked_at,
            to_char(identity.last_login_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_login_at
        SQL;

    private const string TOTP_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            account_id::text AS account_id,
            label,
            encode(secret_encrypted, 'hex') AS secret_encrypted_hex,
            algorithm::text AS algorithm,
            digits,
            period_seconds,
            to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_used_at,
            to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS revoked_at,
            last_used_counter,
            secret_key_version,
            to_char(setup_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS setup_expires_at
        SQL;

    private const string CHALLENGE_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            account_id::text AS account_id,
            challenge_type,
            encode(secret_hash, 'hex') AS secret_hash_hex,
            target_normalized,
            payload::text AS payload,
            attempts,
            max_attempts,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS expires_at,
            to_char(consumed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS consumed_at,
            to_char(cancelled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS cancelled_at
        SQL;

    /** @param array<array-key, mixed> $row */
    private static function identifier(array $row): AuthIdentifier
    {
        return new AuthIdentifier(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['identifier_type'],
            (string) $row['value'],
            (string) $row['normalized_value'],
            self::boolean($row['is_primary']),
            self::boolean($row['can_login']),
            self::boolean($row['can_recover']),
            self::nullableString($row['verified_at']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function password(array $row): PasswordCredential
    {
        return new PasswordCredential(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['password_hash'],
            (string) $row['password_algorithm'],
            self::boolean($row['must_change_password']),
            (string) $row['password_changed_at'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function provider(array $row): AuthProvider
    {
        return new AuthProvider(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['protocol'],
            (string) $row['display_name'],
            self::boolean($row['enabled']),
            self::boolean($row['allow_login']),
            self::boolean($row['allow_registration']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function externalIdentity(array $row): ExternalIdentity
    {
        return new ExternalIdentity(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['provider_id'],
            (string) $row['provider_code'],
            (string) $row['provider_subject'],
            self::nullableString($row['email']),
            self::boolean($row['email_verified']),
            self::nullableString($row['display_name']),
            self::nullableString($row['avatar_url']),
            self::jsonObject((string) $row['claims']),
            (string) $row['linked_at'],
            self::nullableString($row['last_login_at']),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function passkey(array $row): Passkey
    {
        return new Passkey(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['credential_id_hex'],
            (string) $row['public_key_hex'],
            (int) $row['sign_count'],
            self::nullableString($row['aaguid']),
            self::stringList((string) $row['transports']),
            self::nullableString($row['label']),
            (string) $row['created_at'],
            self::nullableString($row['last_used_at']),
            self::nullableString($row['revoked_at']),
            self::boolean($row['backup_eligible'] ?? false),
            self::boolean($row['backup_state'] ?? false),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function totp(array $row): TotpFactor
    {
        return new TotpFactor(
            (string) $row['id'],
            (string) $row['account_id'],
            self::nullableString($row['label']),
            (string) $row['secret_encrypted_hex'],
            (string) $row['algorithm'],
            (int) $row['digits'],
            (int) $row['period_seconds'],
            self::nullableString($row['verified_at']),
            (string) $row['created_at'],
            self::nullableString($row['last_used_at']),
            self::nullableString($row['revoked_at']),
            $row['last_used_counter'] === null ? null : (int) $row['last_used_counter'],
            (int) $row['secret_key_version'],
            self::nullableString($row['setup_expires_at']),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function recoveryCode(array $row): RecoveryCode
    {
        return new RecoveryCode(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['code_hash_hex'],
            (string) $row['created_at'],
            self::nullableString($row['used_at']),
            self::nullableString($row['revoked_at']),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function session(array $row): AuthSession
    {
        return new AuthSession(
            (string) $row['id'],
            (string) $row['account_id'],
            (string) $row['session_token_hash_hex'],
            self::nullableString($row['refresh_token_hash_hex']),
            (string) $row['security_stamp'],
            (string) $row['authentication_method'],
            self::nullableString($row['mfa_verified_at']),
            (string) $row['client_type'],
            self::nullableString($row['device_name']),
            self::nullableString($row['ip_address']),
            self::nullableString($row['user_agent']),
            self::nullableString($row['browser_name']),
            self::nullableString($row['browser_version']),
            self::nullableString($row['os_name']),
            self::nullableString($row['os_version']),
            self::nullableString($row['device_type']),
            self::nullableString($row['device_model']),
            self::nullableString($row['client_locale']),
            self::nullableString($row['client_timezone']),
            self::nullableString($row['client_instance_id']),
            self::nullableString($row['device_fingerprint_hash_hex']),
            self::nullableString($row['geo_country_code']),
            self::nullableString($row['geo_region']),
            self::nullableString($row['geo_city']),
            self::nullableString($row['geo_timezone']),
            (string) $row['created_at'],
            (string) $row['last_seen_at'],
            (string) $row['access_expires_at'],
            (string) $row['expires_at'],
            self::nullableString($row['revoked_at']),
            self::nullableString($row['revoke_reason']),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function challenge(array $row): AuthChallenge
    {
        return new AuthChallenge(
            (string) $row['id'],
            self::nullableString($row['account_id']),
            (string) $row['challenge_type'],
            (string) $row['secret_hash_hex'],
            self::nullableString($row['target_normalized']),
            self::jsonObject((string) $row['payload']),
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            (string) $row['created_at'],
            (string) $row['expires_at'],
            self::nullableString($row['consumed_at']),
            self::nullableString($row['cancelled_at']),
        );
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(string $json): array
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private static function stringList(string $json): array
    {
        $result = [];
        foreach (self::jsonObject($json) as $value) {
            if (is_string($value)) {
                $result[] = $value;
            }
        }
        return $result;
    }
}
