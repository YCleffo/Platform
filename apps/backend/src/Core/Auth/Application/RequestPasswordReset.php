<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Communication\Application\EmailService;
use App\Core\Communication\Application\QueueEmailCommand;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class RequestPasswordReset
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

    public function execute(string $email, ClientContext $context): void
    {
        try {
            $normalized = $this->normalizer->normalize('email', $email);
        } catch (InvalidArgumentException) {
            $normalized = mb_strtolower(trim($email), 'UTF-8');
        }
        $identifierDigest = $this->digests->digest($normalized);
        $ipDigest = $this->digests->digest($context->ipAddress ?? 'unknown');
        $allowed = $this->rateLimiter->consume(
            'password-reset:identifier',
            $identifierDigest,
            $this->rateLimitPolicy->passwordResetLimit,
            $this->rateLimitPolicy->passwordResetWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'password-reset:ip',
            $ipDigest,
            $this->rateLimitPolicy->passwordResetLimit,
            $this->rateLimitPolicy->passwordResetWindowSeconds,
        ) && $allowed;
        if (!$allowed) {
            throw new LoginRateLimitedException();
        }

        $target = $this->credentials->findRecoverableEmail($normalized);
        if ($target === null) {
            return;
        }

        $this->db->transaction(function () use ($target, $context): void {
            $token = $this->tokens->issue();
            $expiresAt = $this->clock->now()->modify(sprintf(
                '+%d seconds',
                $this->runtimePolicy->passwordResetTtlSeconds,
            ));
            $challenge = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $target->accountId,
                challengeType: 'password_reset',
                secretHashHex: $token->hashHex,
                targetNormalized: $target->normalizedEmail,
                payload: ['purpose' => 'password_reset'],
                maxAttempts: 5,
                expiresAt: $expiresAt->format('Y-m-d\TH:i:s\Z'),
            ));
            $this->email->queue(new QueueEmailCommand(
                producerModule: 'core.auth',
                recipientAddress: $target->email,
                recipientName: $target->displayName,
                locale: $context->locale->locale->tag,
                templateCode: 'auth.password.reset',
                variables: [
                    'displayName' => $target->displayName,
                    'actionUrl' => $this->links->passwordReset($token->raw),
                ],
                aggregateType: 'core.auth_challenge',
                aggregateId: $challenge->id,
            ));
        });
    }
}
