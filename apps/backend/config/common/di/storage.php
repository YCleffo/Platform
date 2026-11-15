<?php

declare(strict_types=1);

use App\Storage\Application\Mutation;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Application\StorageStore;
use App\Storage\Application\StorageService;
use App\Storage\Infrastructure\S3StorageFileAdapter;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\PostgresStorageStore;
use App\Storage\Infrastructure\StorageMigrationRunner;
use App\Storage\Infrastructure\StorageMutation;

$root = dirname(__DIR__, 3);
if (!class_exists(PostgresStorageStore::class)) {
    return [];
}
return [
    StorageStore::class => ['class' => PostgresStorageStore::class, '__construct()' => ['privacy' => \Yiisoft\Definitions\Reference::to(\App\Core\Privacy\Application\PrivacyResourceUsePolicy::class)]],
    Mutation::class => ['class' => StorageMutation::class],
    StorageFileAdapter::class => ['class' => S3StorageFileAdapter::class],
    StorageService::class => ['__construct()' => [
        'maxFileSize' => \Yiisoft\Definitions\DynamicReference::to(static fn(S3Config $config): int => $config->uploadMaxBytes),
    ]],
    \App\Storage\Infrastructure\BinaryCleanup::class => ['__construct()' => [
        'objects' => \Yiisoft\Definitions\Reference::to(\App\Storage\Infrastructure\S3Objects::class),
        'config' => \Yiisoft\Definitions\Reference::to(S3Config::class),
        'clients' => \Yiisoft\Definitions\Reference::to(\App\Storage\Infrastructure\S3ClientFactory::class),
    ]],
    StorageMigrationRunner::class => ['__construct()' => ['directory' => $root . '/migrations/storage']],
];
