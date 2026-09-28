<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class AccountSettingsService
{
    public function __construct(
        private AccountSettingsRepository $repository,
        private SettingsSchemaRegistry $registry,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    public function get(string $accountId): AccountSettings
    {
        $stored = $this->repository->find($accountId);
        /** @var array<string, mixed> $storedSettings */
        $storedSettings = $stored?->settings ?? [];
        return new AccountSettings(
            $this->registry->effective($storedSettings, $stored?->schemaVersion ?? $this->registry->schemaVersion()),
            $this->registry->schemaVersion(),
        );
    }

    /** @param array<string, mixed> $patch */
    public function patch(string $accountId, array $patch, ClientContext $context): AccountSettings
    {
        $patch = $this->registry->validatePatch($patch);
        return $this->db->transaction(function () use ($accountId, $patch, $context): AccountSettings {
            $stored = $this->repository->findForUpdate($accountId);
            /** @var array<string, mixed> $storedSettings */
            $storedSettings = $stored?->settings ?? [];
            $before = $this->registry->upgradeStored(
                $storedSettings,
                $stored?->schemaVersion ?? $this->registry->schemaVersion(),
            );
            $after = $this->registry->applyPatch($before, $patch);
            if ($after !== $before || ($stored !== null && $stored->schemaVersion !== $this->registry->schemaVersion())) {
                $this->repository->save($accountId, $after, $this->registry->schemaVersion());
                $changedPaths = self::auditChangedPaths($before, $after);
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::ACCOUNT_SETTINGS_UPDATED,
                    actorAccountId: $accountId,
                    resourceType: 'core.account_settings',
                    resourceId: $accountId,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['changedPaths' => $changedPaths],
                ));
            }
            return new AccountSettings(
                $this->registry->effective($after),
                $this->registry->schemaVersion(),
            );
        });
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private static function auditChangedPaths(array $before, array $after): array
    {
        $beforeFlat = SettingsSchemaRegistry::flatten($before);
        $afterFlat = SettingsSchemaRegistry::flatten($after);
        $paths = [];
        foreach (array_unique([...array_keys($beforeFlat), ...array_keys($afterFlat)]) as $path) {
            $old = $beforeFlat[$path] ?? null;
            $new = $afterFlat[$path] ?? null;
            if ($old !== $new) {
                $paths[] = $path;
            }
        }
        return $paths;
    }
}
