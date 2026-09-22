<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ManageAccountLifecycle
{
    public function __construct(
        private AccountLifecycleRepository $accounts,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    public function execute(
        string $operation,
        string $accountId,
        string $actorAccountId,
        ClientContext $context,
    ): ?AccountLifecycleChange {
        return $this->db->transaction(function () use (
            $operation,
            $accountId,
            $actorAccountId,
            $context,
        ): ?AccountLifecycleChange {
            $change = match ($operation) {
                'approve' => $this->accounts->approve($accountId, $actorAccountId),
                'disable' => $this->accounts->disable($accountId),
                'enable' => $this->accounts->enable($accountId),
                'archive' => $this->accounts->archive($accountId),
                default => throw new \InvalidArgumentException('Unsupported account lifecycle operation.'),
            };
            if ($change !== null) {
                $this->audit->record(new SecurityAuditCommand(
                    action: match ($operation) {
                        'approve' => SecurityAction::ACCOUNT_APPROVED,
                        'disable' => SecurityAction::ACCOUNT_DISABLED,
                        'enable' => SecurityAction::ACCOUNT_ENABLED,
                        'archive' => SecurityAction::ACCOUNT_ARCHIVED,
                    },
                    actorAccountId: $actorAccountId,
                    resourceType: 'core.account',
                    resourceId: $accountId,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    beforeData: ['status' => $change->beforeStatus],
                    afterData: ['status' => $change->afterStatus],
                    metadata: ['revokedSessions' => $change->revokedSessions],
                ));
            }

            return $change;
        });
    }
}
