<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAuditService;

final readonly class Logout
{
    public function __construct(
        private SessionRepository $sessions,
        private SecurityAuditService $securityAudit,
    ) {}

    public function execute(AuthenticatedIdentity $identity, ClientContext $context): void
    {
        if ($this->sessions->revokeForAccount(
            $identity->sessionId,
            $identity->accountId,
            'logout',
        )) {
            $this->securityAudit->sessionRevoked(
                $identity->accountId,
                $identity->sessionId,
                $context,
            );
        }
    }
}
