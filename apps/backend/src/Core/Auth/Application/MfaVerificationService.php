<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MfaVerificationService
{
    public function __construct(
        private AuthChallengeRepository $challenges,
        private TotpFactorRepository $totpFactors,
        private RecoveryCodeRepository $recoveryCodes,
        private TotpSecretCipher $cipher,
        private TotpService $totp,
        private RecoveryCodeService $recovery,
        private OpaqueTokenService $tokens,
        private SessionIssuer $sessions,
        private RateLimiterInterface $rateLimiter,
        private AuthRateLimitPolicy $ratePolicy,
        private IdentifierDigestService $digests,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
        private PasswordCredentialRepository $passwords,
    ) {}

    public function verify(
        string $rawChallengeToken,
        ?string $totpCode,
        ?string $recoveryCode,
        ClientContext $context,
    ): AuthenticationResult {
        $challenge = $this->activeChallenge($rawChallengeToken);
        $challengeKey = $this->digests->digest($challenge?->id ?? $rawChallengeToken);
        $ipKey = $this->digests->digest($context->ipAddress ?? 'unknown');
        $allowed = $this->rateLimiter->consume(
            'mfa:challenge',
            $challengeKey,
            $this->ratePolicy->mfaVerifyChallengeLimit,
            $this->ratePolicy->securityWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'mfa:ip',
            $ipKey,
            $this->ratePolicy->mfaVerifyIpLimit,
            $this->ratePolicy->securityWindowSeconds,
        ) && $allowed;
        if (!$allowed || $challenge === null || $challenge->accountId === null) {
            throw new MfaVerificationException();
        }
        if (($challenge->payload['purpose'] ?? null) !== 'login'
            || ($challenge->payload['clientBinding'] ?? null) !== AuthenticationFlowService::clientBinding($context)
        ) {
            $this->fail($challenge, $context);
        }
        $firstFactor = $challenge->payload['firstFactor'] ?? null;
        if (!is_string($firstFactor)) {
            $this->fail($challenge, $context);
        }

        try {
            return $this->db->transaction(function () use (
                $challenge,
                $firstFactor,
                $totpCode,
                $recoveryCode,
                $context,
            ): AuthenticationResult {
                $secondFactor = null;
                if ($totpCode !== null && $recoveryCode === null) {
                    $factor = $this->totpFactors->findActive($challenge->accountId);
                    $counter = $factor === null ? null : $this->totp->matchingCounter(
                        $this->cipher->decrypt($factor->secretEncryptedHex, $factor->secretKeyVersion),
                        $totpCode,
                        $factor->algorithm,
                        $factor->digits,
                        $factor->periodSeconds,
                    );
                    if ($factor === null || $counter === null || !$this->totpFactors->acceptCounter($factor->id, $counter)) {
                        throw new MfaVerificationException();
                    }
                    $secondFactor = 'totp';
                } elseif ($recoveryCode !== null && $totpCode === null) {
                    if (!$this->rateLimiter->consume(
                        'mfa:recovery-code',
                        $this->digests->digest($challenge->accountId),
                        $this->ratePolicy->recoveryCodeLimit,
                        $this->ratePolicy->securityWindowSeconds,
                    )) {
                        throw new MfaVerificationException();
                    }
                    $consumed = $this->recoveryCodes->consumeCode(
                        $challenge->accountId,
                        $this->recovery->hashHex($recoveryCode),
                    );
                    if ($consumed === null) {
                        throw new MfaVerificationException();
                    }
                    $secondFactor = 'recovery_code';
                    $this->audit->record(new SecurityAuditCommand(
                        action: SecurityAction::RECOVERY_CODE_USED,
                        actorAccountId: $challenge->accountId,
                        resourceType: 'core.auth_recovery_code',
                        resourceId: $consumed->id,
                        requestId: $context->requestId,
                        ipAddress: $context->ipAddress,
                        userAgent: $context->userAgent,
                    ));
                } else {
                    throw new MfaVerificationException();
                }

                if (!$this->challenges->consume($challenge->id)) {
                    throw new MfaVerificationException();
                }
                if ($this->passwords->findByAccountId($challenge->accountId)?->mustChangePassword === true) {
                    throw new MfaVerificationException();
                }
                $method = AuthenticationMethod::withSecondFactor($firstFactor, $secondFactor);
                $result = $this->sessions->issueWithinTransaction(
                    $challenge->accountId,
                    $method,
                    $context,
                    true,
                );
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::MFA_VERIFIED,
                    actorAccountId: $challenge->accountId,
                    resourceType: 'core.auth_session',
                    resourceId: $result->sessionId,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['method' => $method],
                ));

                return $result;
            });
        } catch (MfaVerificationException) {
            $this->fail($challenge, $context);
        }
    }

    private function activeChallenge(string $rawToken): ?AuthChallenge
    {
        if (!$this->tokens->hasValidFormat($rawToken)) {
            return null;
        }
        $challenge = $this->challenges->findActiveBySecretHashHex($this->tokens->hashHex($rawToken));
        return $challenge?->challengeType === 'mfa_login' ? $challenge : null;
    }

    private function fail(AuthChallenge $challenge, ClientContext $context): never
    {
        $this->challenges->registerFailedAttempt($challenge->id);
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::MFA_FAILED,
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
