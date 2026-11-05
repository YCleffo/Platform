<?php

declare(strict_types=1);

namespace App\PlatformRuntime\Scheduler;

use Predis\ClientInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class OperationalStatus
{
    public function __construct(private ConnectionInterface $db, private ClientInterface $redis) {}

    public function ready(): bool
    {
        try {
            $schema = $this->db->createCommand(<<<'SQL'
SELECT to_regclass('core.accounts') IS NOT NULL
   AND to_regclass('core.privacy_consents') IS NOT NULL
   AND to_regclass('core.legal_representations') IS NOT NULL
   AND to_regclass('core.legal_representation_scopes') IS NOT NULL
   AND to_regclass('platform_runtime.installations') IS NOT NULL
   AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL
   AND to_regclass('platform_runtime.background_job_status') IS NOT NULL
SQL)->queryScalar();
            if (!in_array($schema, [true, 't', '1', 1], true)) {
                return false;
            }
            if ((string) $this->redis->ping() !== 'PONG') {
                return false;
            }
            return $this->componentHealthy('backend-scheduler')
                && $this->componentHealthy('backend-email-worker');
        } catch (\Throwable) {
            return false;
        }
    }

    public function componentHealthy(string $component): bool
    {
        [$code, $enabled, $timeout] = $this->component($component);
        if (!$enabled) {
            return true;
        }
        $age = $this->db->createCommand(<<<'SQL'
SELECT EXTRACT(EPOCH FROM (clock_timestamp() - heartbeat_at))::integer
FROM platform_runtime.background_job_status WHERE job_code = :code
SQL, [':code' => $code])->queryScalar();
        return $age !== false && $age !== null && (int) $age >= 0 && (int) $age <= $timeout;
    }

    /** @return array{string,bool,int} */
    public function component(string $component): array
    {
        return match ($component) {
            'backend-scheduler' => ['__scheduler__', SchedulerConfig::enabled('CORE_SCHEDULER_ENABLED', true),
                SchedulerConfig::integer('CORE_SCHEDULER_HEALTH_TIMEOUT_SECONDS', 120, 15, 3600)],
            'backend-email-worker' => ['backend_email_worker',
                SchedulerConfig::enabled('CORE_EMAIL_WORKER_ENABLED', true),
                SchedulerConfig::integer('CORE_EMAIL_WORKER_HEALTH_TIMEOUT_SECONDS', 120, 15, 3600)],
            default => throw new \InvalidArgumentException('Unknown operational component.'),
        };
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->createCommand(<<<'SQL'
SELECT job_code, last_succeeded_at::text AS last_success, last_failed_at::text AS last_failure,
       consecutive_failures, last_duration_ms, last_processed_count, last_result,
       EXTRACT(EPOCH FROM (clock_timestamp() - heartbeat_at))::integer AS heartbeat_age_seconds
FROM platform_runtime.background_job_status ORDER BY job_code
SQL)->queryAll();
        return $rows;
    }

    /** @return array{pendingEmailCount:int,oldestPendingAgeSeconds:?int} */
    public function emailBacklog(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
SELECT count(*)::integer AS pending_email_count,
       EXTRACT(EPOCH FROM (clock_timestamp() - min(occurred_at)))::integer AS oldest_age
FROM core.outbox_events WHERE status IN ('pending','failed')
  AND event_type IN ('communication.email.requested','auth.password.reset.requested',
      'auth.email.verification.requested','auth.account.created')
SQL)->queryOne();
        /** @var array{pending_email_count: int|string, oldest_age: int|string|null} $row */
        return ['pendingEmailCount' => (int) $row['pending_email_count'],
            'oldestPendingAgeSeconds' => $row['oldest_age'] === null ? null : (int) $row['oldest_age']];
    }

    /** @return array{installationStatus:?string,entitlementSequence:?int,validUntil:?string} */
    public function entitlement(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
SELECT i.status::text AS status, i.current_entitlement_sequence AS sequence,
       d.valid_until::text AS valid_until
FROM platform_runtime.installations i
LEFT JOIN platform_runtime.entitlement_documents d
  ON d.installation_id = i.id AND d.sequence = i.current_entitlement_sequence
LIMIT 1
SQL)->queryOne();
        if (!is_array($row)) {
            return ['installationStatus' => null, 'entitlementSequence' => null, 'validUntil' => null];
        }
        /** @var array{status: string|null, sequence: int|string|null, valid_until: string|null} $row */
        return ['installationStatus' => $row['status'],
            'entitlementSequence' => (int) $row['sequence'],
            'validUntil' => $row['valid_until']];
    }
}
