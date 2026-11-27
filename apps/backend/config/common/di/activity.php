<?php

declare(strict_types=1);

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityEngineService;
use App\Activity\Application\ActivityPluginRegistry;
use App\Activity\Infrastructure\DefaultActivityDomainPolicy;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Education\Infrastructure\EducationActivityDomainPolicy;
use App\Notifications\Application\NotificationsService;
use App\Realtime\Application\RealtimeService;
use Yiisoft\Db\Connection\ConnectionInterface;

return [
    ActivityDomainPolicyInterface::class => static function (
        ConnectionInterface $db,
        PermissionService $permissions,
    ): ActivityDomainPolicyInterface {
        if (class_exists(EducationActivityDomainPolicy::class)) {
            return new EducationActivityDomainPolicy($db, $permissions);
        }
        return new DefaultActivityDomainPolicy($db, $permissions);
    },
    ActivityEngineService::class => static function (
        ConnectionInterface $db,
        OrganizationRepository $organizations,
        PermissionService $permissions,
        ActivityPluginRegistry $plugins,
        RealtimeService $realtime,
        NotificationsService $notifications,
        ActivityDomainPolicyInterface $domainPolicy,
    ): ActivityEngineService {
        return new ActivityEngineService(
            $db,
            $organizations,
            $permissions,
            $plugins,
            $realtime,
            $notifications,
            $domainPolicy,
        );
    },
];
