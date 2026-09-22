<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Shared\Application\ConflictException;
use App\Core\Time\Application\ClockInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ExternalAuthService
{
    public function __construct(
        private AuthProviderRepository $providers,
        private ExternalIdentityRepository $identities,
        private PasswordCredentialRepository $passwords,
        private PasskeyRepository $passkeys,
        private AuthChallengeRepository $challenges,
        private OidcClientRegistryInterface $clients,
        private AuthenticationPayloadCipher $cipher,
        private OpaqueTokenService $tokens,
        private AuthenticationFlowService $authenticationFlow,
        private RecentAuthenticationPolicy $recentAuthentication,
        private AccountSecurityNotifier $notifications,
        private RateLimiterInterface $rateLimiter,
        private AuthRateLimitPolicy $ratePolicy,
        private IdentifierDigestService $digests,
        private AuthRuntimePolicy $runtimePolicy,
        private SecurityAuditService $audit,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {}

    /** @return list<array{code: string, displayName: string, loginEnabled: bool}> */
    public function publicProviders(): array
    {
        return array_map(
            static fn(AuthProvider $provider): array => [
                'code' => $provider->code,
                'displayName' => $provider->displayName,
                'loginEnabled' => $provider->allowLogin,
            ],
            $this->providers->findEnabled(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function identities(string $accountId): array
    {
        return array_map(
            static fn(ExternalIdentity $identity): array => $identity->toSafeArray(),
            $this->identities->findByAccount($accountId),
        );
    }

    public function startLogin(string $providerCode, ClientContext $context): ExternalAuthorizationStart
    {
        $this->consumeStartLimit($context, 'external:start');
        $provider = $this->availableProvider($providerCode, true);
        return $this->start($provider, 'external_login', null, null, $context);
    }

    public function completeLogin(
        string $providerCode,
        string $state,
        string $authorizationCode,
        ClientContext $context,
    ): AuthenticationFlowResult {
        $this->consumeCallbackLimit($state, $context);
        $provider = $this->availableProvider($providerCode, true);
        $challenge = $this->transaction($state, 'external_login', $providerCode, null, null, $context);
        try {
            $sensitive = $this->sensitive($challenge);
            $profile = $this->clients->get($providerCode)->exchangeAuthorizationCode(
                $authorizationCode,
                $sensitive['nonce'],
                $sensitive['codeVerifier'],
            );
            $identity = $this->identities->findByProviderSubject($provider->id, $profile->subject);
            if ($identity === null) {
                throw new ExternalAuthenticationException();
            }

            return $this->db->transaction(function () use ($challenge, $identity, $profile, $providerCode, $context): AuthenticationFlowResult {
                if (!$this->challenges->consume($challenge->id)) {
                    throw new ExternalAuthenticationException();
                }
                $updated = $this->identities->updateSnapshotAndLogin($identity->id, $profile)
                    ?? throw new ExternalAuthenticationException();
                $result = $this->authenticationFlow->completeFirstFactor(
                    $updated->accountId,
                    AuthenticationMethod::firstFactor($providerCode),
                    $context,
                );
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::EXTERNAL_LOGIN_SUCCEEDED,
                    actorAccountId: $updated->accountId,
                    resourceType: 'core.auth_external_identity',
                    resourceId: $updated->id,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['provider' => $providerCode],
                ));
                return $result;
            });
        } catch (Throwable $exception) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_LOGIN_FAILED,
                actorAccountId: null,
                resourceType: 'core.auth_challenge',
                resourceId: $challenge->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                success: false,
                errorCode: 'external_verification_failed',
                metadata: ['provider' => $providerCode],
            ));
            throw $exception instanceof ExternalAuthenticationException
                ? $exception
                : new ExternalAuthenticationException(previous: $exception);
        }
    }

    public function startLink(
        string $providerCode,
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): ExternalAuthorizationStart {
        $this->recentAuthentication->require($accountId, $sessionId);
        $this->consumeStartLimit($context, 'external:link', $accountId);
        $provider = $this->availableProvider($providerCode, false);
        if ($this->identities->findByAccountProvider($accountId, $provider->id) !== null) {
            throw new ConflictException('Provider is already linked to this account.');
        }
        return $this->start($provider, 'external_link', $accountId, $sessionId, $context);
    }

    public function completeLink(
        string $providerCode,
        string $state,
        string $authorizationCode,
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): ExternalIdentity {
        $this->recentAuthentication->require($accountId, $sessionId);
        $this->consumeCallbackLimit($state, $context);
        $provider = $this->availableProvider($providerCode, false);
        $challenge = $this->transaction(
            $state,
            'external_link',
            $providerCode,
            $accountId,
            $sessionId,
            $context,
        );
        try {
            $sensitive = $this->sensitive($challenge);
            $profile = $this->clients->get($providerCode)->exchangeAuthorizationCode(
                $authorizationCode,
                $sensitive['nonce'],
                $sensitive['codeVerifier'],
            );
            return $this->db->transaction(function () use ($challenge, $accountId, $provider, $profile, $providerCode, $context): ExternalIdentity {
                if (!$this->challenges->consume($challenge->id)) {
                    throw new ExternalAuthenticationException();
                }
                $identity = $this->identities->link($accountId, $provider->id, $profile);
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::EXTERNAL_IDENTITY_LINKED,
                    actorAccountId: $accountId,
                    resourceType: 'core.auth_external_identity',
                    resourceId: $identity->id,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['provider' => $providerCode],
                ));
                $this->notifications->notify($accountId, 'auth.external_identity.linked', $context->locale->locale->tag);
                return $identity;
            });
        } catch (ConflictException $exception) {
            $this->challenges->registerFailedAttempt($challenge->id);
            throw $exception;
        } catch (Throwable $exception) {
            $this->challenges->registerFailedAttempt($challenge->id);
            throw $exception instanceof ExternalAuthenticationException
                ? $exception
                : new ExternalAuthenticationException(previous: $exception);
        }
    }

    public function unlink(
        string $providerCode,
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): void {
        $this->recentAuthentication->require($accountId, $sessionId);
        $provider = $this->providers->findByCode($providerCode);
        $identity = $provider === null ? null : $this->identities->findByAccountProvider($accountId, $provider->id);
        if ($provider === null || $identity === null) {
            throw new ExternalAuthenticationException();
        }
        $remaining = $this->identities->countByAccount($accountId) - 1;
        if ($this->passwords->findByAccountId($accountId) !== null) {
            ++$remaining;
        }
        $remaining += count($this->passkeys->findActivePasskeysByAccountId($accountId));
        if ($remaining < 1) {
            throw new ExternalIdentityLastMethodException();
        }
        $this->db->transaction(function () use ($accountId, $provider, $identity, $providerCode, $context): void {
            if ($this->identities->unlink($accountId, $provider->id) === null) {
                throw new ExternalAuthenticationException();
            }
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::EXTERNAL_IDENTITY_UNLINKED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_external_identity',
                resourceId: $identity->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['provider' => $providerCode],
            ));
            $this->notifications->notify($accountId, 'auth.external_identity.unlinked', $context->locale->locale->tag);
        });
    }

    private function start(
        AuthProvider $provider,
        string $type,
        ?string $accountId,
        ?string $sessionId,
        ClientContext $context,
    ): ExternalAuthorizationStart {
        $state = $this->tokens->issue();
        $nonce = self::randomBase64Url(32);
        $verifier = self::randomBase64Url(64);
        $challengeValue = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $sealed = $this->cipher->encrypt(json_encode([
            'nonce' => $nonce,
            'codeVerifier' => $verifier,
        ], JSON_THROW_ON_ERROR));
        $expiresAt = $this->clock->now()->modify(sprintf(
            '+%d seconds',
            $this->runtimePolicy->mfaChallengeTtlSeconds,
        ))->format('Y-m-d\TH:i:s\Z');
        $authorizationUrl = $this->clients->get($provider->code)->authorizationUrl(
            $state->raw,
            $nonce,
            $challengeValue,
        );
        $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
            accountId: $accountId,
            challengeType: $type,
            secretHashHex: $state->hashHex,
            targetNormalized: null,
            payload: [
                'purpose' => $type,
                'provider' => $provider->code,
                'sessionId' => $sessionId,
                'clientBinding' => AuthenticationFlowService::clientBinding($context),
                'sealed' => $sealed->ciphertextHex,
                'keyVersion' => $sealed->keyVersion,
            ],
            maxAttempts: 5,
            expiresAt: $expiresAt,
        ));

        return new ExternalAuthorizationStart($authorizationUrl, $expiresAt);
    }

    private function availableProvider(string $code, bool $forLogin): AuthProvider
    {
        if (!in_array($code, [AuthenticationMethod::GOOGLE, AuthenticationMethod::TELEGRAM], true)) {
            throw new ExternalAuthenticationException();
        }
        $provider = $this->providers->findByCode($code);
        if ($provider === null || !$provider->enabled || $provider->protocol !== 'oidc' || ($forLogin && !$provider->allowLogin)) {
            throw new ExternalAuthenticationException();
        }
        return $provider;
    }

    private function transaction(
        string $state,
        string $type,
        string $provider,
        ?string $accountId,
        ?string $sessionId,
        ClientContext $context,
    ): AuthChallenge {
        if (!$this->tokens->hasValidFormat($state)) {
            throw new ExternalAuthenticationException();
        }
        $challenge = $this->challenges->findActiveBySecretHashHex($this->tokens->hashHex($state));
        if ($challenge === null || $challenge->challengeType !== $type
            || ($challenge->payload['purpose'] ?? null) !== $type
            || ($challenge->payload['provider'] ?? null) !== $provider
            || ($challenge->payload['clientBinding'] ?? null) !== AuthenticationFlowService::clientBinding($context)
            || $challenge->accountId !== $accountId
            || ($challenge->payload['sessionId'] ?? null) !== $sessionId
        ) {
            throw new ExternalAuthenticationException();
        }
        return $challenge;
    }

    /** @return array{nonce: string, codeVerifier: string} */
    private function sensitive(AuthChallenge $challenge): array
    {
        $sealed = $challenge->payload['sealed'] ?? null;
        $version = $challenge->payload['keyVersion'] ?? null;
        if (!is_string($sealed) || !is_int($version)) {
            throw new ExternalAuthenticationException();
        }
        $data = json_decode($this->cipher->decrypt($sealed, $version), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['nonce'] ?? null) || !is_string($data['codeVerifier'] ?? null)) {
            throw new ExternalAuthenticationException();
        }
        /** @var string $nonce */
        $nonce = $data['nonce'];
        /** @var string $codeVerifier */
        $codeVerifier = $data['codeVerifier'];
        return ['nonce' => $nonce, 'codeVerifier' => $codeVerifier];
    }

    private function consumeStartLimit(ClientContext $context, string $bucket, ?string $accountId = null): void
    {
        $key = $accountId ?? ($context->ipAddress ?? 'unknown');
        $limit = $accountId === null ? $this->ratePolicy->externalStartIpLimit : $this->ratePolicy->providerLinkLimit;
        if (!$this->rateLimiter->consume(
            $bucket,
            $this->digests->digest($key),
            $limit,
            $this->ratePolicy->securityWindowSeconds,
        )) {
            throw new LoginRateLimitedException();
        }
    }

    private function consumeCallbackLimit(string $state, ClientContext $context): void
    {
        $key = $this->digests->digest($state . '|' . ($context->ipAddress ?? 'unknown'));
        if (!$this->rateLimiter->consume(
            'external:callback',
            $key,
            $this->ratePolicy->externalCallbackLimit,
            $this->ratePolicy->securityWindowSeconds,
        )) {
            throw new LoginRateLimitedException();
        }
    }

    /** @param int<1, max> $bytes */
    private static function randomBase64Url(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
