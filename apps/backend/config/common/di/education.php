<?php

declare(strict_types=1);

use App\Education\Application\EducationStore;
use App\Education\Application\FileStore;
use App\Education\Application\Mutation;
use App\Education\Infrastructure\MigrationRunner;
use App\Education\Infrastructure\PostgresEducationStore;
use App\Education\Infrastructure\PostgresMutation;
use App\Education\Infrastructure\PrivateFileStore;

$root = dirname(__DIR__, 3);
if (!class_exists(PostgresEducationStore::class)) {
    return [];
}
return [
    EducationStore::class => ['class' => PostgresEducationStore::class],
    FileStore::class => ['class' => PrivateFileStore::class, '__construct()' => [
        'directory' => getenv('EDUCATION_FILE_DIRECTORY') ?: $root . '/runtime/education-files',
    ]],
    Mutation::class => ['class' => PostgresMutation::class],
    MigrationRunner::class => ['__construct()' => ['directory' => $root . '/migrations/education']],
];
