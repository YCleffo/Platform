<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class SettingsService
{
    public function __construct(
        private ModuleRepository $modules,
        private ModuleSettingsRepository $moduleSettings,
        private SystemSettingsRepository $systemSettings,
        private SettingSecurityPolicy $securityPolicy,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    public function getModuleSetting(string $moduleCode, string $key): mixed
    {
        $module = $this->modules->findByCode($moduleCode);
        return $module === null
            ? null
            : $this->moduleSettings->find($module->id, $key)?->value;
    }

    public function getSystemSetting(string $key): mixed
    {
        return $this->systemSettings->findByKey($key)?->value;
    }

    /** @return list<SystemSetting> */
    public function getPublicSystemSettings(): array
    {
        return $this->systemSettings->findPublic();
    }

    /** @return list<SystemSetting> */
    public function listSystemSettings(): array
    {
        return $this->systemSettings->findAll();
    }

    public function findSystemSetting(string $key): ?SystemSetting
    {
        return $this->systemSettings->findByKey($key);
    }

    public function putSystemSetting(
        string $key,
        mixed $value,
        bool $isPublic,
        ?string $description,
        string $actorAccountId,
        ClientContext $context,
    ): SystemSetting {
        $this->securityPolicy->assertSafe($key, $value);

        return $this->db->transaction(function () use (
            $key,
            $value,
            $isPublic,
            $description,
            $actorAccountId,
            $context,
        ): SystemSetting {
            $before = $this->systemSettings->findByKey($key);
            $after = $this->systemSettings->upsert(
                $key,
                $value,
                $isPublic,
                $description,
                $actorAccountId,
            );
            $this->record(
                SecurityAction::SYSTEM_SETTING_UPDATED,
                'core.system_setting',
                $after->id,
                $actorAccountId,
                $context,
                $before === null ? null : self::systemAuditData($before),
                self::systemAuditData($after),
            );

            return $after;
        });
    }

    public function deleteSystemSetting(
        string $key,
        string $actorAccountId,
        ClientContext $context,
    ): ?SystemSetting {
        return $this->db->transaction(function () use ($key, $actorAccountId, $context): ?SystemSetting {
            $deleted = $this->systemSettings->delete($key);
            if ($deleted !== null) {
                $this->record(
                    SecurityAction::SYSTEM_SETTING_DELETED,
                    'core.system_setting',
                    $deleted->id,
                    $actorAccountId,
                    $context,
                    self::systemAuditData($deleted),
                    null,
                );
            }

            return $deleted;
        });
    }

    /** @return list<ModuleSetting>|null */
    public function listModuleSettings(string $moduleCode): ?array
    {
        $module = $this->modules->findByCode($moduleCode);
        return $module === null ? null : $this->moduleSettings->findAll($module->id);
    }

    public function findModuleSetting(string $moduleCode, string $key): ?ModuleSetting
    {
        $module = $this->modules->findByCode($moduleCode);
        return $module === null ? null : $this->moduleSettings->find($module->id, $key);
    }

    public function moduleExists(string $moduleCode): bool
    {
        return $this->modules->findByCode($moduleCode) !== null;
    }

    public function putModuleSetting(
        string $moduleCode,
        string $key,
        mixed $value,
        ?string $description,
        string $actorAccountId,
        ClientContext $context,
    ): ?ModuleSetting {
        $this->securityPolicy->assertSafe($key, $value);

        return $this->db->transaction(function () use (
            $moduleCode,
            $key,
            $value,
            $description,
            $actorAccountId,
            $context,
        ): ?ModuleSetting {
            $module = $this->modules->findByCode($moduleCode);
            if ($module === null) {
                return null;
            }
            $before = $this->moduleSettings->find($module->id, $key);
            $after = $this->moduleSettings->upsert(
                $module->id,
                $key,
                $value,
                $description,
                $actorAccountId,
            );
            $this->record(
                SecurityAction::MODULE_SETTING_UPDATED,
                'core.module_setting',
                $after->id,
                $actorAccountId,
                $context,
                $before === null ? null : self::moduleAuditData($before),
                self::moduleAuditData($after),
            );

            return $after;
        });
    }

    public function deleteModuleSetting(
        string $moduleCode,
        string $key,
        string $actorAccountId,
        ClientContext $context,
    ): ?ModuleSetting {
        return $this->db->transaction(function () use (
            $moduleCode,
            $key,
            $actorAccountId,
            $context,
        ): ?ModuleSetting {
            $module = $this->modules->findByCode($moduleCode);
            if ($module === null) {
                return null;
            }
            $deleted = $this->moduleSettings->delete($module->id, $key);
            if ($deleted !== null) {
                $this->record(
                    SecurityAction::MODULE_SETTING_DELETED,
                    'core.module_setting',
                    $deleted->id,
                    $actorAccountId,
                    $context,
                    self::moduleAuditData($deleted),
                    null,
                );
            }

            return $deleted;
        });
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function record(
        string $action,
        string $resourceType,
        string $resourceId,
        string $actorAccountId,
        ClientContext $context,
        ?array $before,
        ?array $after,
    ): void {
        $this->audit->record(new SecurityAuditCommand(
            action: $action,
            actorAccountId: $actorAccountId,
            resourceType: $resourceType,
            resourceId: $resourceId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            beforeData: $before,
            afterData: $after,
        ));
    }

    /** @return array<string, mixed> */
    private static function systemAuditData(SystemSetting $setting): array
    {
        return [
            'key' => $setting->key,
            'isPublic' => $setting->isPublic,
        ];
    }

    /** @return array<string, mixed> */
    private static function moduleAuditData(ModuleSetting $setting): array
    {
        return [
            'moduleCode' => $setting->moduleCode,
            'key' => $setting->key,
        ];
    }
}
