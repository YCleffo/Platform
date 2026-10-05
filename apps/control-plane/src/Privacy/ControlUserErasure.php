<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class ControlUserErasure
{
    public function __construct(private Connection $connection, private bool $policyApproved,
        private ?int $identityRetentionDays, private ?int $billingContactRetentionDays)
    {
        if (($identityRetentionDays !== null && $identityRetentionDays < -1)
            || ($billingContactRetentionDays !== null && $billingContactRetentionDays < -1)) {
            throw new \InvalidArgumentException('Invalid Control Plane erasure retention.');
        }
    }

    /** @return array{status: string, reason_code: string, financial_evidence_count: int} */
    public function erase(string $controlUserId): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($controlUserId): array {
            $user = $connection->fetchAssociative('SELECT id, email_normalized, archived_at FROM commercial.control_users WHERE id = ? FOR UPDATE', [$controlUserId]);
            if ($user === false) {
                throw new \DomainException('Control User not found.');
            }
            $financial = (int) $connection->fetchOne(<<<'SQL'
SELECT (SELECT count(*) FROM commercial.invoices i JOIN commercial.commercial_account_memberships m ON m.commercial_account_id = i.commercial_account_id WHERE m.control_user_id = ?)
     + (SELECT count(*) FROM commercial.receipts r JOIN commercial.commercial_account_memberships m ON m.commercial_account_id = r.commercial_account_id WHERE m.control_user_id = ?)
SQL, [$controlUserId, $controlUserId]);
            $reason = null;
            if (!$this->policyApproved || $this->identityRetentionDays === null
                || $this->billingContactRetentionDays === null
                || $this->identityRetentionDays < 0 || $this->billingContactRetentionDays < 0) {
                $reason = 'policy_not_approved';
            } elseif (($this->identityRetentionDays > 0 || $this->billingContactRetentionDays > 0)
                && ($user['archived_at'] === null || (bool) $connection->fetchOne(
                    "SELECT ?::timestamptz <= now() - (? * interval '1 day')",
                    [$user['archived_at'], max($this->identityRetentionDays, $this->billingContactRetentionDays)]) === false)) {
                $reason = 'retention_period_active';
            } elseif ($connection->fetchOne('SELECT 1 FROM commercial.privacy_holds WHERE control_user_id = ? AND released_at IS NULL LIMIT 1', [$controlUserId]) !== false) {
                $reason = 'active_hold';
            } elseif ($connection->fetchOne(<<<'SQL'
SELECT 1 FROM commercial.commercial_account_memberships m
WHERE m.control_user_id = ? AND m.role = 'owner' AND m.revoked_at IS NULL
AND NOT EXISTS (SELECT 1 FROM commercial.commercial_account_memberships other
 WHERE other.commercial_account_id = m.commercial_account_id AND other.id <> m.id
 AND other.role = 'owner' AND other.revoked_at IS NULL)
LIMIT 1
SQL, [$controlUserId]) !== false) {
                $reason = 'sole_account_owner';
            }
            if ($reason !== null) {
                return $this->record($connection, $controlUserId, 'blocked', $reason, $financial);
            }

            $connection->executeStatement("SELECT set_config('app.privacy_lifecycle', 'on', true)");
            $connection->executeStatement('DELETE FROM commercial.control_sessions WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement('DELETE FROM commercial.control_user_email_verifications WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement('DELETE FROM commercial.control_auth_challenges WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement('DELETE FROM commercial.control_user_recovery_codes WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement('DELETE FROM commercial.control_user_totp_factors WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement('DELETE FROM commercial.control_user_password_resets WHERE control_user_id = ?', [$controlUserId]);
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events SET status = 'dead', payload = '{}'::jsonb,
    last_error = 'subject_erasure', locked_at = NULL, lock_token = NULL
WHERE aggregate_type = 'control_user' AND aggregate_id = ? AND status IN ('pending','failed','processing','dead')
SQL, [$controlUserId]);
            $connection->executeStatement('UPDATE commercial.commercial_account_memberships SET revoked_at = now() WHERE control_user_id = ? AND revoked_at IS NULL', [$controlUserId]);
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.billing_profiles SET contact_name = NULL, contact_email = NULL, updated_at = now()
WHERE contact_email = ? AND commercial_account_id IN
 (SELECT commercial_account_id FROM commercial.commercial_account_memberships WHERE control_user_id = ?)
SQL, [$user['email_normalized'], $controlUserId]);
            $alias = 'erased-' . $controlUserId . '@privacy.invalid';
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.control_users
SET status = 'disabled', email = ?, email_normalized = ?, password_hash = '!erased-disabled!',
    updated_at = now(), archived_at = COALESCE(archived_at, now()), last_login_at = NULL
WHERE id = ?
SQL, [$alias, $alias, $controlUserId]);
            $connection->executeStatement("SELECT set_config('app.privacy_lifecycle', 'off', true)");
            if ($financial > 0) {
                return $this->record($connection, $controlUserId, 'partially_completed', 'financial_evidence_retained', $financial);
            }
            return $this->record($connection, $controlUserId, 'completed', 'identity_pseudonymized', 0);
        });
    }

    /** @return array{status: string, reason_code: string, financial_evidence_count: int} */
    private function record(Connection $connection, string $userId, string $status, string $reason, int $financial): array
    {
        $connection->executeStatement('INSERT INTO commercial.privacy_erasure_cases (control_user_id, status, reason_code, financial_evidence_count, completed_at) VALUES (?, ?, ?, ?, now())', [$userId, $status, $reason, $financial]);
        return ['status' => $status, 'reason_code' => $reason, 'financial_evidence_count' => $financial];
    }
}
