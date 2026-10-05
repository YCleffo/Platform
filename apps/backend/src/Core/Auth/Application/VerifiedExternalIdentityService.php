<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Shared\Application\ConflictException;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Provider-neutral Core use cases for adapters that already verified identity.
 * No endpoint calls this service in this phase; future adapters are its callers.
 */
final readonly class VerifiedExternalIdentityService
{
    public function __construct(
        private AccountRepository $accounts,
        private AuthProviderRepository $providers,
        private ExternalIdentityRepository $identities,
        private AuthenticationFlowService $authenticationFlow,
        private CredentialMutationGuard $mutationGuard,
        private ExternalIdentityMutationPolicy $mutationPolicy,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    public function findAccountByExternalIdentity(VerifiedExternalIdentity $verified): ?string
    {
        $provider = $this->provider($verified, false);
        return $this->identities->findByProviderSubject($provider->id, $verified->externalUserId)?->accountId;
    }

    public function linkExternalIdentity(string $accountId, VerifiedExternalIdentity $verified, string $sessionId, ClientContext $context): ExternalIdentity
    {
        if ($this->accounts->findById($accountId) === null) {
            throw new ExternalAuthenticationException('Account does not exist.');
        }
        $this->mutationPolicy->requireRecent($accountId, $sessionId, $context);
        $provider = $this->provider($verified, false);

        return $this->db->transaction(function () use ($accountId, $verified, $provider, $context): ExternalIdentity {
            $existing = $this->identities->findByProviderSubject($provider->id, $verified->externalUserId);
            if ($existing !== null) {
                if ($existing->accountId !== $accountId) {
                    throw new ConflictException('External identity is already linked to another account.');
                }
                return $existing;
            }
            if ($this->identities->findByAccountProvider($accountId, $provider->id) !== null) {
                throw new ConflictException('An identity for this provider is already linked to this account.');
            }

            // The existing storage also supports legacy OIDC profile snapshots.
            // Mini-app identities persist only the verified stable subject.
            try {
                $identity = $this->identities->link($accountId, $provider->id,
                    new ExternalIdentityProfile($verified->externalUserId, null, false, null, null, []));
            } catch (ConflictException $exception) {
                // The unique index is authoritative under concurrent linking. A
                // second caller that requested the same owner observes success.
                $identity = $this->identities->findByProviderSubject($provider->id, $verified->externalUserId);
                if ($identity === null || $identity->accountId !== $accountId) {
                    if ($identity === null && $this->identities->findByAccountProvider($accountId, $provider->id) !== null) {
                        throw new ConflictException('An identity for this provider is already linked to this account.', previous: $exception);
                    }
                    throw new ConflictException('External identity is already linked to another account.', previous: $exception);
                }
                return $identity;
            }
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_IDENTITY_LINKED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_external_identity',
                resourceId: $identity->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['provider' => $provider->code],
            ));
            $this->mutationPolicy->notifyLinked($accountId, $context);
            return $identity;
        });
    }

    public function unlinkExternalIdentity(string $accountId, VerifiedExternalIdentity $verified, string $sessionId, ClientContext $context): void
    {
        $this->mutationPolicy->requireRecent($accountId, $sessionId, $context);
        $provider = $this->provider($verified, false);
        $identity = $this->identities->findByProviderSubject($provider->id, $verified->externalUserId);
        if ($identity === null || $identity->accountId !== $accountId) {
            throw new ExternalAuthenticationException('External identity is not linked to this account.');
        }

        $this->db->transaction(function () use ($accountId, $provider, $identity, $context): void {
            try {
                $this->mutationGuard->assertCanRemoveOneMethod($accountId);
            } catch (LastAuthenticationMethodException $exception) {
                throw new ExternalIdentityLastMethodException(
                    'Cannot unlink the last usable authentication method.',
                    $exception->getCode(),
                    $exception,
                );
            }
            if ($this->identities->unlink($accountId, $provider->id) === null) {
                throw new ExternalAuthenticationException('External identity could not be unlinked.');
            }
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_IDENTITY_UNLINKED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_external_identity',
                resourceId: $identity->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['provider' => $provider->code],
            ));
            $this->mutationPolicy->notifyUnlinked($accountId, $context);
        });
    }

    public function authenticate(VerifiedExternalIdentity $verified, ClientContext $context): AuthenticationFlowResult
    {
        $provider = $this->provider($verified, true);
        $identity = $this->identities->findByProviderSubject($provider->id, $verified->externalUserId);
        if ($identity === null) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_LOGIN_FAILED,
                actorAccountId: null,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                success: false,
                errorCode: 'external_identity_not_linked',
                metadata: ['provider' => $provider->code],
            ));
            throw new ExternalAuthenticationException();
        }

        return $this->db->transaction(function () use ($identity, $provider, $context): AuthenticationFlowResult {
            $updated = $this->identities->markUsed($identity->id) ?? $identity;
            $result = $this->authenticationFlow->completeFirstFactor($updated->accountId, 'external', $context);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_LOGIN_SUCCEEDED,
                actorAccountId: $updated->accountId,
                resourceType: 'core.auth_external_identity',
                resourceId: $updated->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['provider' => $provider->code],
            ));
            return $result;
        });
    }

    private function provider(VerifiedExternalIdentity $verified, bool $forLogin): AuthProvider
    {
        $provider = $this->providers->findByCode($verified->provider);
        if ($provider === null || !$provider->enabled || ($forLogin && !$provider->allowLogin)) {
            throw new ExternalAuthenticationException('External provider is unavailable.');
        }
        return $provider;
    }
}
