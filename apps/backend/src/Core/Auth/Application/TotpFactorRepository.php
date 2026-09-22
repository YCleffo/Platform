<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface TotpFactorRepository
{
    /** @return list<TotpFactor> */
    public function findActiveByAccountId(string $accountId): array;

    public function findActive(string $accountId): ?TotpFactor;

    public function findPending(string $id, string $accountId): ?TotpFactor;

    public function createPending(CreateTotpFactorCommand $command): TotpFactor;

    public function activate(string $id, string $accountId, int $counter): ?TotpFactor;

    public function acceptCounter(string $id, int $counter): bool;

    public function revokeAllFactors(string $accountId): int;
}
