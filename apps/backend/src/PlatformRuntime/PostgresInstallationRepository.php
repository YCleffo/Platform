<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresInstallationRepository implements InstallationRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function find(string $installationId): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
SELECT id::text, status::text, current_entitlement_sequence
FROM platform_runtime.installations WHERE id = CAST(:id AS uuid)
SQL, [':id' => $installationId])->queryOne();
        return is_array($row) ? $row : null;
    }

    public function localId(): string
    {
        $id = $this->db->createCommand('SELECT id::text FROM platform_runtime.installations WHERE singleton_key = 1')->queryScalar();
        if (!is_string($id)) { throw new DomainException('Local installation is unavailable.'); }
        return $id;
    }
}
