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
                actorAccountId: $scope->actor(),
                action: 'education.' . $operation,
                resourceType: 'education.workspace',
                resourceId: isset($result['id']) && is_string($result['id']) ? $result['id'] : $scope->organizationId,
                metadata: ['organizationId' => $scope->organizationId],
            );
            $this->audit->write($auditCommand);
            return $result;
        });
    }
}
