<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;

require dirname(__DIR__) . '/src/bootstrap.php';

function objectLocation(string $kind, string $id, S3Config $config): array
{
    if (!BrandingSettings::uuid($id)) throw new RuntimeException('Invalid backup object identifier.');
    return match ($kind) {
        'storage' => [$config->bucketStorage, 'objects/' . $id],
        'education', 'homework' => [$config->bucketEducation, 'objects/' . $id],
        'branding' => [$config->bucketStorage, 'branding/' . $id],
        default => throw new RuntimeException('Unknown backup object category.'),
    };
}

try {
    $container = (new Yiisoft\Yii\Runner\Http\HttpApplicationRunner(rootPath: dirname(__DIR__),
        environment: App\Environment::appEnv()))->getContainer();
    $mode = $argv[1] ?? '';
    if ($mode === 'config') {
        echo json_encode(['database' => getenv('DB_NAME')], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    $db = $container->get(ConnectionInterface::class);
    if (!$db instanceof PdoConnectionInterface) throw new RuntimeException('PostgreSQL PDO is required.');
    $pdo = $db->getActivePdo();
    $config = $container->get(S3Config::class);
    $objects = $container->get(S3Objects::class);
    if ($mode === 'snapshot') {
        $pdo->query("SELECT pg_advisory_lock_shared(hashtextextended('platform.storage.backup',0))")->fetchColumn();
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $snapshot = $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
        echo json_encode(['snapshot' => $snapshot, 'database' => $pdo->query('SELECT current_database()')->fetchColumn()], JSON_THROW_ON_ERROR), PHP_EOL;
        $queries = [
            'storage' => 'SELECT storage_key::text AS id,size_bytes AS size,sha256 FROM storage.versions',
            'education' => 'SELECT storage_key::text AS id,size_bytes AS size,sha256 FROM education.files',
            'homework' => 'SELECT storage_key::text AS id,size_bytes AS size,sha256 FROM education.homework_files',
            'branding' => "SELECT value->>'id' AS id,(value->>'sizeBytes')::bigint AS size,value->>'sha256' AS sha256
                FROM core.system_settings WHERE setting_key='platform.favicon'",
        ];
        foreach ($queries as $kind => $sql) {
            if (in_array($kind, ['education', 'homework'], true)) {
                $table = $kind === 'education' ? 'education.files' : 'education.homework_files';
                $probe = $pdo->prepare('SELECT to_regclass(?)'); $probe->execute([$table]);
                if ($probe->fetchColumn() === null) continue;
            }
            $pdo->exec('DECLARE backup_objects NO SCROLL CURSOR FOR ' . $sql);
            do {
                $rows = $pdo->query('FETCH 500 FROM backup_objects')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    objectLocation($kind, $row['id'], $config);
                    echo json_encode(['kind' => $kind, 'id' => $row['id'], 'size' => (int) $row['size'], 'sha256' => $row['sha256']], JSON_THROW_ON_ERROR), PHP_EOL;
                }
            } while ($rows !== []);
            $pdo->exec('CLOSE backup_objects');
        }
        echo "{\"inventoryComplete\":true}\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'release') throw new RuntimeException('Backup snapshot was not finalized.');
        $pdo->exec('ROLLBACK');
        $pdo->query("SELECT pg_advisory_unlock_shared(hashtextextended('platform.storage.backup',0))")->fetchColumn();
    } elseif ($mode === 'read' || $mode === 'write') {
        [$bucket, $key] = objectLocation($argv[2] ?? '', $argv[3] ?? '', $config);
        if ($mode === 'read') {
            $stream = $objects->open($bucket, $key);
            try {
                if (stream_copy_to_stream($stream, STDOUT) === false) throw new RuntimeException('Object read failed.');
            } finally {
                fclose($stream);
            }
        } else {
            $size = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
            $sha = $argv[5] ?? '';
            if ($size === false || $size < 0 || !preg_match('/^[a-f0-9]{64}$/D', $sha)) throw new RuntimeException('Invalid restore metadata.');
            $temporary = tmpfile();
            if ($temporary === false) throw new RuntimeException('Restore temporary stream unavailable.');
            try {
                $copied = stream_copy_to_stream(STDIN, $temporary, $size + 1);
                rewind($temporary); $hash = hash_init('sha256'); hash_update_stream($hash, $temporary);
                if ($copied !== $size || !hash_equals($sha, hash_final($hash))) throw new RuntimeException('Backup object integrity mismatch.');
                if ($objects->head($bucket, $key) === null) {
                    rewind($temporary);
                    $objects->put($bucket, $key, $temporary);
                }
                $actual = $objects->verify($bucket, $key, $size);
                if (!hash_equals($sha, $actual['sha256'])) throw new RuntimeException('Existing restore object has different content.');
                echo json_encode(['verified' => true,
                    'database' => $pdo->query('SELECT current_database()')->fetchColumn()], JSON_THROW_ON_ERROR), PHP_EOL;
            } finally {
                fclose($temporary);
            }
        }
    } else {
        throw new RuntimeException('Choose snapshot, read or write.');
    }
} catch (Throwable $error) {
    // SDK exceptions can contain bearer URLs. Only their class is written to the console.
    fwrite(STDERR, 'Storage backup bridge failed (' . get_class($error) . ').' . PHP_EOL);
    exit(1);
}
