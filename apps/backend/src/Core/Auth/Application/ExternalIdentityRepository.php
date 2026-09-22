<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface ExternalIdentityRepository
{
    public function findByProviderSubject(string $providerId, string $subject): ?ExternalIdentity;

    public function findByAccountProvider(string $accountId, string $providerId): ?ExternalIdentity;

    /** @return list<ExternalIdentity> */
    public function findByAccount(string $accountId): array;

    public function link(string $accountId, string $providerId, ExternalIdentityProfile $profile): ExternalIdentity;

    public function updateSnapshotAndLogin(string $id, ExternalIdentityProfile $profile): ?ExternalIdentity;

    public function unlink(string $accountId, string $providerId): ?ExternalIdentity;

    public function countByAccount(string $accountId): int;
}
