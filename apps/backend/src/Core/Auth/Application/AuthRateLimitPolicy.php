<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final readonly class AuthRateLimitPolicy
{
    public function __construct(
        public int $loginIpLimit,
        public int $loginIdentifierLimit,
        public int $loginCombinedLimit,
        public int $loginWindowSeconds,
        public int $passwordResetLimit,
        public int $passwordResetWindowSeconds,
        public int $activationResendLimit,
        public int $activationResendWindowSeconds,
        public int $externalStartIpLimit,
        public int $externalCallbackLimit,
        public int $mfaVerifyChallengeLimit,
        public int $mfaVerifyIpLimit,
        public int $totpSetupConfirmLimit,
        public int $mfaRecoveryIdentifierLimit,
        public int $mfaRecoveryIpLimit,
        public int $mfaRecoveryConfirmLimit,
        public int $mfaRecoveryExternalStartIpLimit,
        public int $mfaRecoveryExternalTransactionLimit,
        public int $mfaRecoveryExternalCallbackLimit,
        public int $providerLinkLimit,
        public int $recoveryCodeLimit,
        public int $securityWindowSeconds,
        public int $passkeyAuthStartLimit = 20,
        public int $passkeyAuthCompleteLimit = 10,
        public int $passkeyRegisterCompleteLimit = 10,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Authentication rate limit values must be positive integers.');
            }
        }
    }
}
