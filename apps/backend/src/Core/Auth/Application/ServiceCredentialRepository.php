<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface ServiceCredentialRepository
{
    /** @param list<string> $scopes @return array<string, mixed>|null */
    public function create(string $accountId, string $hashHex, array $scopes, ?string $expiresAt, ?string $actorAccountId): ?array;
    /** @return list<array<string, mixed>> */
    public function listFor(string $accountId): array;
    /** @return array<string, mixed>|null */
    public function rotate(string $accountId, string $credentialId, string $hashHex, ?string $actorAccountId): ?array;
    /** @return array<string, mixed>|null */
    public function revoke(string $accountId, string $credentialId): ?array;
    /** @return array<string, mixed>|null */
    public function findForAuthentication(string $credentialId): ?array;
    public function touch(string $credentialId): void;
}
