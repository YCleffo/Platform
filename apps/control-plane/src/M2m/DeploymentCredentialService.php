<?php

declare(strict_types=1);

namespace ControlPlane\M2m;

use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\RateLimitExceeded;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class DeploymentCredentialService
{
    public function __construct(
        private Connection $connection,
        private DeploymentScopeRegistry $scopes,
        private DatabaseRateLimiter $rateLimiter,
    ) {}

    /** @param list<string> $scopes
     * @return array{credential: array<string, mixed>, secret: string}|null
     */
    public function create(string $deploymentId, array $scopes, ?string $expiresAt = null): ?array
    {
        $scopes = $this->scopes->validate($scopes);
        self::validateExpiry($expiresAt);
        $secret = self::secret();
        return $this->connection->transactional(function (Connection $db) use ($deploymentId, $scopes, $expiresAt, $secret): ?array {
            $allowed = $db->fetchOne(<<<'SQL'
                SELECT 1 FROM commercial.deployments WHERE id = ? AND archived_at IS NULL
                    AND status <> 'decommissioned' FOR SHARE
                SQL, [$deploymentId]);
            if ($allowed === false) {
                return null;
            }
            $row = $db->fetchAssociative(<<<'SQL'
                INSERT INTO commercial.service_credentials
                    (deployment_id, credential_id, secret_hash, scopes, expires_at)
                VALUES (?, ?, decode(?, 'hex'), ?::text[], ?::timestamptz)
                RETURNING id::text, credential_id::text, deployment_id::text,
                    array_to_json(scopes)::text AS scopes_json, status,
                    created_at::text, expires_at::text, last_used_at::text,
                    rotated_at::text, revoked_at::text
                SQL, [$deploymentId, Uuid::v4()->toRfc4122(), hash('sha256', $secret), self::pgArray($scopes), $expiresAt]);
            return $row === false ? null : ['credential' => self::present($row), 'secret' => $secret];
        });
    }

    /** @return array{credential: array<string, mixed>, secret: string}|null */
    public function rotate(string $deploymentId, string $credentialId): ?array
    {
        $secret = self::secret();
        return $this->connection->transactional(function (Connection $db) use ($deploymentId, $credentialId, $secret): ?array {
            $old = $db->fetchAssociative(<<<'SQL'
                SELECT c.scopes, c.expires_at::text AS expires_at
                FROM commercial.service_credentials c JOIN commercial.deployments d ON d.id = c.deployment_id
                WHERE c.deployment_id = ? AND c.credential_id = ? AND c.status = 'active'
                  AND (c.expires_at IS NULL OR c.expires_at > now())
                  AND d.archived_at IS NULL AND d.status <> 'decommissioned'
                FOR UPDATE OF c
                SQL, [$deploymentId, $credentialId]);
            if ($old === false) {
                return null;
            }
            $db->executeStatement("UPDATE commercial.service_credentials SET status = 'rotated', rotated_at = now() WHERE credential_id = ?", [$credentialId]);
            $row = $db->fetchAssociative(<<<'SQL'
                INSERT INTO commercial.service_credentials
                    (deployment_id, credential_id, secret_hash, scopes, expires_at)
                VALUES (?, ?, decode(?, 'hex'), ?::text[], ?::timestamptz)
                RETURNING id::text, credential_id::text, deployment_id::text,
                    array_to_json(scopes)::text AS scopes_json, status,
                    created_at::text, expires_at::text, last_used_at::text,
                    rotated_at::text, revoked_at::text
                SQL, [$deploymentId, Uuid::v4()->toRfc4122(), hash('sha256', $secret), $old['scopes'], $old['expires_at']]);
            return $row === false ? null : ['credential' => self::present($row), 'secret' => $secret];
        });
    }

    /** @return array<string, mixed>|null */
    public function revoke(string $deploymentId, string $credentialId): ?array
    {
        return $this->connection->transactional(function (Connection $db) use ($deploymentId, $credentialId): ?array {
            $row = $db->fetchAssociative(<<<'SQL'
                SELECT id::text, credential_id::text, deployment_id::text,
                    array_to_json(scopes)::text AS scopes_json, status,
                    created_at::text, expires_at::text, last_used_at::text,
                    rotated_at::text, revoked_at::text
                FROM commercial.service_credentials
                WHERE deployment_id = ? AND credential_id = ? FOR UPDATE
                SQL, [$deploymentId, $credentialId]);
            if ($row === false) {
                return null;
            }
            if ($row['status'] === 'active') {
                $row = $db->fetchAssociative(<<<'SQL'
                    UPDATE commercial.service_credentials SET status = 'revoked', revoked_at = now()
                    WHERE credential_id = ?
                    RETURNING id::text, credential_id::text, deployment_id::text,
                        array_to_json(scopes)::text AS scopes_json, status,
                        created_at::text, expires_at::text, last_used_at::text,
                        rotated_at::text, revoked_at::text
                    SQL, [$credentialId]);
            }
            return self::present($row);
        });
    }

    /** Authorization: Service <credentialId>.<secret> */
    public function authenticate(string $authorization, string $clientKey): ?DeploymentIdentity
    {
        try {
            $this->rateLimiter->consume('deployment_m2m', hash('sha256', $clientKey), 120, 60);
        } catch (RateLimitExceeded) {
            return null;
        }
        if (preg_match('/\AService ([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.([A-Za-z0-9_-]{43})\z/D', $authorization, $match) !== 1) {
            return null;
        }
        try {
            $this->rateLimiter->consume('deployment_m2m_id', $match[1], 300, 60);
        } catch (RateLimitExceeded) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT c.deployment_id::text, c.credential_id::text,
                encode(c.secret_hash, 'hex') AS secret_hash, array_to_json(c.scopes)::text AS scopes_json,
                c.status, c.expires_at::text, c.revoked_at::text,
                d.status AS deployment_status, d.archived_at AS deployment_archived_at
            FROM commercial.service_credentials c JOIN commercial.deployments d ON d.id = c.deployment_id
            WHERE c.credential_id = ?
            SQL, [$match[1]]);
        $actual = hash('sha256', $match[2]);
        if (!hash_equals($row === false ? str_repeat('0', 64) : (string) $row['secret_hash'], $actual)
            || $row === false || $row['status'] !== 'active' || $row['revoked_at'] !== null
            || ($row['expires_at'] !== null && new \DateTimeImmutable($row['expires_at']) <= new \DateTimeImmutable())
            || $row['deployment_archived_at'] !== null || $row['deployment_status'] === 'decommissioned') {
            return null;
        }
        try {
            $this->connection->executeStatement(<<<'SQL'
                UPDATE commercial.service_credentials SET last_used_at = now()
                WHERE credential_id = ? AND status = 'active'
                  AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')
                SQL, [$match[1]]);
        } catch (Throwable) {
            // Best-effort bounded usage timestamp.
        }
        return new DeploymentIdentity($row['deployment_id'], $row['credential_id'],
            json_decode($row['scopes_json'], true, flags: JSON_THROW_ON_ERROR));
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param list<string> $scopes */
    private static function pgArray(array $scopes): string
    {
        return '{' . implode(',', array_map(static fn(string $scope): string => '"' . $scope . '"', $scopes)) . '}';
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return ['id' => $row['id'], 'credentialId' => $row['credential_id'],
            'deploymentId' => $row['deployment_id'],
            'scopes' => json_decode($row['scopes_json'], true, flags: JSON_THROW_ON_ERROR),
            'status' => $row['status'], 'createdAt' => $row['created_at'],
            'expiresAt' => $row['expires_at'], 'lastUsedAt' => $row['last_used_at'],
            'rotatedAt' => $row['rotated_at'], 'revokedAt' => $row['revoked_at']];
    }

    private static function validateExpiry(?string $expiresAt): void
    {
        if ($expiresAt !== null && new \DateTimeImmutable($expiresAt) <= new \DateTimeImmutable()) {
            throw new InvalidArgumentException('Deployment credential expiry must be in the future.');
        }
    }
}
