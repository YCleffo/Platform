<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\DeviceManagement\Application\Mutation;
use App\DeviceManagement\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DeviceMutation implements Mutation
{
    public function __construct(private ConnectionInterface $db, private AuditWriter $audit) {}

    public function run(Scope $scope, string $operation, callable $action): array
    {
        return $this->db->transaction(function () use ($scope, $operation, $action): array {
            $result = $action();
            $this->audit->write(new WriteAuditEventCommand(
                actorType: 'account',
                actorAccountId: $scope->accountId,
                action: 'device_management.' . $operation,
                resourceType: 'device_management.device',
                resourceId: isset($result['id']) && is_string($result['id']) ? $result['id'] : $scope->organizationId,
                metadata: ['organizationId' => $scope->organizationId],
            ));
            return $result;
        });
    }
}
