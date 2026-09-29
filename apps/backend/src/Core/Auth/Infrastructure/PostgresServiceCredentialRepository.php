<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\ServiceCredentialRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresServiceCredentialRepository implements ServiceCredentialRepository
{
    private const string COLUMNS = <<<'SQL'
        id::text AS "id", credential_id::text AS "credentialId", account_id::text AS "accountId",
        array_to_json(scopes)::text AS "scopesJson", status,
        created_at::text AS "createdAt", expires_at::text AS "expiresAt",
        last_used_at::text AS "lastUsedAt", rotated_at::text AS "rotatedAt",
        revoked_at::text AS "revokedAt"
        SQL;

    public function __construct(private ConnectionInterface $db) {}

    public function create(string $accountId, string $hashHex, array $scopes, ?string $expiresAt, ?string $actorAccountId): ?array
    {
        $allowed = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM core.accounts WHERE id = CAST(:account AS uuid)
            AND account_type IN ('service', 'system') AND status = 'active'
            AND disabled_at IS NULL AND archived_at IS NULL FOR SHARE
            SQL, [':account' => $accountId])->queryScalar();
        if ($allowed === false || $allowed === null) {
            return null;
        }
        $row = $this->db->createCommand(
            'INSERT INTO core.service_credentials (account_id, secret_hash, scopes, expires_at, created_by_account_id) '
            . 'VALUES (CAST(:account AS uuid), decode(:hash, \'hex\'), CAST(:scopes AS text[]), '
            . 'CAST(:expires AS timestamptz), CAST(:actor AS uuid)) RETURNING ' . self::COLUMNS,
            [':account' => $accountId, ':hash' => $hashHex, ':scopes' => self::pgArray($scopes),
                ':expires' => $expiresAt, ':actor' => $actorAccountId],
        )->queryOne();
        return is_array($row) ? self::map($row) : null;
    }

    public function listFor(string $accountId): array
    {
        $rows = $this->db->createCommand('SELECT ' . self::COLUMNS
            . ' FROM core.service_credentials WHERE account_id = CAST(:account AS uuid) ORDER BY created_at DESC, id DESC LIMIT 1000',
            [':account' => $accountId])->queryAll();
        return array_map(self::map(...), $rows);
    }

    public function rotate(string $accountId, string $credentialId, string $hashHex, ?string $actorAccountId): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT c.array_scopes, c.expires_at::text AS expires_at
            FROM (SELECT id, account_id, scopes AS array_scopes, expires_at, status
                  FROM core.service_credentials WHERE credential_id = CAST(:id AS uuid)
                  AND account_id = CAST(:account AS uuid) FOR UPDATE) c
            JOIN core.accounts a ON a.id = c.account_id
            WHERE c.status = 'active' AND (c.expires_at IS NULL OR c.expires_at > now())
              AND a.account_type IN ('service','system') AND a.status = 'active'
              AND a.disabled_at IS NULL AND a.archived_at IS NULL
            SQL, [':id' => $credentialId, ':account' => $accountId])->queryOne();
        if (!is_array($row)) {
            return null;
        }
        $this->db->createCommand(<<<'SQL'
            UPDATE core.service_credentials SET status = 'rotated', rotated_at = now()
            WHERE credential_id = CAST(:id AS uuid) AND account_id = CAST(:account AS uuid) AND status = 'active'
            SQL, [':id' => $credentialId, ':account' => $accountId])->execute();
        $new = $this->db->createCommand(
            'INSERT INTO core.service_credentials (account_id, secret_hash, scopes, expires_at, created_by_account_id) '
            . 'VALUES (CAST(:account AS uuid), decode(:hash, \'hex\'), CAST(:scopes AS text[]), '
            . 'CAST(:expires AS timestamptz), CAST(:actor AS uuid)) RETURNING ' . self::COLUMNS,
            [':account' => $accountId, ':hash' => $hashHex, ':scopes' => $row['array_scopes'],
                ':expires' => $row['expires_at'], ':actor' => $actorAccountId],
        )->queryOne();
        return is_array($new) ? self::map($new) : null;
    }

    public function revoke(string $accountId, string $credentialId): ?array
    {
        $row = $this->db->createCommand('SELECT ' . self::COLUMNS
            . ' FROM core.service_credentials WHERE credential_id = CAST(:id AS uuid) '
            . 'AND account_id = CAST(:account AS uuid) FOR UPDATE',
            [':id' => $credentialId, ':account' => $accountId])->queryOne();
        if (!is_array($row)) {
            return null;
        }
        $changed = $row['status'] === 'active';
        if ($changed) {
            $row = $this->db->createCommand('UPDATE core.service_credentials SET status = \'revoked\', revoked_at = now() '
                . 'WHERE credential_id = CAST(:id AS uuid) RETURNING ' . self::COLUMNS,
                [':id' => $credentialId])->queryOne();
        }
        return [...self::map($row), 'changed' => $changed];
    }

    public function findForAuthentication(string $credentialId): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT c.account_id::text AS "accountId", c.credential_id::text AS "credentialId",
                   encode(c.secret_hash, 'hex') AS "hashHex", array_to_json(c.scopes)::text AS "scopesJson",
                   c.status, c.expires_at::text AS "expiresAt", c.revoked_at::text AS "revokedAt",
                   a.account_type::text AS "accountType", a.status::text AS "accountStatus",
                   a.disabled_at::text AS "disabledAt", a.archived_at::text AS "archivedAt"
            FROM core.service_credentials c JOIN core.accounts a ON a.id = c.account_id
            WHERE c.credential_id = CAST(:id AS uuid)
            SQL, [':id' => $credentialId])->queryOne();
        if (!is_array($row)) {
            return null;
        }
        $row['scopes'] = json_decode((string) $row['scopesJson'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['scopesJson']);
        return $row;
    }

    public function touch(string $credentialId): void
    {
        $this->db->createCommand(<<<'SQL'
            UPDATE core.service_credentials SET last_used_at = now()
            WHERE credential_id = CAST(:id AS uuid)
              AND status = 'active'
              AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')
            SQL, [':id' => $credentialId])->execute();
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        $scopes = json_decode((string) $row['scopesJson'], true, flags: JSON_THROW_ON_ERROR);
        return ['id' => (string) $row['id'], 'credentialId' => (string) $row['credentialId'],
            'accountId' => (string) $row['accountId'], 'scopes' => $scopes,
            'status' => (string) $row['status'], 'createdAt' => (string) $row['createdAt'],
            'expiresAt' => $row['expiresAt'], 'lastUsedAt' => $row['lastUsedAt'],
            'rotatedAt' => $row['rotatedAt'], 'revokedAt' => $row['revokedAt']];
    }

    /** @param list<string> $items */
    private static function pgArray(array $items): string
    {
        return '{' . implode(',', array_map(static fn(string $item): string => '"' . $item . '"', $items)) . '}';
    }
}
