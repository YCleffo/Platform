<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;

final readonly class AuthenticationFlowService
{
    public function __construct(
        private TotpFactorRepository $totpFactors,
        private AuthChallengeRepository $challenges,
        private OpaqueTokenService $tokens,
        private AuthRuntimePolicy $policy,
        private ClockInterface $clock,
        private SessionIssuer $sessions,
        private SecurityAuditService $audit,
        private PasswordCredentialRepository $passwords,
    ) {}

    public function completeFirstFactor(
        string $accountId,
        string $method,
        ClientContext $context,
    ): AuthenticationFlowResult {
        AuthenticationMethod::firstFactor($method);
        if ($this->passwords->findByAccountId($accountId)?->mustChangePassword === true) {
            $token = $this->tokens->issue();
            $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $accountId,
                challengeType: 'password_change_required',
                secretHashHex: $token->hashHex,
                targetNormalized: null,
                payload: ['purpose' => 'password_change', 'firstFactor' => $method, 'clientBinding' => self::clientBinding($context)],
                maxAttempts: 5,
                expiresAt: $this->clock->now()->modify(sprintf('+%d seconds', $this->policy->mfaChallengeTtlSeconds))->format('Y-m-d\TH:i:s\Z'),
            ));
            return AuthenticationFlowResult::passwordChangeRequired($token->raw);
        }
        if ($this->totpFactors->findActive($accountId) === null) {
            return AuthenticationFlowResult::authenticated(
                $this->sessions->issue($accountId, $method, $context, false),
            );
        }

        $token = $this->tokens->issue();
        $challenge = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
            accountId: $accountId,
            challengeType: 'mfa_login',
            secretHashHex: $token->hashHex,
            targetNormalized: null,
            payload: [
                'purpose' => 'login',
                'firstFactor' => $method,
                'clientBinding' => self::clientBinding($context),
            ],
            maxAttempts: 5,
            expiresAt: $this->clock->now()
                ->modify(sprintf('+%d seconds', $this->policy->mfaChallengeTtlSeconds))
                ->format('Y-m-d\TH:i:s\Z'),
        ));
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::MFA_REQUIRED,
            actorAccountId: $accountId,
            resourceType: 'core.auth_challenge',
            resourceId: $challenge->id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['firstFactor' => $method],
        ));

        return AuthenticationFlowResult::mfaRequired($token->raw);
    }

    public static function clientBinding(ClientContext $context): string
    {
        return hash('sha256', implode('|', [
            $context->clientInstanceId ?? '',
            $context->device->clientType,
            $context->userAgent ?? '',
        ]));
    }
}
