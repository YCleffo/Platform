<?php

declare(strict_types=1);

require '/app/src/bootstrap.php';

use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageStore;
use App\Storage\Infrastructure\LegacyObjectMigration;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use Yiisoft\Db\Connection\ConnectionInterface;

if (getenv('STORAGE02_TEST_RUNTIME') !== '1' || getenv('DB_NAME') !== 'platform_test') throw new RuntimeException('Isolated runtime required.');
$container = (new Yiisoft\Yii\Runner\Http\HttpApplicationRunner(rootPath: '/app', environment: App\Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$config = $container->get(S3Config::class);
if (!str_starts_with($config->bucketStorage, 'storage02-test-')) throw new RuntimeException('Test bucket required.');
$objects = $container->get(S3Objects::class);
$migration = $container->get(LegacyObjectMigration::class);
$store = $container->get(StorageStore::class);
$directory = sys_get_temp_dir() . '/storage02-migration-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create owned fixture source.');
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
try {
    // Reproduce a complete legacy source inventory, including other committed test records.
    $versions = $db->createCommand('SELECT storage_key::text,size_bytes,sha256 FROM storage.versions')->queryAll();
    foreach ($versions as $version) {
        $source = $objects->open($config->bucketStorage, S3Objects::objectKey($version['storage_key']));
        $target = fopen($directory . '/' . $version['storage_key'], 'xb');
        try { stream_copy_to_stream($source, $target); } finally { fclose($source); fclose($target); }
    }
    $student = $db->createCommand('SELECT s.id::text,s.organization_id::text,s.person_id::text,sa.account_id::text
        FROM education.students s JOIN education.student_accounts sa ON sa.student_id=s.id
        WHERE sa.revoked_at IS NULL ORDER BY s.created_at LIMIT 1')->queryOne();
    $check(is_array($student), 'An activated isolated fixture student is required.');
    $scope = new StorageScope($student['organization_id'], $student['account_id'], $student['person_id'], 'en-US', 'UTC', $student['id']);
    $key = (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar();
    $content = 'An existing legacy document with a verified immutable identifier.';
    file_put_contents($directory . '/' . $key, $content);
    $store->storeVersion($scope, 'platform-storage02-migration/' . $key . '.txt', strlen($content), hash('sha256', $content), $key, null);
    $dryRun = $migration->run('storage', $directory);
    $check($dryRun['success'] && !$objects->head($config->bucketStorage, S3Objects::objectKey($key)), 'Dry-run wrote an object or rejected a complete source.');
    $applied = $migration->run('storage', $directory, true);
    $check($applied['success'] && $applied['sourcesDeleted'] === 0, 'Migration failed or removed source bytes.');
    $count = (int) $db->createCommand("SELECT count(*) FROM storage.object_migrations WHERE module='storage'")->queryScalar();
    $check($migration->run('storage', $directory, true)['success'], 'Idempotent migration replay failed.');
    $check($count === (int) $db->createCommand("SELECT count(*) FROM storage.object_migrations WHERE module='storage'")->queryScalar(), 'Migration replay duplicated ledger entries.');
    $check($objects->verify($config->bucketStorage, S3Objects::objectKey($key))['sha256'] === hash('sha256', $content), 'Migrated object hash mismatch.');
    file_put_contents($directory . '/' . $key, 'corrupt');
    $check(!$migration->run('storage', $directory)['success'], 'Corrupt source was accepted.');
    file_put_contents($directory . '/' . $key, $content);
    file_put_contents($directory . '/untracked-source.txt', 'Preserve unaccounted data.');
    $check(!$migration->run('storage', $directory)['success'], 'Unaccounted source was accepted.');
    unlink($directory . '/untracked-source.txt');
    unlink($directory . '/' . $key);
    $check(!$migration->run('storage', $directory)['success'], 'Missing source was accepted.');
    echo "Legacy migration PASS dry-run, actual S3 copy/hash, replay, corruption, untracked and missing source.\n";
} finally {
    // These are owned copies created by this fixture. The verified S3 versions remain referenced.
    $real = realpath($directory);
    if ($real !== false && str_starts_with($real, sys_get_temp_dir() . '/storage02-migration-')) {
        foreach (new DirectoryIterator($real) as $file) {
            if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
        }
        rmdir($real);
    }
}
