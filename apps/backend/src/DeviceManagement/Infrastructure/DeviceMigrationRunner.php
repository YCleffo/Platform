<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;

final readonly class DeviceMigrationRunner
{
    public function __construct(private ConnectionInterface $db, private string $directory) {}

    public function apply(): void
    {
        $this->db->transaction(function (): void {
            $this->db->createCommand('SELECT pg_advisory_xact_lock(1846202606)')->queryScalar();
            $this->db->createCommand('CREATE SCHEMA IF NOT EXISTS device_management')->execute();
            $this->db->createCommand('CREATE TABLE IF NOT EXISTS device_management.module_migrations
                (name text PRIMARY KEY,sha256 char(64) NOT NULL,applied_at timestamptz NOT NULL DEFAULT now())')->execute();
            $files = glob($this->directory . '/*.sql');
            if ($files === false || $files === []) {
                throw new RuntimeException('DeviceManagement migrations are missing.');
            }
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $name = basename($file);
                $hash = hash_file('sha256', $file);
                if ($hash === false) {
                    throw new RuntimeException('Cannot hash DeviceManagement migration.');
                }
                $previous = $this->db->createCommand(
                    'SELECT sha256 FROM device_management.module_migrations WHERE name=:name', [':name' => $name])->queryScalar();
                if (is_string($previous)) {
                    if (!hash_equals($previous, $hash)) {
                        throw new RuntimeException('Applied DeviceManagement migration has changed.');
                    }
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Cannot read DeviceManagement migration.');
                }
                if (!$this->db instanceof PdoConnectionInterface) {
                    throw new RuntimeException('DeviceManagement requires the PostgreSQL PDO adapter.');
                }
                $this->db->getActivePdo()->exec($sql);
                $this->db->createCommand('INSERT INTO device_management.module_migrations(name,sha256) VALUES(:name,:hash)',
                    [':name' => $name, ':hash' => $hash])->execute();
            }
        });
    }
}
