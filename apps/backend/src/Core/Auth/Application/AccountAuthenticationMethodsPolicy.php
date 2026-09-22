<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AccountAuthenticationMethodsPolicy
{
    public function __construct(
        private PasswordCredentialRepository $passwords,
        private ExternalIdentityRepository $identities,
        private PasskeyRepository $passkeys,
    ) {}

    public function countUsableMethods(string $accountId): int
    {
        $count = 0;
        if ($this->passwords->findByAccountId($accountId) !== null) {
            ++$count;
        }
        $count += $this->identities->countByAccount($accountId);
        $count += count($this->passkeys->findActivePasskeysByAccountId($accountId));

        return $count;
    }

    public function canRemoveMethod(string $accountId): bool
    {
        return $this->countUsableMethods($accountId) > 1;
    }

    public function assertCanRemovePasskey(string $accountId): void
    {
        if (!$this->canRemoveMethod($accountId)) {
            throw new LastAuthenticationMethodException('Cannot revoke the last usable authentication method.');
        }
    }

    public function assertCanUnlinkIdentity(string $accountId): void
    {
        if (!$this->canRemoveMethod($accountId)) {
            throw new ExternalIdentityLastMethodException('Cannot unlink the last usable authentication method.');
        }
    }
}
