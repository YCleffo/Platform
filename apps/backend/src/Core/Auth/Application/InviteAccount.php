<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Communication\Application\EmailService;
use App\Core\Communication\Application\QueueEmailCommand;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Time\Application\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class InviteAccount
{
    public function __construct(
        private CredentialLifecycleRepository $credentials,
        private AuthChallengeRepository $challenges,
        private AuthIdentifierNormalizer $normalizer,
        private OpaqueTokenService $tokens,
        private AuthRuntimePolicy $runtimePolicy,
        private AuthRateLimitPolicy $rateLimitPolicy,
        private RateLimiterInterface $rateLimiter,
        private IdentifierDigestService $digests,
        private AuthLinkBuilder $links,
        private EmailService $email,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {}

    public function execute(string $accountId, string $email, ClientContext $context): bool
    {
        $normalized = $this->normalizer->normalize('email', $email);
        $allowed = $this->rateLimiter->consume(
            'activation:account',
            $this->digests->digest($accountId),
            $this->rateLimitPolicy->activationResendLimit,
            $this->rateLimitPolicy->activationResendWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'activation:ip',
            $this->digests->digest($context->ipAddress ?? 'unknown'),
            $this->rateLimitPolicy->activationResendLimit,
            $this->rateLimitPolicy->activationResendWindowSeconds,
        ) && $allowed;
        if (!$allowed) {
            throw new LoginRateLimitedException();
        }

        return $this->db->transaction(function () use ($accountId, $email, $normalized): bool {
            $target = $this->credentials->ensurePrimaryEmail($accountId, $email, $normalized);
            if ($target === null) {
                return false;
            }
            $token = $this->tokens->issue();
            $expiresAt = $this->clock->now()->modify(sprintf(
                '+%d seconds',
                $this->runtimePolicy->activationTtlSeconds,
            ));
            $challenge = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $accountId,
                challengeType: 'account_activation',
                secretHashHex: $token->hashHex,
                targetNormalized: $target->normalizedEmail,
                payload: ['purpose' => 'account_activation'],
                maxAttempts: 5,
                expiresAt: $expiresAt->format('Y-m-d\TH:i:s\Z'),
            ));
            $this->email->queue(new QueueEmailCommand(
                producerModule: 'core.auth',
                recipientAddress: $target->email,
                recipientName: $target->displayName,
                locale: $target->locale,
                templateCode: 'auth.email.verification',
                variables: [
                    'displayName' => $target->displayName,
                    'actionUrl' => $this->links->activation($token->raw),
                ],
                aggregateType: 'core.auth_challenge',
                aggregateId: $challenge->id,
            ));

            return true;
        });
    }
}
