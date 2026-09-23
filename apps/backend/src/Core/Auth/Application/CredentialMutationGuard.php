<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Centralises last-authentication-method invariant enforcement for concurrent mutations.
 *
 * Pattern (must be used INSIDE an active DB transaction):
 *  1. Caller opens a transaction.
 *  2. Calls assertCanRemoveOneMethod() which:
 *     a. Issues SELECT … FOR UPDATE NOWAIT on the account row — serialises all concurrent
 *        credential mutations for this account at the DB level.
 *     b. Re-counts usable first-factor methods while holding the row lock.
 *     c. Throws if removing one method would leave zero.
 *  3. Caller performs the mutation (revoke passkey / unlink identity / etc.).
 *  4. Transaction commits, lock is released.
 *
 * This eliminates the TOCTOU race between:
 *   - concurrent passkey revoke
 *   - concurrent external identity unlink
 *   - any future credential removal path
 *
 * All callers must share the same ConnectionInterface instance so they all
 * participate in the same PostgreSQL transaction and row-lock scope.
 */
final readonly class CredentialMutationGuard
{
    public function __construct(
        private ConnectionInterface $db,
        private PasswordCredentialRepository $passwords,
        private ExternalIdentityRepository $identities,
        private PasskeyRepository $passkeys,
    ) {}

    /**
     * Acquires a row-level lock on the account and re-counts usable methods.
     * Throws LastAuthenticationMethodException if removing one would leave zero.
     *
     * MUST be called inside an active DB transaction.
     * If no active transaction is present this method throws a RuntimeException.
     */
    public function assertCanRemoveOneMethod(string $accountId): void
    {
        // Row-level lock: prevents concurrent credential mutations from racing.
        // NOWAIT means if another transaction already holds the lock we fail fast
        // rather than blocking indefinitely.
        $this->db->createCommand(
            'SELECT id FROM core.accounts WHERE id = CAST(:account_id AS uuid) FOR UPDATE NOWAIT',
            [':account_id' => $accountId],
        )->queryScalar();

        // Re-count after acquiring lock — this is the authoritative count.
        $count = $this->countUsableMethods($accountId);

        if ($count <= 1) {
            throw new LastAuthenticationMethodException(
                'Cannot remove the last usable authentication method.',
            );
        }
    }

    private function countUsableMethods(string $accountId): int
    {
        $count = 0;
        if ($this->passwords->findByAccountId($accountId) !== null) {
            ++$count;
        }
        $count += $this->identities->countByAccount($accountId);
        $count += $this->passkeys->countActiveByAccountId($accountId);

        return $count;
    }
}
