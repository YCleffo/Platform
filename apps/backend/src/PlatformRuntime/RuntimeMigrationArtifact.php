<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Migration boundary executed inside the immutable target runtime image. */
final readonly class RuntimeMigrationArtifact
{
    public function __construct(private ConnectionInterface $db) {}

    /** @return list<string> */
    public function validateHistory(string $directory = '/app/migrations/sql'): array
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        $available = array_map('basename', $files);
        sort($available, SORT_STRING);
        if ($available === []) {
            throw new DomainException('Migration artifact is empty.');
        }
        $table = $this->db->createCommand("SELECT to_regclass('public.platform_migrations')")->queryScalar();
        if ($table === false || $table === null) {
            return $available;
        }
        $applied = $this->db->createCommand('SELECT migration_name FROM public.platform_migrations ORDER BY migration_name')->queryColumn();
        foreach ($applied as $name) {
            if (!is_string($name) || !in_array($name, $available, true)) {
                throw new DomainException('Applied migration is absent from target artifact.');
            }
        }
        return $available;
    }

    public function apply(string $directory = '/app/migrations/sql'): void
    {
        $available = $this->validateHistory($directory);
        $this->db->createCommand(<<<'SQL'
CREATE TABLE IF NOT EXISTS public.platform_migrations (
 migration_name text PRIMARY KEY,
 applied_at timestamptz NOT NULL DEFAULT now()
)
SQL)->execute();
        foreach ($available as $name) {
            $applied = $this->db->createCommand('SELECT 1 FROM public.platform_migrations WHERE migration_name=:name', [':name' => $name])->queryScalar();
            if ($applied !== false && $applied !== null) {
                continue;
            }
            $path = rtrim($directory, '/') . '/' . $name;
            $sql = file_get_contents($path);
            if (!is_string($sql) || trim($sql) === '') {
                throw new DomainException('Invalid migration artifact.');
            }
            $this->db->transaction(function () use ($sql, $name): void {
                $this->db->createCommand($sql)->execute();
                $this->db->createCommand('INSERT INTO public.platform_migrations(migration_name) VALUES (:name)', [':name' => $name])->execute();
            });
        }
    }
}
