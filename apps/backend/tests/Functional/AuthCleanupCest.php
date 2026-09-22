<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;

final readonly class AuthCleanupCest
{
    public function cleanupRemovesObsoleteRecordsAndPreservesActive(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $accountId = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'Cleanup test') RETURNING id::text",
            )->queryScalar();

            // 1. Challenges
            // Old expired challenge (older than 30 days) -> should be deleted
            $oldExpiredChallengeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_challenges (
                    account_id, challenge_type, secret_hash, created_at, expires_at
                ) VALUES (
                    CAST(:acc AS uuid), 'test_expired', decode('010203', 'hex'),
                    now() - interval '40 days', now() - interval '35 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Fresh expired challenge (only 2 days old) -> must be preserved (retention 30 days)
            $freshExpiredChallengeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_challenges (
                    account_id, challenge_type, secret_hash, created_at, expires_at
                ) VALUES (
                    CAST(:acc AS uuid), 'test_fresh_exp', decode('040506', 'hex'),
                    now() - interval '3 days', now() - interval '2 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Old consumed challenge (consumed 35 days ago) -> should be deleted
            $oldConsumedChallengeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_challenges (
                    account_id, challenge_type, secret_hash, created_at, expires_at, consumed_at
                ) VALUES (
                    CAST(:acc AS uuid), 'test_consumed', decode('070809', 'hex'),
                    now() - interval '40 days', now() + interval '1 day', now() - interval '35 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Active challenge (not consumed, not expired) -> must be preserved
            $activeChallengeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_challenges (
                    account_id, challenge_type, secret_hash, created_at, expires_at
                ) VALUES (
                    CAST(:acc AS uuid), 'test_active', decode('0a0b0c', 'hex'),
                    now(), now() + interval '1 day'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // 2. Recovery codes
            // Old used code (used 35 days ago) -> should be deleted
            $oldUsedCodeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_recovery_codes (
                    account_id, code_hash, created_at, used_at
                ) VALUES (
                    CAST(:acc AS uuid), decode('112233', 'hex'),
                    now() - interval '40 days', now() - interval '35 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Old revoked code (revoked 35 days ago) -> should be deleted
            $oldRevokedCodeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_recovery_codes (
                    account_id, code_hash, created_at, revoked_at
                ) VALUES (
                    CAST(:acc AS uuid), decode('445566', 'hex'),
                    now() - interval '40 days', now() - interval '35 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Fresh unused available recovery code -> must be preserved
            $unusedCodeId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_recovery_codes (
                    account_id, code_hash, created_at
                ) VALUES (
                    CAST(:acc AS uuid), decode('778899', 'hex'), now()
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // 3. TOTP factors
            // Expired unverified pending TOTP factor -> should be deleted
            $expiredPendingTotpId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_totp_factors (
                    account_id, label, secret_encrypted, algorithm, digits, period_seconds,
                    secret_key_version, created_at, setup_expires_at
                ) VALUES (
                    CAST(:acc AS uuid), 'pending_exp', decode('aabbcc', 'hex'), 'sha1', 6, 30,
                    1, now() - interval '2 hours', now() - interval '1 hour'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Fresh pending unverified TOTP factor -> must be preserved
            $freshPendingTotpId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_totp_factors (
                    account_id, label, secret_encrypted, algorithm, digits, period_seconds,
                    secret_key_version, created_at, setup_expires_at
                ) VALUES (
                    CAST(:acc AS uuid), 'pending_fresh', decode('ddeeff', 'hex'), 'sha1', 6, 30,
                    1, now(), now() + interval '10 minutes'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            // Active verified TOTP factor -> must be preserved
            $activeVerifiedTotpId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_totp_factors (
                    account_id, label, secret_encrypted, algorithm, digits, period_seconds,
                    secret_key_version, created_at, verified_at
                ) VALUES (
                    CAST(:acc AS uuid), 'active_ver', decode('001122', 'hex'), 'sha1', 6, 30,
                    1, now() - interval '10 days', now() - interval '10 days'
                ) RETURNING id::text
                SQL,
                [':acc' => $accountId],
            )->queryScalar();

            $repository = $container->get(AuthCleanupRepository::class);
            $policy = new AuthCleanupPolicy(
                challengeRetentionDays: 30,
                recoveryCodeRetentionDays: 30,
                batchSize: 100,
            );

            // Execute cleanup
            $report = $repository->cleanup($policy);
            assertSame(1, $report->expiredChallenges);
            assertSame(1, $report->consumedChallenges);
            assertSame(2, $report->recoveryCodes);
            assertSame(1, $report->pendingTotpFactors);

            // Verify deleted records do not exist
            $remainingChallenges = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_challenges WHERE id IN (CAST(:old_exp AS uuid), CAST(:old_cons AS uuid))',
                [':old_exp' => $oldExpiredChallengeId, ':old_cons' => $oldConsumedChallengeId],
            )->queryScalar();
            assertSame(0, $remainingChallenges);

            $remainingCodes = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_recovery_codes WHERE id IN (CAST(:c1 AS uuid), CAST(:c2 AS uuid))',
                [':c1' => $oldUsedCodeId, ':c2' => $oldRevokedCodeId],
            )->queryScalar();
            assertSame(0, $remainingCodes);

            $remainingTotp = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_totp_factors WHERE id = CAST(:id AS uuid)',
                [':id' => $expiredPendingTotpId],
            )->queryScalar();
            assertSame(0, $remainingTotp);

            // Verify preserved records still exist
            $preservedChallenges = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_challenges WHERE id IN (CAST(:fresh_exp AS uuid), CAST(:active AS uuid))',
                [':fresh_exp' => $freshExpiredChallengeId, ':active' => $activeChallengeId],
            )->queryScalar();
            assertSame(2, $preservedChallenges);

            $preservedCodes = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_recovery_codes WHERE id = CAST(:id AS uuid)',
                [':id' => $unusedCodeId],
            )->queryScalar();
            assertSame(1, $preservedCodes);

            $preservedTotp = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_totp_factors WHERE id IN (CAST(:p_fresh AS uuid), CAST(:act_ver AS uuid))',
                [':p_fresh' => $freshPendingTotpId, ':act_ver' => $activeVerifiedTotpId],
            )->queryScalar();
            assertSame(2, $preservedTotp);

            // Repeat cleanup is idempotent and deletes 0
            $secondReport = $repository->cleanup($policy);
            assertSame(0, $secondReport->total());
        } finally {
            $transaction->rollBack();
        }
    }
}
