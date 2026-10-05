<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;

/** Shared pre/post policy for every external-identity credential mutation. */
final readonly class ExternalIdentityMutationPolicy
{
    public function __construct(
        private RecentAuthenticationPolicy $recent,
        private RateLimiterInterface $limits,
        private AuthRateLimitPolicy $ratePolicy,
        private IdentifierDigestService $digests,
        private AccountSecurityNotifier $notifications,
    ) {}

    public function requireRecent(string $accountId, string $sessionId, ClientContext $context): void
    {
        $this->recent->require($accountId, $sessionId);
        if (!$this->limits->consume(
            'external:identity-mutation',
            $this->digests->digest($accountId . '|' . ($context->ipAddress ?? 'unknown')),
            $this->ratePolicy->providerLinkLimit,
            $this->ratePolicy->securityWindowSeconds,
        )) {
            throw new LoginRateLimitedException();
        }
    }

    public function notifyLinked(string $accountId, ClientContext $context): void
    {
        $this->notifications->notify($accountId, 'auth.external_identity.linked', $context->locale->locale->tag);
    }

    public function notifyUnlinked(string $accountId, ClientContext $context): void
    {
        $this->notifications->notify($accountId, 'auth.external_identity.unlinked', $context->locale->locale->tag);
    }
}
