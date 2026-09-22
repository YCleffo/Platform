<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;

final readonly class RecentAuthenticationPolicy
{
    public function __construct(
        private SessionRepository $sessions,
        private AuthRuntimePolicy $policy,
        private ClockInterface $clock,
    ) {}

    public function require(string $accountId, string $sessionId, bool $requireMfa = false): AuthSession
    {
        $session = $this->sessions->findForAccount($sessionId, $accountId);
        if ($session === null || $session->revokedAt !== null) {
            throw new RecentAuthenticationRequiredException();
        }
        $authenticatedAt = $requireMfa ? $session->mfaVerifiedAt : $session->createdAt;
        if ($authenticatedAt === null
            || new DateTimeImmutable($authenticatedAt) < $this->clock->now()->modify(
                sprintf('-%d seconds', $this->policy->recentAuthenticationSeconds),
            )
        ) {
            throw new RecentAuthenticationRequiredException();
        }

        return $session;
    }
}
