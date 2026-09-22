<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Communication\Application\EmailService;
use App\Core\Communication\Application\QueueEmailCommand;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MfaRecoveryService
{
    public function __construct(
        private IdentifierRepository $identifiers,
        private AuthIdentifierNormalizer $normalizer,
        private TotpFactorRepository $factors,
        private PasswordCredentialRepository $passwords,
        private PasswordHasherInterface $passwordHasher,
        private AuthProviderRepository $providers,
        private ExternalIdentityRepository $identities,
        private AuthChallengeRepository $challenges,
        private OpaqueTokenService $tokens,
        private MfaRecoveryFinalizer $finalizer,
        private AuthRuntimePolicy $runtimePolicy,
        private AuthRateLimitPolicy $ratePolicy,
        private RateLimiterInterface $rateLimiter,
        private IdentifierDigestService $digests,
        private AuthLinkBuilder $links,
        private EmailService $email,
        private SecurityAuditService $audit,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {}

    public function request(string $email, ClientContext $context): void
    {
        try {
            $normalized = $this->normalizer->normalize('email', $email);
        } catch (InvalidArgumentException) {
            $normalized = mb_strtolower(trim($email), 'UTF-8');
        }
        $allowed = $this->rateLimiter->consume(
            'mfa-recovery:identifier',
            $this->digests->digest($normalized),
            $this->ratePolicy->mfaRecoveryIdentifierLimit,
            $this->ratePolicy->securityWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'mfa-recovery:ip',
            $this->digests->digest($context->ipAddress ?? 'unknown'),
            $this->ratePolicy->mfaRecoveryIpLimit,
            $this->ratePolicy->securityWindowSeconds,
        ) && $allowed;
        if (!$allowed) {
            throw new LoginRateLimitedException();
        }

        $identifier = $this->identifiers->findByNormalized('email', $normalized);
        if ($identifier === null
            || $identifier->verifiedAt === null
            || !$identifier->canRecover
            || $this->factors->findActive($identifier->accountId) === null
        ) {
            return;
        }
        $this->db->transaction(function () use ($identifier, $context): void {
            $token = $this->tokens->issue();
            $challenge = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $identifier->accountId,
                challengeType: 'mfa_recovery',
                secretHashHex: $token->hashHex,
                targetNormalized: $identifier->normalizedValue,
                payload: ['purpose' => 'mfa_recovery'],
                maxAttempts: 5,
                expiresAt: $this->clock->now()->modify(sprintf(
                    '+%d seconds',
                    $this->runtimePolicy->mfaRecoveryTtlSeconds,
                ))->format('Y-m-d\TH:i:s\Z'),
            ));
            $this->email->queue(new QueueEmailCommand(
                producerModule: 'core.auth',
                recipientAddress: $identifier->value,
                recipientName: null,
                locale: $context->locale->locale->tag,
                templateCode: 'auth.mfa.recovery_requested',
                variables: ['actionUrl' => $this->links->mfaRecovery($token->raw)],
                aggregateType: 'core.auth_challenge',
                aggregateId: $challenge->id,
            ));
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::MFA_RECOVERY_REQUESTED,
                actorAccountId: $identifier->accountId,
                resourceType: 'core.auth_challenge',
                resourceId: $challenge->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
        });
    }

    public function confirm(
        string $rawToken,
        ?string $password,
        ClientContext $context,
    ): MfaRecoveryConfirmationResult
    {
        if (!$this->tokens->hasValidFormat($rawToken)) {
            throw new MfaVerificationException();
        }
        $challenge = $this->challenges->findActiveBySecretHashHex($this->tokens->hashHex($rawToken));
        $limitKey = $this->digests->digest($challenge?->id ?? $rawToken);
        if (!$this->rateLimiter->consume(
            'mfa-recovery:confirm',
            $limitKey,
            $this->ratePolicy->mfaRecoveryConfirmLimit,
            $this->ratePolicy->securityWindowSeconds,
        ) || $challenge === null || $challenge->challengeType !== 'mfa_recovery' || $challenge->accountId === null) {
            throw new MfaVerificationException();
        }
        $credential = $this->passwords->findByAccountId($challenge->accountId);
        if ($credential !== null) {
            if ($password === null || !$this->passwordHasher->verify($password, $credential->passwordHash)) {
                $this->fail($challenge, $context);
            }
            try {
                $this->finalizer->complete($challenge, $context, AuthenticationMethod::PASSWORD);
            } catch (MfaVerificationException) {
                $this->fail($challenge, $context);
            }
            return MfaRecoveryConfirmationResult::completed();
        }

        $providers = $this->usableExternalProviders($challenge->accountId);
        try {
            return $this->db->transaction(function () use ($challenge, $providers, $context): MfaRecoveryConfirmationResult {
                if (!$this->challenges->consume($challenge->id)) {
                    throw new MfaVerificationException();
                }
                if ($providers === []) {
                    $this->audit->record(new SecurityAuditCommand(
                        action: SecurityAction::MFA_RECOVERY_EXTERNAL_REAUTH_FAILED,
                        actorAccountId: $challenge->accountId,
                        resourceType: 'core.auth_challenge',
                        resourceId: $challenge->id,
                        requestId: $context->requestId,
                        ipAddress: $context->ipAddress,
                        userAgent: $context->userAgent,
                        success: false,
                        errorCode: 'additional_recovery_required',
                    ));
                    return MfaRecoveryConfirmationResult::additionalRecoveryRequired();
                }

                $proof = $this->tokens->issue();
                $expiresAt = $this->clock->now()->modify(sprintf(
                    '+%d seconds',
                    $this->runtimePolicy->mfaRecoveryTtlSeconds,
                ))->format('Y-m-d\TH:i:s\Z');
                $reauth = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                    accountId: $challenge->accountId,
                    challengeType: 'mfa_recovery_reauth_pending',
                    secretHashHex: $proof->hashHex,
                    targetNormalized: null,
                    payload: [
                        'purpose' => 'mfa_recovery_reauth_pending',
                        'originChallengeId' => $challenge->id,
                        'providers' => $providers,
                        'clientBinding' => AuthenticationFlowService::clientBinding($context),
                    ],
                    maxAttempts: 3,
                    expiresAt: $expiresAt,
                ));
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::MFA_RECOVERY_EXTERNAL_REAUTH_REQUIRED,
                    actorAccountId: $challenge->accountId,
                    resourceType: 'core.auth_challenge',
                    resourceId: $reauth->id,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['providers' => $providers],
                ));

                /** @var non-empty-list<string> $providers */
                return MfaRecoveryConfirmationResult::externalReauthenticationRequired(
                    $proof->raw,
                    $providers,
                    $expiresAt,
                );
            });
        } catch (MfaVerificationException) {
            $this->fail($challenge, $context);
        }
    }

    /** @return list<string> */
    private function usableExternalProviders(string $accountId): array
    {
        $result = [];
        foreach ([AuthenticationMethod::GOOGLE, AuthenticationMethod::TELEGRAM] as $code) {
            $provider = $this->providers->findByCode($code);
            if ($provider !== null
                && $provider->enabled
                && $provider->allowLogin
                && $provider->protocol === 'oidc'
                && $this->identities->findByAccountProvider($accountId, $provider->id) !== null
            ) {
                $result[] = $code;
            }
        }
        return $result;
    }

    private function fail(AuthChallenge $challenge, ClientContext $context): never
    {
        $this->challenges->registerFailedAttempt($challenge->id);
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::MFA_RECOVERY_FAILED,
            actorAccountId: $challenge->accountId,
            resourceType: 'core.auth_challenge',
            resourceId: $challenge->id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            success: false,
            errorCode: 'verification_failed',
        ));
        throw new MfaVerificationException();
    }
}
