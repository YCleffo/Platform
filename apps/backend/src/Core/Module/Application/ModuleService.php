<?php

declare(strict_types=1);

namespace App\Core\Module\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ModuleService
{
    public function __construct(
        private ModuleRepository $repository,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    /** @return list<ModuleView> */
    public function list(): array
    {
        return $this->repository->findAll();
    }

    public function get(string $moduleCode): ?ModuleView
    {
        return $this->repository->findByCode($moduleCode);
    }

    public function isEnabled(string $moduleCode): bool
    {
        return $this->repository->findByCode($moduleCode)?->enabled ?? false;
    }

    public function setEnabled(
        string $moduleCode,
        bool $enabled,
        string $actorAccountId,
        ClientContext $context,
    ): ?ModuleStateChange {
        return $this->db->transaction(function () use (
            $moduleCode,
            $enabled,
            $actorAccountId,
            $context,
        ): ?ModuleStateChange {
            $change = $this->repository->setEnabled($moduleCode, $enabled);
            if ($change === null || !$change->changed) {
                return $change;
            }

            $this->audit->record(new SecurityAuditCommand(
                action: $enabled ? SecurityAction::MODULE_ENABLED : SecurityAction::MODULE_DISABLED,
                actorAccountId: $actorAccountId,
                resourceType: 'core.module',
                resourceId: $change->after->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                beforeData: self::auditState($change->before),
                afterData: self::auditState($change->after),
            ));

            return $change;
        });
    }

    /** @return array<string, mixed> */
    private static function auditState(ModuleView $module): array
    {
        return [
            'code' => $module->code,
            'enabled' => $module->enabled,
            'enabledAt' => $module->enabledAt,
            'disabledAt' => $module->disabledAt,
        ];
    }
}
