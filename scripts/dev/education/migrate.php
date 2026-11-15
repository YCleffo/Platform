<?php

declare(strict_types=1);

require __DIR__ . '/guard.php';
$pdo = educationFixtureDatabase();
// This marker exists only in the standalone local stack, never in working Core.
$pdo->exec('CREATE TABLE IF NOT EXISTS public.education_local_migrations
    (name text PRIMARY KEY,sha256 char(64) NOT NULL,applied_at timestamptz NOT NULL DEFAULT now())');
$files = glob('/app/migrations/sql/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $name = basename($file);
    $hash = hash_file('sha256', $file);
    $find = $pdo->prepare('SELECT sha256 FROM public.education_local_migrations WHERE name=?');
    $find->execute([$name]);
    $previous = $find->fetchColumn();
    if (is_string($previous)) {
        if (!hash_equals($previous, (string) $hash)) throw new RuntimeException('Changed applied Core migration: ' . $name);
        continue;
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec((string) file_get_contents($file));
        $pdo->prepare('INSERT INTO public.education_local_migrations(name,sha256) VALUES(?,?)')->execute([$name, $hash]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
require '/app/src/bootstrap.php';
$container = (new Yiisoft\Yii\Runner\Http\HttpApplicationRunner(rootPath: '/app', environment: App\Environment::appEnv()))->getContainer();
foreach ([App\Education\Infrastructure\MigrationRunner::class,
    App\DeviceManagement\Infrastructure\DeviceMigrationRunner::class,
    App\Storage\Infrastructure\StorageMigrationRunner::class] as $class) {
    $container->get($class)->apply();
}
echo "Local Core/Education/DeviceManagement/Storage migrations verified.\n";
