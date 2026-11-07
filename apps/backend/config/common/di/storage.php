<?php

declare(strict_types=1);

use App\Storage\Application\Mutation;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Application\StorageStore;
use App\Storage\Infrastructure\LocalFilesystemStorageAdapter;
use App\Storage\Infrastructure\PostgresStorageStore;
use App\Storage\Infrastructure\StorageMigrationRunner;
use App\Storage\Infrastructure\StorageMutation;

$root = dirname(__DIR__, 3);
if (!class_exists(PostgresStorageStore::class)) {
    return [];
}
return [
    StorageStore::class => ['class' => PostgresStorageStore::class],
    Mutation::class => ['class' => StorageMutation::class],
    StorageFileAdapter::class => ['class' => LocalFilesystemStorageAdapter::class,
        '__construct()' => ['root' => (getenv('STORAGE_FILE_DIRECTORY') ?: $root . '/runtime/storage-files')]],
    StorageMigrationRunner::class => ['__construct()' => ['directory' => $root . '/migrations/storage']],
];
