<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Scheduler;

use Doctrine\DBAL\Connection;

final readonly class OperationalStatus
{
    public function __construct(private Connection $db) {}

    public function ready(): bool
    {
        try {
            $schema = $this->db->fetchOne(<<<'SQL'
SELECT to_regclass('commercial.control_users') IS NOT NULL
   AND to_regclass('commercial.control_user_email_verifications') IS NOT NULL
   AND to_regclass('commercial.control_sessions') IS NOT NULL
   AND to_regclass('commercial.control_session_refresh_history') IS NOT NULL
   AND to_regclass('commercial.rate_limit_counters') IS NOT NULL
   AND to_regclass('commercial.refunds') IS NOT NULL
   AND to_regclass('commercial.refund_items') IS NOT NULL
   AND to_regclass('commercial.receipts') IS NOT NULL
   AND to_regclass('commercial.outbox_events') IS NOT NULL
   AND to_regclass('commercial.background_job_status') IS NOT NULL
SQL);
            return in_array($schema, [true, 't', '1', 1], true) && $this->componentHealthy('control-scheduler')
                && $this->componentHealthy('control-outbox-worker');
        } catch (\Throwable) { return false; }
    }

    public function componentHealthy(string $component): bool
    {
        [$code, $enabled, $timeout] = $this->component($component);
        if (!$enabled) { return true; }
        $age = $this->db->fetchOne(<<<'SQL'
SELECT EXTRACT(EPOCH FROM (clock_timestamp() - heartbeat_at))::integer
FROM commercial.background_job_status WHERE job_code = ?
SQL, [$code]);
        return $age !== false && $age !== null && (int) $age >= 0 && (int) $age <= $timeout;
    }

    /** @return array{string,bool,int} */
    public function component(string $component): array
    {
        return match ($component) {
            'control-scheduler' => ['__scheduler__', SchedulerConfig::enabled('CONTROL_SCHEDULER_ENABLED', true),
                SchedulerConfig::integer('CONTROL_SCHEDULER_HEALTH_TIMEOUT_SECONDS', 120, 15, 3600)],
            'control-outbox-worker' => ['control_outbox_worker',
                SchedulerConfig::enabled('CONTROL_OUTBOX_WORKER_ENABLED', true),
                SchedulerConfig::integer('CONTROL_OUTBOX_WORKER_HEALTH_TIMEOUT_SECONDS', 120, 15, 3600)],
            default => throw new \InvalidArgumentException('Unknown operational component.'),
        };
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
SELECT job_code, last_succeeded_at::text AS last_success, last_failed_at::text AS last_failure,
       consecutive_failures, last_duration_ms, last_processed_count, last_result,
       EXTRACT(EPOCH FROM (clock_timestamp() - heartbeat_at))::integer AS heartbeat_age_seconds
FROM commercial.background_job_status ORDER BY job_code
SQL);
    }

    /** @return array{pendingOutboxCount:int,oldestPendingAgeSeconds:?int} */
    public function outboxBacklog(): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
SELECT count(*)::integer AS pending_count,
       EXTRACT(EPOCH FROM (clock_timestamp() - min(created_at)))::integer AS oldest_age
FROM commercial.outbox_events WHERE status IN ('pending','failed')
SQL);
        if ($row === false || !array_key_exists('pending_count', $row) || !array_key_exists('oldest_age', $row)) {
            throw new \RuntimeException('Outbox backlog status is unavailable.');
        }
        return ['pendingOutboxCount' => (int) $row['pending_count'],
            'oldestPendingAgeSeconds' => $row['oldest_age'] === null ? null : (int) $row['oldest_age']];
    }

    /** @return array{paidUnappliedCount:int,recoveryRequiredCount:int,pendingCompensationRefundCount:int,oldestPendingCompensationAgeSeconds:?int} */
    public function financialBacklog(): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
SELECT
 (SELECT count(*) FROM commercial.subscription_plan_changes WHERE status='paid_unapplied')::integer AS paid_unapplied_count,
 (SELECT count(*) FROM commercial.subscription_plan_changes WHERE compensation_status='recovery_required')::integer AS recovery_required_count,
 (SELECT count(*) FROM commercial.subscription_plan_changes c JOIN commercial.payments p ON p.invoice_id=c.invoice_id
  JOIN commercial.refunds r ON r.payment_id=p.id AND r.client_request_key='plan-change-unapplied-' || c.id::text
  WHERE c.status='paid_unapplied' AND c.compensation_status='pending' AND r.status='pending')::integer AS pending_refund_count,
 (SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(r.created_at)))::integer
  FROM commercial.subscription_plan_changes c JOIN commercial.payments p ON p.invoice_id=c.invoice_id
  JOIN commercial.refunds r ON r.payment_id=p.id AND r.client_request_key='plan-change-unapplied-' || c.id::text
  WHERE c.status='paid_unapplied' AND c.compensation_status='pending' AND r.status='pending') AS oldest_pending_age
SQL);
        if ($row === false || !isset($row['paid_unapplied_count'], $row['recovery_required_count'], $row['pending_refund_count'])
            || !array_key_exists('oldest_pending_age', $row)) {
            throw new \RuntimeException('Financial backlog status is unavailable.');
        }
        return ['paidUnappliedCount' => (int) $row['paid_unapplied_count'],
            'recoveryRequiredCount' => (int) $row['recovery_required_count'],
            'pendingCompensationRefundCount' => (int) $row['pending_refund_count'],
            'oldestPendingCompensationAgeSeconds' => $row['oldest_pending_age'] === null ? null : (int) $row['oldest_pending_age']];
    }
}
