<?php

declare(strict_types=1);

namespace App\Shared\Database;

use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;

/**
 * Shared idempotent PostgreSQL schema migration runner with advisory locking and SHA-256 verification.
 */
final readonly class SchemaMigrationRunner
{
    public function __construct(
        private ConnectionInterface $db,
        private string $schema,
        private string $directory,
        private int $advisoryLockKey,
    ) {}

    public function apply(): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $this->schema) !== 1) {
            throw new RuntimeException('Invalid schema identifier.');
        }

        $this->db->transaction(function (): void {
            $this->db->createCommand('SELECT pg_advisory_xact_lock(:key)', [':key' => $this->advisoryLockKey])->queryScalar();
            $this->db->createCommand(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $this->schema))->execute();
            $this->db->createCommand(sprintf(
                'CREATE TABLE IF NOT EXISTS %s.module_migrations (name text PRIMARY KEY, sha256 char(64) NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())',
                $this->schema,
            ))->execute();

            $files = glob(rtrim($this->directory, '/\\') . '/*.sql');
            if ($files === false || $files === []) {
                throw new RuntimeException(sprintf('Migrations for %s are missing.', $this->schema));
            }
            sort($files, SORT_STRING);

            foreach ($files as $file) {
                $name = basename($file);
                $hash = hash_file('sha256', $file);
                if ($hash === false) {
                    throw new RuntimeException(sprintf('Cannot hash migration %s.', $name));
                }
                $previous = $this->db->createCommand(
                    sprintf('SELECT sha256 FROM %s.module_migrations WHERE name = :name', $this->schema),
                    [':name' => $name],
                )->queryScalar();
                if (is_string($previous)) {
                    if (!hash_equals($previous, $hash)) {
                        throw new RuntimeException(sprintf('Applied migration %s.%s has changed.', $this->schema, $name));
                    }
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException(sprintf('Cannot read migration %s.', $name));
                }
                if (!$this->db instanceof PdoConnectionInterface) {
                    throw new RuntimeException('SchemaMigrationRunner requires PostgreSQL PDO adapter.');
                }
                $this->db->getActivePdo()->exec($sql);
                $this->db->createCommand(
                    sprintf('INSERT INTO %s.module_migrations(name, sha256) VALUES(:name, :hash)', $this->schema),
                    [':name' => $name, ':hash' => $hash],
                )->execute();
            }
        });
    }
}
