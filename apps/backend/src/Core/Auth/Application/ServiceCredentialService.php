<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Security\Application\RateLimiterInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ServiceCredentialService
{
    public function __construct(
        private ServiceCredentialRepository $credentials,
        private ServiceCredentialScopeRegistry $scopeRegistry,
        private OpaqueTokenService $tokens,
        private RateLimiterInterface $rateLimiter,
        private AuditWriter $audit,
        private ConnectionInterface $db,
    ) {}

    /** @param list<string> $scopes
     * @return array{credential: array<string, mixed>, secret: string}|null
     */
    public function create(string $accountId, array $scopes, ?string $expiresAt, string $actorAccountId): ?array
    {
        $scopes = $this->scopeRegistry->validate($scopes);
        self::validateExpiry($expiresAt);
        $secret = $this->tokens->issue();
        return $this->db->transaction(function () use ($accountId, $scopes, $expiresAt, $actorAccountId, $secret): ?array {
            $record = $this->credentials->create($accountId, $secret->hashHex, $scopes, $expiresAt, $actorAccountId);
            if ($record === null) {
                return null;
            }
            /** @var array{credentialId:string,accountId:string,scopes:list<string>,id:string,status:string,createdAt:string,expiresAt:?string,lastUsedAt:?string,rotatedAt:?string,revokedAt:?string} $record */
            $this->record('service_credential.created', $record, $actorAccountId);
            return ['credential' => $record, 'secret' => $secret->raw];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $accountId): array { return $this->credentials->listFor($accountId); }

    /** @return array{credential: array<string, mixed>, secret: string}|null */
    public function rotate(string $accountId, string $credentialId, string $actorAccountId): ?array
    {
        $secret = $this->tokens->issue();
        return $this->db->transaction(function () use ($accountId, $credentialId, $actorAccountId, $secret): ?array {
            $record = $this->credentials->rotate($accountId, $credentialId, $secret->hashHex, $actorAccountId);
            if ($record === null) {
                return null;
            }
            /** @var array{credentialId:string,accountId:string,scopes:list<string>,id:string,status:string,createdAt:string,expiresAt:?string,lastUsedAt:?string,rotatedAt:?string,revokedAt:?string} $record */
            $this->record('service_credential.rotated', $record, $actorAccountId);
            return ['credential' => $record, 'secret' => $secret->raw];
        });
    }

    /** @return array<string, mixed>|null */
    public function revoke(string $accountId, string $credentialId, string $actorAccountId): ?array
    {
        return $this->db->transaction(function () use ($accountId, $credentialId, $actorAccountId): ?array {
            $record = $this->credentials->revoke($accountId, $credentialId);
            if ($record === null) {
                return null;
            }
            $changed = $record['changed'];
            unset($record['changed']);
            /** @var array{credentialId:string,accountId:string,scopes:list<string>,id:string,status:string,createdAt:string,expiresAt:?string,lastUsedAt:?string,rotatedAt:?string,revokedAt:?string} $record */
            if ($changed) {
                $this->record('service_credential.revoked', $record, $actorAccountId);
            }
            return $record;
        });
    }

    /** Header format: Authorization: Service <credentialId>.<secret> */
    public function authenticate(string $authorization, string $clientKey): ?ServiceIdentity
    {
        $parts = preg_match('/\AService ([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.([A-Za-z0-9_-]{43})\z/D', $authorization, $matches);
        $credentialId = $parts === 1 ? $matches[1] : null;
        $digest = hash('sha256', $clientKey);
        if (!$this->rateLimiter->consume('m2m.ip', $digest, 120, 60)) {
            return null;
        }
        if ($credentialId === null) {
            return null;
        }
        if (!$this->rateLimiter->consume('m2m.credential', hash('sha256', $credentialId), 300, 60)) {
            return null;
        }
        $record = $this->credentials->findForAuthentication($credentialId);
        /** @var array{hashHex: string, status: string, revokedAt: string|null, expiresAt: string|null, accountType: string, accountStatus: string, disabledAt: string|null, archivedAt: string|null, accountId: string, credentialId: string, scopes: list<string>}|null $record */
        $actualHash = $this->tokens->hashHex($matches[2]);
        if (!hash_equals($record['hashHex'] ?? str_repeat('0', 64), $actualHash)
            || $record === null || $record['status'] !== 'active' || $record['revokedAt'] !== null
            || ($record['expiresAt'] !== null && new DateTimeImmutable($record['expiresAt']) <= new DateTimeImmutable())
            || !in_array($record['accountType'], ['service', 'system'], true)
            || $record['accountStatus'] !== 'active' || $record['disabledAt'] !== null || $record['archivedAt'] !== null
        ) {
            return null;
        }
        try {
            $this->credentials->touch($credentialId);
        } catch (Throwable) {
            // Usage timestamp is bounded and best effort; authentication still relies on the state above.
        }
        return new ServiceIdentity($record['accountId'], $record['credentialId'], $record['accountType'], $record['scopes']);
    }

    /** @param array{credentialId:string,accountId:string,scopes:list<string>,id:string,status:string,createdAt:string,expiresAt:?string,lastUsedAt:?string,rotatedAt:?string,revokedAt:?string} $record */
    private function record(string $action, array $record, string $actorAccountId): void
    {
        $this->audit->write(new WriteAuditEventCommand(
            actorType: 'account', actorAccountId: $actorAccountId, action: $action,
            resourceType: 'core.service_credential', resourceId: $record['credentialId'],
            metadata: ['accountId' => $record['accountId'], 'scopes' => $record['scopes']],
        ));
    }

    private static function validateExpiry(?string $expiresAt): void
    {
        if ($expiresAt !== null && (new DateTimeImmutable($expiresAt)) <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('Credential expiry must be in the future.');
        }
    }
}
