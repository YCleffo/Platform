<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\CredentialLifecycleRepository;
use App\Core\Auth\Application\CredentialTarget;
use App\Core\Shared\Application\ConflictException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class PostgresCredentialLifecycleRepository implements CredentialLifecycleRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findPrimaryEmail(string $accountId): ?CredentialTarget
    {
        return $this->find(
            'WHERE i.account_id = CAST(:value AS uuid) AND i.identifier_type = \'email\' AND i.is_primary',
            $accountId,
        );
    }

    public function findRecoverableEmail(string $normalizedEmail): ?CredentialTarget
    {
        return $this->find(
            "WHERE i.normalized_value = :value AND i.identifier_type = 'email' AND i.can_recover AND i.verified_at IS NOT NULL AND a.status = 'active' AND a.disabled_at IS NULL",
            $normalizedEmail,
        );
    }

    public function ensurePrimaryEmail(string $accountId, string $email, string $normalizedEmail): ?CredentialTarget
    {
        $accountExists = $this->db->createCommand(
            'SELECT EXISTS (SELECT 1 FROM core.accounts WHERE id = CAST(:id AS uuid) AND archived_at IS NULL)',
            [':id' => $accountId],
        )->queryScalar();
        if (!self::boolean($accountExists)) {
            return null;
        }
        $existing = $this->findPrimaryEmail($accountId);
        if ($existing !== null) {
            if (!hash_equals($existing->normalizedEmail, $normalizedEmail)) {
                throw new ConflictException('Account already has a different primary email identifier.');
            }
            return $existing;
        }

        try {
            $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_identifiers (
                    account_id, identifier_type, value, normalized_value,
                    is_primary, can_login, can_recover
                )
                VALUES (
                    CAST(:account_id AS uuid), 'email', :email, :normalized,
                    true, true, true
                )
                SQL,
                [':account_id' => $accountId, ':email' => $email, ':normalized' => $normalizedEmail],
            )->execute();
        } catch (IntegrityException $exception) {
            throw new ConflictException('Email identifier is already in use.', previous: $exception);
        }

        return $this->findPrimaryEmail($accountId);
    }

    public function completeActivation(string $secretHashHex, string $passwordHash, string $algorithm): ?string
    {
        return $this->db->transaction(function () use ($secretHashHex, $passwordHash, $algorithm): ?string {
            $challenge = $this->activeChallenge($secretHashHex, 'account_activation');
            if ($challenge === null) {
                return null;
            }
            $accountId = (string) $challenge['account_id'];
            $this->savePassword($accountId, $passwordHash, $algorithm);
            $verified = $this->db->createCommand(
                <<<'SQL'
                UPDATE core.auth_identifiers
                SET verified_at = COALESCE(verified_at, now()), updated_at = now()
                WHERE account_id = CAST(:account_id AS uuid)
                  AND identifier_type = 'email'
                  AND normalized_value = :target
                SQL,
                [':account_id' => $accountId, ':target' => $challenge['target_normalized']],
            )->execute();
            if ($verified !== 1 || !$this->consume((string) $challenge['id'])) {
                return null;
            }
            $this->db->createCommand(
                <<<'SQL'
                UPDATE core.accounts
                SET status = CASE WHEN status = 'pending_verification' THEN 'active' ELSE status END,
                    security_stamp = uuidv4(), updated_at = now()
                WHERE id = CAST(:account_id AS uuid)
                  AND status IN ('pending_verification', 'pending_approval', 'active')
                SQL,
                [':account_id' => $accountId],
            )->execute();

            return $accountId;
        });
    }

    public function completePasswordReset(
        string $secretHashHex,
        string $passwordHash,
        string $algorithm,
    ): ?CredentialTarget {
        return $this->db->transaction(function () use ($secretHashHex, $passwordHash, $algorithm): ?CredentialTarget {
            $challenge = $this->activeChallenge($secretHashHex, 'password_reset');
            if ($challenge === null) {
                return null;
            }
            $accountId = (string) $challenge['account_id'];
            $this->savePassword($accountId, $passwordHash, $algorithm);
            if (!$this->consume((string) $challenge['id'])) {
                return null;
            }
            $this->db->createCommand(
                'UPDATE core.accounts SET security_stamp = uuidv4(), updated_at = now() WHERE id = CAST(:id AS uuid)',
                [':id' => $accountId],
            )->execute();
            $this->db->createCommand(
                <<<'SQL'
                UPDATE core.auth_sessions
                SET revoked_at = now(), revoke_reason = 'password reset'
                WHERE account_id = CAST(:account_id AS uuid) AND revoked_at IS NULL
                SQL,
                [':account_id' => $accountId],
            )->execute();

            return $this->findPrimaryEmail($accountId);
        });
    }

    /** @return array<string, mixed>|null */
    private function activeChallenge(string $secretHashHex, string $type): ?array
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT id::text AS id, account_id::text AS account_id, target_normalized
            FROM core.auth_challenges
            WHERE secret_hash = decode(:hash, 'hex')
              AND challenge_type = :type
              AND consumed_at IS NULL AND cancelled_at IS NULL
              AND expires_at > now() AND attempts < max_attempts
            FOR UPDATE
            SQL,
            [':hash' => $secretHashHex, ':type' => $type],
        )->queryOne();

        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    private function consume(string $id): bool
    {
        return $this->db->createCommand(
            'UPDATE core.auth_challenges SET consumed_at = now() WHERE id = CAST(:id AS uuid) AND consumed_at IS NULL',
            [':id' => $id],
        )->execute() === 1;
    }

    private function savePassword(string $accountId, string $passwordHash, string $algorithm): void
    {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_password_credentials (
                account_id, password_hash, password_algorithm, must_change_password
            )
            VALUES (CAST(:account_id AS uuid), :hash, :algorithm, false)
            ON CONFLICT (account_id) DO UPDATE
            SET password_hash = EXCLUDED.password_hash,
                password_algorithm = EXCLUDED.password_algorithm,
                must_change_password = false,
                password_changed_at = now(), updated_at = now()
            SQL,
            [':account_id' => $accountId, ':hash' => $passwordHash, ':algorithm' => $algorithm],
        )->execute();
    }

    private function find(string $where, string $value): ?CredentialTarget
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT i.account_id::text AS account_id, i.value AS email,
                   i.normalized_value AS normalized_email,
                   COALESCE(NULLIF(a.label, ''), 'Platform user') AS display_name,
                   COALESCE(a.locale, 'ru-RU') AS locale
            FROM core.auth_identifiers i
            INNER JOIN core.accounts a ON a.id = i.account_id
            SQL
            . ' ' . $where
            . " AND a.status <> 'archived' AND a.archived_at IS NULL LIMIT 1",
            [':value' => $value],
        )->queryOne();

        return is_array($row) ? new CredentialTarget(
            accountId: (string) $row['account_id'],
            email: (string) $row['email'],
            normalizedEmail: (string) $row['normalized_email'],
            displayName: (string) $row['display_name'],
            locale: (string) $row['locale'],
        ) : null;
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }
}
