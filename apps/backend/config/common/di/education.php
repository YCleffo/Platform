<?php

declare(strict_types=1);

use App\Activity\Application\ActivityEngineService;
use App\Activity\Application\ActivityPluginRegistry;
use App\Activity\Application\ActivityResultListenerInterface;
use App\Education\Application\EducationStore;
use App\Education\Application\CurriculumStore;
use App\Education\Infrastructure\PostgresCurriculumStore;
use App\Education\Application\EducationService;
use App\Education\Application\HomeworkService;
use App\Education\Application\HomeworkStore;
use App\Education\Application\HomeworkExtensions;
use App\Education\Application\ActivityResultSink;
use App\Education\Infrastructure\PostgresHomeworkStore;
use App\Education\Infrastructure\PostgresHomeworkExtensions;
use App\Education\Application\FileStore;
use App\Education\Application\Mutation;
use App\Education\Infrastructure\MigrationRunner;
use App\Education\Infrastructure\PostgresEducationStore;
use App\Education\Infrastructure\PostgresMutation;
use App\Education\Infrastructure\S3FileStore;

$root = dirname(__DIR__, 3);
if (!class_exists(PostgresEducationStore::class)) {
    return [];
}
return [
    \App\Education\Application\EducationAccess::class => ['__construct()' => [
        'familyDb' => \Yiisoft\Definitions\Reference::to(\Yiisoft\Db\Connection\ConnectionInterface::class),
    ]],
    EducationStore::class => ['class' => PostgresEducationStore::class],
    CurriculumStore::class => ['class' => PostgresCurriculumStore::class],
    HomeworkStore::class => ['class' => PostgresHomeworkStore::class],
    HomeworkExtensions::class => ['class' => PostgresHomeworkExtensions::class],
    ActivityResultSink::class => ['class' => \App\Education\Infrastructure\EducationActivityEvents::class],
    ActivityResultListenerInterface::class => ['class' => \App\Education\Infrastructure\EducationActivityJournalAdapter::class],
    EducationService::class => ['__construct()' => [
        'curriculum' => \Yiisoft\Definitions\Reference::to(CurriculumStore::class),
        'activityResults' => \Yiisoft\Definitions\Reference::to(ActivityResultSink::class),
        'homework' => \Yiisoft\Definitions\Reference::to(HomeworkService::class),
        'families' => \Yiisoft\Definitions\Reference::to(\App\Education\Application\StudentFamily::class),
        'activityEngine' => \Yiisoft\Definitions\Reference::to(ActivityEngineService::class),
        'pluginRegistry' => \Yiisoft\Definitions\Reference::to(ActivityPluginRegistry::class),
        'achievements' => \Yiisoft\Definitions\Reference::to(\App\Education\Application\AchievementService::class),
        'activityView' => \Yiisoft\Definitions\Reference::to(\App\Education\Application\ActivityResultView::class),
    ]],
    FileStore::class => ['class' => S3FileStore::class],
    \App\Education\Application\AssessmentService::class => ['__construct()' => [
        'db' => \Yiisoft\Definitions\Reference::to(\Yiisoft\Db\Connection\ConnectionInterface::class),
        'engine' => \Yiisoft\Definitions\Reference::to(ActivityEngineService::class),
        'activityResults' => \Yiisoft\Definitions\Reference::to(ActivityResultSink::class),
    ]],
    Mutation::class => ['class' => PostgresMutation::class],
    MigrationRunner::class => ['__construct()' => ['directory' => $root . '/migrations/education']],
];
