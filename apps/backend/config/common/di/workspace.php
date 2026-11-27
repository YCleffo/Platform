<?php

declare(strict_types=1);

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Media\Application\MediaAccessAuthorizer;
use App\Realtime\Application\RealtimeService;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use App\Workspace\Application\DashboardProviderRegistry;
use App\Workspace\Application\SearchProviderRegistry;
use App\Workspace\Application\TrashProviderRegistry;
use App\Workspace\Application\WorkspaceAudit;
use App\Workspace\Application\WorkspaceDashboardService;
use App\Workspace\Application\WorkspaceOperationService;
use App\Workspace\Application\WorkspacePreferencesService;
use App\Workspace\Application\WorkspaceSearchService;
use App\Workspace\Application\WorkspaceTrashService;
use App\Workspace\Domain\WorkspaceAccess;
use App\Workspace\Infrastructure\ActivityItemTrashProvider;
use App\Workspace\Infrastructure\Dashboard\ActivityDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\ChatDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\CoreDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\DeviceDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\EducationDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\NotificationsDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\StorageMediaDashboardProvider;
use App\Workspace\Infrastructure\Dashboard\WorkspaceOperationsDashboardProvider;
use App\Workspace\Infrastructure\EducationHomeworkTrashProvider;
use App\Workspace\Infrastructure\MediaAssetTrashProvider;
use App\Workspace\Infrastructure\Search\ActivitySearchProvider;
use App\Workspace\Infrastructure\Search\ChatSearchProvider;
use App\Workspace\Infrastructure\Search\CoreDirectorySearchProvider;
use App\Workspace\Infrastructure\Search\DeviceSearchProvider;
use App\Workspace\Infrastructure\Search\DocumentsSearchProvider;
use App\Workspace\Infrastructure\Search\EducationSearchProvider;
use App\Workspace\Infrastructure\Search\SectionSearchProvider;
use App\Workspace\Infrastructure\StorageFileTrashProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

return [
    DashboardProviderRegistry::class => static function (
        ConnectionInterface $db,
        WorkspaceAccess $access,
        ActivityDomainPolicyInterface $activityPolicy,
    ): DashboardProviderRegistry {
        return new DashboardProviderRegistry([
            new CoreDashboardProvider($db),
            new NotificationsDashboardProvider($db),
            new WorkspaceOperationsDashboardProvider($db),
            new StorageMediaDashboardProvider($db),
            new ActivityDashboardProvider($db, $activityPolicy),
            new ChatDashboardProvider($db),
            new EducationDashboardProvider($db, $access),
            new DeviceDashboardProvider($db, $access),
        ]);
    },
    SearchProviderRegistry::class => static function (
        ConnectionInterface $db,
        WorkspaceAccess $access,
        ActivityDomainPolicyInterface $activityPolicy,
        ?MediaAccessAuthorizer $mediaAuthorizer = null,
    ): SearchProviderRegistry {
        return new SearchProviderRegistry([
            new CoreDirectorySearchProvider($db, $access),
            new EducationSearchProvider($db, $access),
            new ActivitySearchProvider($db, $activityPolicy),
            new DocumentsSearchProvider($db, $mediaAuthorizer),
            new ChatSearchProvider($db),
            new DeviceSearchProvider($db, $access),
            new SectionSearchProvider(),
        ]);
    },
    TrashProviderRegistry::class => static function (
        ConnectionInterface $db,
        WorkspaceAccess $access,
    ): TrashProviderRegistry {
        return new TrashProviderRegistry([
            new StorageFileTrashProvider($db, $access),
            new MediaAssetTrashProvider($db, $access),
            new ActivityItemTrashProvider($db, $access),
            new EducationHomeworkTrashProvider($db, $access),
        ]);
    },
    WorkspaceTrashService::class => static function (
        ConnectionInterface $db,
        WorkspaceAccess $access,
        TrashProviderRegistry $providers,
        WorkspaceAudit $audit,
        RealtimeService $realtime,
        ?StorageFileAdapter $storage = null,
        ?S3Objects $s3Objects = null,
        ?S3Config $s3Config = null,
    ): WorkspaceTrashService {
        return new WorkspaceTrashService(
            $db,
            $access,
            $providers,
            $audit,
            $realtime,
            $storage,
            $s3Objects,
            $s3Config,
        );
    },
    WorkspaceDashboardService::class => WorkspaceDashboardService::class,
    WorkspaceSearchService::class => WorkspaceSearchService::class,
    WorkspaceOperationService::class => WorkspaceOperationService::class,
    WorkspacePreferencesService::class => WorkspacePreferencesService::class,
];
