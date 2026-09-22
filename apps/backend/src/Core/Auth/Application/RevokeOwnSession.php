<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAuditService;

final readonly class RevokeOwnSession
{
    public function __construct(
        private SessionRepository $sessions,
        private SecurityAuditService $securityAudit,
    ) {}

    public function execute(
        string $authenticatedAccountId,
        string $sessionId,
        ClientContext $context,
    ): bool {
        $revoked = $this->sessions->revokeForAccount(
            $sessionId,
            $authenticatedAccountId,
            'revoked by account owner',
        );
        if ($revoked) {
            $this->securityAudit->sessionRevoked(
                $authenticatedAccountId,
                $sessionId,
                $context,
            );
        }

        return $revoked;
    }
}
