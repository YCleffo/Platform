<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
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
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MfaRecoveryService
{
    public function __construct(
        private IdentifierRepository $identifiers,
        private AuthIdentifierNormalizer $normalizer,
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private PasswordCredentialRepository $passwords,
        private PasswordHasherInterface $passwordHasher,
        private AuthChallengeRepository $challenges,
        private OpaqueTokenService $tokens,
        private AuthRuntimePolicy $runtimePolicy,
        private AuthRateLimitPolicy $ratePolicy,
        private RateLimiterInterface $rateLimiter,
        private IdentifierDigestService $digests,
        private AuthLinkBuilder $links,
        private EmailService $email,
        private AccountSecurityNotifier $notifications,
        private AccountSecurityRepository $accounts,
        private SessionRepository $sessions,
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

    public function confirm(string $rawToken, ?string $password, ClientContext $context): void
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
        if ($credential !== null
            && ($password === null || !$this->passwordHasher->verify($password, $credential->passwordHash))
        ) {
            $this->fail($challenge, $context);
        }

        try {
            $this->db->transaction(function () use ($challenge, $context): void {
                if (!$this->challenges->consume($challenge->id)) {
                    throw new MfaVerificationException();
                }
                $this->factors->revokeAllFactors($challenge->accountId);
                $this->recoveryCodes->revokeAllCodes($challenge->accountId);
                $this->accounts->rotateSecurityStamp($challenge->accountId)
                    ?? throw new RuntimeException('Account security stamp could not be rotated.');
                $this->sessions->revokeAll($challenge->accountId, 'mfa_recovery');
                $this->challenges->cancelAllForAccount($challenge->accountId, $challenge->id);
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::MFA_RECOVERY_COMPLETED,
                    actorAccountId: $challenge->accountId,
                    resourceType: 'core.account',
                    resourceId: $challenge->accountId,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['severity' => 'high'],
                ));
                $this->notifications->notify(
                    $challenge->accountId,
                    'auth.mfa.recovery_completed',
                    $context->locale->locale->tag,
                );
            });
        } catch (MfaVerificationException) {
            $this->fail($challenge, $context);
        }
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
