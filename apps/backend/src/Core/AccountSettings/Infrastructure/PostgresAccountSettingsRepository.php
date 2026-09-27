<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Infrastructure;

use App\Core\AccountSettings\Application\AccountSettingsRepository;
use App\Core\AccountSettings\Application\StoredAccountSettings;
use DomainException;
use JsonException;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAccountSettingsRepository implements AccountSettingsRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function find(string $accountId): ?StoredAccountSettings
    {
        return $this->query($accountId, false);
    }

    public function findForUpdate(string $accountId): ?StoredAccountSettings
    {
        $exists = $this->db->createCommand(
            'SELECT id FROM core.accounts WHERE id = CAST(:account_id AS uuid) FOR UPDATE',
            [':account_id' => $accountId],
        )->queryScalar();
        if ($exists === false || $exists === null) {
            throw new DomainException('Account does not exist.');
        }
        return $this->query($accountId, true);
    }

    public function save(string $accountId, array $settings, int $schemaVersion): void
    {
        if ($settings === []) {
            $this->db->createCommand(
                'DELETE FROM core.account_settings WHERE account_id = CAST(:account_id AS uuid)',
                [':account_id' => $accountId],
            )->execute();
            return;
        }
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.account_settings (account_id, settings, schema_version)
            VALUES (CAST(:account_id AS uuid), CAST(:settings AS jsonb), :schema_version)
            ON CONFLICT (account_id) DO UPDATE
            SET settings = EXCLUDED.settings,
                schema_version = EXCLUDED.schema_version,
                updated_at = now()
            SQL,
            [
                ':account_id' => $accountId,
                ':settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                ':schema_version' => $schemaVersion,
            ],
        )->execute();
    }

    private function query(string $accountId, bool $forUpdate): ?StoredAccountSettings
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT account_id::text AS account_id, settings::text AS settings, schema_version
            FROM core.account_settings
            WHERE account_id = CAST(:account_id AS uuid)
            SQL
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [':account_id' => $accountId],
        )->queryOne();
        if (!is_array($row)) {
            return null;
        }
        try {
            $settings = json_decode((string) $row['settings'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored account settings contain invalid JSON.', previous: $exception);
        }
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new RuntimeException('Stored account settings must be a JSON object.');
        }
        /** @var array<string, mixed> $settings */
        return new StoredAccountSettings(
            (string) $row['account_id'],
            $settings,
            (int) $row['schema_version'],
        );
    }
}
