<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Storage\Application\Mutation;
use App\Storage\Application\StorageScope;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class StorageMutation implements Mutation
{
    public function __construct(private ConnectionInterface $db, private AuditWriter $audit) {}

    public function run(StorageScope $scope, string $operation, callable $action): array
    {
        return $this->db->transaction(function () use ($scope, $operation, $action): array {
            $result = $action();
            $this->audit->write(new WriteAuditEventCommand(
                actorType: 'account',
                actorAccountId: $scope->accountId,
                action: 'storage.' . $operation,
                resourceType: 'storage.space',
                resourceId: $scope->studentId,
                metadata: ['organizationId' => $scope->organizationId],
            ));
            return $result;
        });
    }
}
