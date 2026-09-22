<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface AuthChallengeRepository
{
    public function storeChallenge(CreateAuthChallengeCommand $command): AuthChallenge;

    public function findActiveBySecretHashHex(string $secretHashHex): ?AuthChallenge;

    public function consume(string $id): bool;

    public function cancel(string $id): bool;

    public function registerFailedAttempt(string $id): ?AuthChallenge;

    public function cancelAllForAccount(string $accountId, ?string $exceptId = null): int;
}
