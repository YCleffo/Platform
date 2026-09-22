<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\LocaleResolver;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;

final readonly class AuthenticationService
{
    public function __construct(
        private SessionRepository $sessions,
        private AccountSecurityRepository $accounts,
        private PermissionService $permissions,
        private SessionActivityService $activity,
        private OpaqueTokenService $tokens,
        private ClockInterface $clock,
        private LocaleResolver $localeResolver,
    ) {}

    public function authenticate(string $rawAccessToken, ClientContext $context): ?AuthenticatedIdentity
    {
        if (!$this->tokens->hasValidFormat($rawAccessToken)) {
            return null;
        }

        $session = $this->sessions->findByTokenHashHex($this->tokens->hashHex($rawAccessToken));
        $now = $this->clock->now();
        if ($session === null || !$this->sessionAllowsAuthentication($session, $now)) {
            return null;
        }

        $account = $this->accounts->findSecurityState($session->accountId);
        if ($account === null
            || !$account->allowsAuthentication($now)
            || !hash_equals($account->securityStamp, $session->securityStamp)
        ) {
            return null;
        }

        $localizedContext = $context->withLocale(LocaleContext::fromResolved(
            $this->localeResolver->resolveAuthenticated(
                $context->locale,
                $account->locale,
                $context->acceptLanguage,
            ),
        ));
        $activity = $this->activity->touch($session->id, $account->accountId, $localizedContext);
        if ($activity === null) {
            return null;
        }

        return new AuthenticatedIdentity(
            accountId: $account->accountId,
            personId: $account->personId,
            sessionId: $session->id,
            roles: array_map(
                static fn(object $role): string => $role->code,
                $this->permissions->getRoles($account->accountId),
            ),
            permissions: array_map(
                static fn(object $permission): string => $permission->code,
                $this->permissions->getPermissions($account->accountId),
            ),
            locale: $account->locale,
            timezone: $account->timezone,
        );
    }

    private function sessionAllowsAuthentication(AuthSession $session, DateTimeImmutable $now): bool
    {
        return $session->revokedAt === null
            && new DateTimeImmutable($session->accessExpiresAt) > $now
            && new DateTimeImmutable($session->expiresAt) > $now;
    }
}
