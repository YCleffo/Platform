<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountRepository;
use InvalidArgumentException;

final readonly class TotpProvisioningPolicy
{
    public string $issuer;

    public function __construct(
        private IdentifierRepository $identifiers,
        private AccountRepository $accounts,
        string $issuer,
    ) {
        $issuer = trim($issuer);
        if ($issuer === '' || mb_strlen($issuer) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $issuer) === 1) {
            throw new InvalidArgumentException('TOTP issuer must contain 1-64 printable characters.');
        }
        $this->issuer = $issuer;
    }

    public function accountLabel(string $accountId): string
    {
        $recoveryEmail = $this->identifiers->findRecoveryEmailByAccount($accountId);
        if ($recoveryEmail !== null) {
            return $recoveryEmail->value;
        }
        $identifier = $this->identifiers->findDisplayIdentifierByAccount($accountId);
        if ($identifier !== null) {
            return $identifier->value;
        }
        $accountLabel = trim($this->accounts->findById($accountId)?->label ?? '');
        if ($accountLabel !== '') {
            return $accountLabel;
        }
        $compactId = preg_replace('/[^a-f0-9]/i', '', $accountId);
        if (is_string($compactId) && strlen($compactId) >= 8) {
            return 'account-' . substr($compactId, 0, 12);
        }
        return $accountId;
    }
}
