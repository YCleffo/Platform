<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface SessionRepository
{
    public function storeSession(CreateSessionCommand $command): AuthSession;

    public function findByTokenHashHex(string $tokenHashHex): ?AuthSession;

    public function findByRefreshTokenHashHex(string $tokenHashHex): ?AuthSession;

    public function rotateTokens(RotateSessionTokensCommand $command): ?AuthSession;

    public function findForAccount(string $sessionId, string $accountId): ?AuthSession;

    /** @return list<AuthSession> */
    public function findActiveForAccount(string $accountId): array;

    public function touch(TouchSessionCommand $command): ?AuthSession;

    public function revoke(string $id, string $reason): bool;

    public function revokeForAccount(string $id, string $accountId, string $reason): bool;

    public function revokeOthers(string $accountId, string $currentSessionId, string $reason): int;

    public function revokeAll(string $accountId, string $reason): int;

    public function secureCurrentAndRevokeOthers(
        string $accountId,
        string $currentSessionId,
        string $securityStamp,
        string $reason,
        bool $markMfaVerified = false,
    ): int;
}
