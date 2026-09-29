<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;

final readonly class RefreshSession
{
    public function __construct(
        private SessionRepository $sessions,
        private AccountSecurityRepository $accounts,
        private OpaqueTokenService $tokens,
        private AuthRuntimePolicy $policy,
        private ClockInterface $clock,
        private SecurityAuditService $securityAudit,
    ) {}

    public function execute(string $rawRefreshToken, ClientContext $context): ?AuthenticationResult
    {
        if (!$this->tokens->hasValidFormat($rawRefreshToken)) {
            return null;
        }

        $refreshHash = $this->tokens->hashHex($rawRefreshToken);
        $session = $this->sessions->findByRefreshTokenHashHex($refreshHash);
        $now = $this->clock->now();
        if ($session === null
            || $session->revokedAt !== null
            || new DateTimeImmutable($session->expiresAt) <= $now
        ) {
            return null;
        }

        $account = $this->accounts->findSecurityState($session->accountId);
        if ($account === null
            || !$account->allowsHumanAuthentication($now)
            || !hash_equals($account->securityStamp, $session->securityStamp)
        ) {
            return null;
        }

        $pair = $this->policy->issuePair($this->tokens);
        $accessExpiresAt = $now->modify(sprintf('+%d seconds', $this->policy->accessTokenTtlSeconds));
        $absoluteExpiry = new DateTimeImmutable($session->expiresAt);
        if ($accessExpiresAt > $absoluteExpiry) {
            $accessExpiresAt = $absoluteExpiry;
        }

        $rotated = $this->sessions->rotateTokens(new RotateSessionTokensCommand(
            currentRefreshTokenHashHex: $refreshHash,
            newAccessTokenHashHex: $pair->accessToken->hashHex,
            newRefreshTokenHashHex: $pair->refreshToken->hashHex,
            accessExpiresAt: $accessExpiresAt->format('Y-m-d\TH:i:s\Z'),
            expectedSecurityStamp: $account->securityStamp,
        ));
        if ($rotated === null) {
            return null;
        }

        $this->securityAudit->sessionRefreshed($account->accountId, $rotated->id, $context);

        return new AuthenticationResult(
            accessToken: $pair->accessToken->raw,
            refreshToken: $pair->refreshToken->raw,
            accessExpiresAt: $rotated->accessExpiresAt,
            sessionExpiresAt: $rotated->expiresAt,
            sessionId: $rotated->id,
        );
    }
}
