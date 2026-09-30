<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthCleanupReport;
use App\Core\Auth\Application\AuthCleanupRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAuthCleanupRepository implements AuthCleanupRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function eraseHumanIdentity(string $accountId): void
    {
        $account = $this->db->createCommand(<<<'SQL'
            SELECT account_type::text FROM core.accounts WHERE id = CAST(:id AS uuid) FOR UPDATE
            SQL, [':id' => $accountId])->queryScalar();
        if ($account !== 'human') {
            throw new \DomainException('Privacy credential erasure requires a human account.');
        }
        // Delete unbound activation/reset challenges while identifiers still identify their target.
        $this->db->createCommand(<<<'SQL'
            DELETE FROM core.auth_challenges c
            WHERE c.account_id = CAST(:id AS uuid)
                OR (c.account_id IS NULL AND EXISTS (
                    SELECT 1 FROM core.auth_identifiers i
                    WHERE i.account_id = CAST(:id AS uuid)
                        AND i.normalized_value = c.target_normalized))
            SQL, [':id' => $accountId])->execute();
        foreach (['auth_sessions', 'auth_recovery_codes', 'auth_totp_factors', 'auth_passkeys',
            'auth_external_identities', 'auth_password_credentials', 'auth_identifiers'] as $table) {
            $this->db->createCommand("DELETE FROM core.{$table} WHERE account_id = CAST(:id AS uuid)",
                [':id' => $accountId])->execute();
        }
    }

    public function cleanup(AuthCleanupPolicy $policy): AuthCleanupReport
    {
        return new AuthCleanupReport(
            expiredChallenges: $this->deleteChallenges(
                'expires_at < now() - make_interval(days => CAST(:retention_days AS integer))',
                'expires_at',
                $policy->challengeRetentionDays,
                $policy->batchSize,
            ),
            consumedChallenges: $this->deleteChallenges(
                'consumed_at < now() - make_interval(days => CAST(:retention_days AS integer))',
                'consumed_at',
                $policy->challengeRetentionDays,
                $policy->batchSize,
            ),
            cancelledChallenges: $this->deleteChallenges(
                'cancelled_at < now() - make_interval(days => CAST(:retention_days AS integer))',
                'cancelled_at',
                $policy->challengeRetentionDays,
                $policy->batchSize,
            ),
            recoveryCodes: $this->deleteRecoveryCodes($policy),
            pendingTotpFactors: $this->deletePendingTotpFactors($policy->batchSize),
            serviceCredentials: $this->deleteServiceCredentials($policy),
        );
    }

    private function deleteChallenges(string $predicate, string $orderColumn, int $retentionDays, int $batchSize): int
    {
        $sql = <<<SQL
            WITH candidates AS (
                SELECT id
                FROM core.auth_challenges
                WHERE {$predicate}
                ORDER BY {$orderColumn}, id
                FOR UPDATE SKIP LOCKED
                LIMIT :batch_size
            )
            DELETE FROM core.auth_challenges AS target
            USING candidates
            WHERE target.id = candidates.id
              AND target.{$predicate}
            SQL;

        return $this->db->createCommand($sql, [
            ':retention_days' => $retentionDays,
            ':batch_size' => $batchSize,
        ])->execute();
    }

    private function deleteRecoveryCodes(AuthCleanupPolicy $policy): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            WITH candidates AS (
                SELECT id
                FROM core.auth_recovery_codes
                WHERE used_at < now() - make_interval(days => CAST(:retention_days AS integer))
                   OR revoked_at < now() - make_interval(days => CAST(:retention_days AS integer))
                ORDER BY coalesce(used_at, revoked_at), id
                FOR UPDATE SKIP LOCKED
                LIMIT :batch_size
            )
            DELETE FROM core.auth_recovery_codes AS target
            USING candidates
            WHERE target.id = candidates.id
              AND (
                  target.used_at < now() - make_interval(days => CAST(:retention_days AS integer))
                  OR target.revoked_at < now() - make_interval(days => CAST(:retention_days AS integer))
              )
            SQL,
            [
                ':retention_days' => $policy->recoveryCodeRetentionDays,
                ':batch_size' => $policy->batchSize,
            ],
        )->execute();
    }

    private function deletePendingTotpFactors(int $batchSize): int
    {
        return $this->db->createCommand(
            <<<'SQL'
            WITH candidates AS (
                SELECT id
                FROM core.auth_totp_factors
                WHERE verified_at IS NULL
                  AND setup_expires_at < now()
                ORDER BY setup_expires_at, id
                FOR UPDATE SKIP LOCKED
                LIMIT :batch_size
            )
            DELETE FROM core.auth_totp_factors AS target
            USING candidates
            WHERE target.id = candidates.id
              AND target.verified_at IS NULL
              AND target.setup_expires_at < now()
            SQL,
            [':batch_size' => $batchSize],
        )->execute();
    }

    private function deleteServiceCredentials(AuthCleanupPolicy $policy): int
    {
        return $this->db->createCommand(<<<'SQL'
            WITH candidates AS (
                SELECT id FROM core.service_credentials
                WHERE (status <> 'active' AND COALESCE(revoked_at, rotated_at) < now() - make_interval(days => CAST(:days AS integer)))
                   OR (expires_at < now() - make_interval(days => CAST(:days AS integer)))
                ORDER BY created_at, id FOR UPDATE SKIP LOCKED LIMIT :batch_size
            )
            DELETE FROM core.service_credentials t USING candidates WHERE t.id = candidates.id
            SQL, [':days' => $policy->serviceCredentialRetentionDays, ':batch_size' => $policy->batchSize])->execute();
    }
}
