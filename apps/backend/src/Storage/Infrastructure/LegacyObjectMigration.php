<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Explicit one-time source reader. The application never serves files through this class. */
final readonly class LegacyObjectMigration
{
    public function __construct(private ConnectionInterface $db, private S3Objects $objects, private S3Config $config) {}

    public function run(string $module, string $sourceDirectory, bool $apply = false): array
    {
        if (!in_array($module, ['storage', 'education'], true)) throw new DomainException('Unknown migration module.');
        $root = realpath($sourceDirectory);
        if ($root === false || !is_dir($root) || is_link($sourceDirectory)) {
            throw new DomainException('The explicit legacy source directory is unavailable.');
        }
        $references = $this->references($module);
        $items = [];
        $errors = [];
        foreach ($references as $row) {
            $key = $row['key'];
            try {
                $path = $this->source($root, $key);
                $size = filesize($path);
                $hash = hash_file('sha256', $path);
                if ($size !== (int) $row['size'] || !is_string($hash) || !hash_equals($row['sha256'], $hash)) {
                    $errors[] = ['key' => $key, 'error' => 'source_checksum_or_size_mismatch'];
                    continue;
                }
                $items[] = ['key' => $key, 'sizeBytes' => $size, 'sha256' => $hash,
                    'sourceFingerprint' => hash('sha256', $root . DIRECTORY_SEPARATOR . $key . '|' . $hash)];
            } catch (\Throwable) {
                $errors[] = ['key' => $key, 'error' => 'source_missing_or_unsafe'];
            }
        }
        $keys = array_fill_keys(array_column($references, 'key'), true);
        $untracked = [];
        foreach (new \DirectoryIterator($root) as $file) {
            if ($file->isDot()) continue;
            if (!isset($keys[$file->getFilename()])) {
                $untracked[] = ['name' => $file->getFilename(), 'regularFile' => $file->isFile() && !$file->isLink()];
            }
        }
        foreach ($untracked as $item) {
            $errors[] = ['name' => $item['name'], 'error' => 'untracked_source'];
        }
        $report = ['module' => $module, 'mode' => $apply ? 'apply' : 'dry-run',
            'referencedObjects' => count($references), 'verifiedSourceObjects' => count($items),
            'errors' => $errors, 'untrackedSources' => $untracked, 'objects' => [], 'sourcesDeleted' => 0];
        if ($errors !== []) return [...$report, 'success' => false];
        if (!$apply) return [...$report, 'success' => true, 'objects' => $items];
        if ($this->db->getTransaction() !== null) throw new DomainException('Object migration requires committed source metadata.');
        foreach ($items as $item) {
            try {
                $this->migrate($module, $root, $item);
                $report['objects'][] = [...$item, 's3Verified' => true];
            } catch (\Throwable) {
                $report['errors'][] = ['key' => $item['key'], 'error' => 'object_migration_failed'];
                return [...$report, 'success' => false];
            }
        }
        return [...$report, 'success' => true];
    }

    private function migrate(string $module, string $root, array $item): void
    {
        $this->db->transaction(function () use ($module, $root, $item): void {
            $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',
                [':key' => 'platform.storage.migrate.' . $module . '.' . $item['key']])->queryScalar();
            $bucket = $module === 'storage' ? $this->config->bucketStorage : $this->config->bucketEducation;
            $key = S3Objects::objectKey($item['key']);
            if ($this->objects->head($bucket, $key) === null) {
                $source = fopen($this->source($root, $item['key']), 'rb');
                if ($source === false) throw new DomainException('Cannot open the verified legacy source.');
                try { $this->objects->put($bucket, $key, $source); }
                finally { fclose($source); }
            }
            $verified = $this->objects->verify($bucket, $key, $item['sizeBytes']);
            if ($verified['sizeBytes'] !== $item['sizeBytes'] || !hash_equals($verified['sha256'], $item['sha256'])) {
                throw new StorageError(409, 'migration_checksum_mismatch');
            }
            $this->db->createCommand('INSERT INTO storage.object_migrations(module,storage_key,size_bytes,sha256,source_fingerprint)
                VALUES(:module,CAST(:key AS uuid),:size,:sha,:fingerprint)
                ON CONFLICT(module,storage_key) DO UPDATE SET size_bytes=EXCLUDED.size_bytes,sha256=EXCLUDED.sha256,
                    source_fingerprint=EXCLUDED.source_fingerprint,verified_at=now()',
                [':module' => $module, ':key' => $item['key'], ':size' => $verified['sizeBytes'],
                    ':sha' => $verified['sha256'], ':fingerprint' => $item['sourceFingerprint']])->execute();
        });
    }

    private function references(string $module): array
    {
        $sql = $module === 'storage'
            ? 'SELECT storage_key::text AS key,size_bytes AS size,sha256 FROM storage.versions'
            : 'SELECT storage_key::text AS key,size_bytes AS size,sha256 FROM education.files
                UNION ALL SELECT storage_key::text AS key,size_bytes AS size,sha256 FROM education.homework_files';
        $rows = $this->db->createCommand($sql)->queryAll();
        $byKey = [];
        foreach ($rows as $row) {
            if (isset($byKey[$row['key']]) && ((int) $byKey[$row['key']]['size'] !== (int) $row['size']
                || $byKey[$row['key']]['sha256'] !== $row['sha256'])) {
                throw new DomainException('Conflicting metadata references the same object key.');
            }
            $byKey[$row['key']] = $row;
        }
        return array_values($byKey);
    }

    private function source(string $root, string $key): string
    {
        S3Objects::objectKey($key);
        $path = $root . DIRECTORY_SEPARATOR . $key;
        $resolved = realpath($path);
        $prefix = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
        if ($resolved === false || !is_file($resolved) || is_link($path)
            || strncasecmp($resolved, $prefix, strlen($prefix)) !== 0
            || dirname($resolved) !== $root) {
            throw new DomainException('Legacy source key is unavailable or escapes its directory.');
        }
        return $resolved;
    }
}
