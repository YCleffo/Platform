<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class ControlPrivacyCleanup
{
    public function __construct(private Connection $connection, private ControlPrivacyRetentionPolicy $policy)
    {
    }

    /** @return array{category: string, selected: int, deleted: int, dry_run: bool} */
    public function run(string $category, int $limit = 100, bool $dryRun = true): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Cleanup limit must be 1..1000.');
        }
        $days = $this->policy->days($category);
        [$table, $key, $eligible] = match ($category) {
            'verification' => ['control_user_email_verifications', 'id', "t.expires_at < now() - (? * interval '1 day') AND NOT EXISTS (SELECT 1 FROM commercial.privacy_holds h WHERE h.control_user_id = t.control_user_id AND h.released_at IS NULL)"],
            'session' => ['control_sessions', 'id', "COALESCE(t.revoked_at, t.expires_at) < now() - (? * interval '1 day') AND NOT EXISTS (SELECT 1 FROM commercial.privacy_holds h WHERE h.control_user_id = t.control_user_id AND h.released_at IS NULL)"],
            'rate_limit' => ['rate_limit_counters', 'ctid', "t.expires_at < now() - (? * interval '1 day')"],
            'idempotency' => ['idempotency_keys', 'id', "t.status <> 'pending' AND t.expires_at IS NOT NULL AND t.expires_at < now() - (? * interval '1 day')"],
            'outbox' => ['outbox_events', 'id', "t.status IN ('published','dead') AND COALESCE(t.published_at, t.created_at) < now() - (? * interval '1 day') AND NOT EXISTS (SELECT 1 FROM commercial.privacy_holds h WHERE h.released_at IS NULL)"],
            'audit' => ['audit_events', 'id', "t.occurred_at < now() - (? * interval '1 day') AND NOT EXISTS (SELECT 1 FROM commercial.privacy_holds h WHERE h.released_at IS NULL)"],
            'service_credential' => ['service_credentials', 'id', "COALESCE(CASE WHEN t.status <> 'active' THEN COALESCE(t.revoked_at, t.rotated_at) END, t.expires_at) < now() - (? * interval '1 day') AND NOT EXISTS (SELECT 1 FROM commercial.privacy_holds h WHERE h.released_at IS NULL)"],
            default => throw new \DomainException('Unknown Control Plane cleanup category.'),
        };
        [$selected, $deleted] = $this->connection->transactional(function (Connection $connection) use ($category, $table, $key, $eligible, $days, $limit, $dryRun): array {
            $ids = $connection->fetchFirstColumn("SELECT t.$key FROM commercial.$table t WHERE $eligible ORDER BY t.$key LIMIT ? FOR UPDATE SKIP LOCKED", [$days, $limit]);
            $deleted = 0;
            if (!$dryRun && $ids !== []) {
                // The cleanup database role must also be restricted in production.
                $connection->executeStatement("SELECT set_config('app.privacy_lifecycle', 'on', true)");
                $placeholders = implode(',', array_fill(0, count($ids), $key === 'ctid' ? '?::tid' : '?'));
                $deleted = (int) $connection->executeStatement("DELETE FROM commercial.$table WHERE $key IN ($placeholders)", $ids);
                $connection->executeStatement("SELECT set_config('app.privacy_lifecycle', 'off', true)");
            }
            $connection->executeStatement('INSERT INTO commercial.privacy_lifecycle_runs (category, dry_run, selected_count, deleted_count) VALUES (?, ?::boolean, ?, ?)', [$category, $dryRun ? 'true' : 'false', count($ids), $deleted]);

            return [count($ids), $deleted];
        });

        return ['category' => $category, 'selected' => $selected, 'deleted' => $deleted, 'dry_run' => $dryRun];
    }
}
