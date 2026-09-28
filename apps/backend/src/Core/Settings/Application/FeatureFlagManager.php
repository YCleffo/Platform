<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class FeatureFlagManager
{
    public function __construct(
        private FeatureFlagRepository $repository,
        private ModuleRepository $modules,
        private SettingSecurityPolicy $securityPolicy,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    /** @return list<FeatureFlag> */
    public function list(): array
    {
        return $this->repository->findAll();
    }

    public function get(string $code): ?FeatureFlag
    {
        return $this->repository->findByCode($code);
    }

    /** @param array<array-key, mixed> $config */
    public function put(
        string $code,
        ?string $moduleCode,
        bool $enabled,
        int $rolloutPercent,
        array $config,
        ?string $description,
        string $actorAccountId,
        ClientContext $context,
    ): ?FeatureFlag {
        $this->securityPolicy->assertValueSafe($config);

        return $this->db->transaction(function () use (
            $code,
            $moduleCode,
            $enabled,
            $rolloutPercent,
            $config,
            $description,
            $actorAccountId,
            $context,
        ): ?FeatureFlag {
            $module = $moduleCode === null ? null : $this->modules->findByCode($moduleCode);
            if ($moduleCode !== null && $module === null) {
                return null;
            }
            $before = $this->repository->findByCode($code);
            $after = $this->repository->upsert(
                $module?->id,
                $code,
                $enabled,
                $rolloutPercent,
                $config,
                $description,
                $actorAccountId,
            );
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::FEATURE_FLAG_UPDATED,
                actorAccountId: $actorAccountId,
                resourceType: 'core.feature_flag',
                resourceId: $after->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                beforeData: $before === null ? null : self::auditData($before),
                afterData: self::auditData($after),
            ));

            return $after;
        });
    }

    public function delete(
        string $code,
        string $actorAccountId,
        ClientContext $context,
    ): ?FeatureFlag {
        return $this->db->transaction(function () use ($code, $actorAccountId, $context): ?FeatureFlag {
            $deleted = $this->repository->delete($code);
            if ($deleted !== null) {
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::FEATURE_FLAG_DELETED,
                    actorAccountId: $actorAccountId,
                    resourceType: 'core.feature_flag',
                    resourceId: $deleted->id,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    beforeData: self::auditData($deleted),
                ));
            }

            return $deleted;
        });
    }

    /** @return array<string, mixed> */
    private static function auditData(FeatureFlag $flag): array
    {
        return [
            'code' => $flag->code,
            'moduleCode' => $flag->moduleCode,
            'enabled' => $flag->enabled,
            'rolloutPercent' => $flag->rolloutPercent,
        ];
    }
}
