<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final readonly class AuthRuntimePolicy
{
    public function __construct(
        public int $accessTokenTtlSeconds,
        public int $sessionTtlSeconds,
        public int $activationTtlSeconds,
        public int $passwordResetTtlSeconds,
        public int $mfaChallengeTtlSeconds,
        public int $mfaRecoveryTtlSeconds,
        public int $totpSetupTtlSeconds,
        public int $recentAuthenticationSeconds,
    ) {
        if ($accessTokenTtlSeconds < 60 || $accessTokenTtlSeconds > 3600) {
            throw new InvalidArgumentException('Access token TTL must be between 60 and 3600 seconds.');
        }
        if ($sessionTtlSeconds <= $accessTokenTtlSeconds || $sessionTtlSeconds > 31_536_000) {
            throw new InvalidArgumentException('Session TTL must exceed access TTL and be at most one year.');
        }
        if ($activationTtlSeconds < 300 || $activationTtlSeconds > 604_800) {
            throw new InvalidArgumentException('Activation TTL must be between five minutes and seven days.');
        }
        if ($passwordResetTtlSeconds < 300 || $passwordResetTtlSeconds > 86_400) {
            throw new InvalidArgumentException('Password reset TTL must be between five minutes and one day.');
        }
        foreach ([$mfaChallengeTtlSeconds, $totpSetupTtlSeconds, $recentAuthenticationSeconds] as $ttl) {
            if ($ttl < 60 || $ttl > 3_600) {
                throw new InvalidArgumentException('MFA and recent-authentication TTLs must be between one minute and one hour.');
            }
        }
        if ($mfaRecoveryTtlSeconds < 300 || $mfaRecoveryTtlSeconds > 3_600) {
            throw new InvalidArgumentException('MFA recovery TTL must be between five minutes and one hour.');
        }
    }

    public function issuePair(OpaqueTokenService $tokens): IssuedTokenPair
    {
        return new IssuedTokenPair($tokens->issue(), $tokens->issue());
    }
}
