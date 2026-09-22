<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountRepository;
use App\Core\Security\Application\IdentifierDigestService;
use InvalidArgumentException;

final readonly class TotpProvisioningPolicy
{
    public string $issuer;

    public function __construct(
        private IdentifierRepository $identifiers,
        private AccountRepository $accounts,
        private IdentifierDigestService $digests,
        string $issuer,
    ) {
        $issuer = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', str_replace(':', '', $issuer)) ?? '');
        if ($issuer === '' || mb_strlen($issuer) > 64) {
            throw new InvalidArgumentException('TOTP issuer must contain 1-64 printable characters and no colons.');
        }
        $this->issuer = $issuer;
    }

    public function accountLabel(string $accountId): string
    {
        $recoveryEmail = $this->identifiers->findRecoveryEmailByAccount($accountId);
        if ($recoveryEmail !== null) {
            $sanitized = $this->sanitizeLabel($recoveryEmail->value);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }
        $identifier = $this->identifiers->findDisplayIdentifierByAccount($accountId);
        if ($identifier !== null) {
            $sanitized = $this->sanitizeLabel($identifier->value);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }
        $account = $this->accounts->findById($accountId);
        if ($account !== null && $account->label !== null) {
            $sanitized = $this->sanitizeLabel($account->label);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }

        $digest = substr($this->digests->digest('totp_label:' . $accountId), 0, 16);
        return 'account-' . $digest;
    }

    private function sanitizeLabel(string $candidate): ?string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $candidate);
        if ($sanitized === null) {
            return null;
        }
        $sanitized = trim(str_replace(':', '', $sanitized));
        if ($sanitized === '') {
            return null;
        }
        return mb_substr($sanitized, 0, 160);
    }
}
