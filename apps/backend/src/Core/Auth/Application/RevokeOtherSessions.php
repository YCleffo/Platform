<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAuditService;

final readonly class RevokeOtherSessions
{
    public function __construct(
        private SessionRepository $sessions,
        private SecurityAuditService $securityAudit,
    ) {}

    public function execute(
        string $authenticatedAccountId,
        string $currentSessionId,
        ClientContext $context,
    ): int {
        $revoked = $this->sessions->revokeOthers(
            $authenticatedAccountId,
            $currentSessionId,
            'revoked other sessions by account owner',
        );
        if ($revoked > 0) {
            $this->securityAudit->sessionsRevokedAll(
                $authenticatedAccountId,
                $currentSessionId,
                $revoked,
                $context,
            );
        }

        return $revoked;
    }
}
