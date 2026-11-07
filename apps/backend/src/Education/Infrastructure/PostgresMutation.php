<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Education\Application\Mutation;
use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresMutation implements Mutation
{
    public function __construct(private ConnectionInterface $db, private AuditWriter $audit) {}

    public function run(Scope $scope, string $operation, callable $action): array
    {
        return $this->db->transaction(function () use ($scope, $operation, $action): array {
            $result = $action();
            $auditCommand = new WriteAuditEventCommand(
                actorType: 'account',
                actorAccountId: $scope->accountId,
                action: 'education.' . $operation,
                resourceType: 'education.workspace',
                resourceId: isset($result['id']) && is_string($result['id']) ? $result['id'] : $scope->organizationId,
                metadata: ['organizationId' => $scope->organizationId],
            );
            try {
                $this->audit->write($auditCommand);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, 'AUDIT-FAIL: ' . $operation . ' cmd=' . var_export([
                    'action' => $auditCommand->action, 'resourceType' => $auditCommand->resourceType,
                    'resourceId' => $auditCommand->resourceId, 'metadata' => $auditCommand->metadata,
                    'before' => $auditCommand->beforeData, 'after' => $auditCommand->afterData,
                ], true) . ' msg=' . $e->getMessage() . PHP_EOL);
                throw $e;
            }
            return $result;
        });
    }
}
