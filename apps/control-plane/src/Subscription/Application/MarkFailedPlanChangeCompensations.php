<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** A canceled provider refund is a durable operator case, never a completed compensation. */
final readonly class MarkFailedPlanChangeCompensations
{
    public function __construct(private Connection $connection) {}

    public function runBatch(int $limit, string $correlationId): int
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid compensation recovery batch.');
        }
        $ids = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT c.id FROM commercial.subscription_plan_changes c
JOIN commercial.payments p ON p.invoice_id=c.invoice_id
JOIN commercial.refunds r ON r.payment_id=p.id
 AND r.client_request_key='plan-change-unapplied-' || c.id::text
WHERE c.status='paid_unapplied' AND c.compensation_status='pending' AND r.status='canceled'
ORDER BY c.updated_at, c.id LIMIT ?
SQL, [$limit], [ParameterType::INTEGER]);
        $marked = 0;
        foreach ($ids as $id) {
            if (!is_string($id)) continue;
            $marked += $this->connection->transactional(function (Connection $db) use ($id, $correlationId): int {
                $sourceId = $db->fetchOne('SELECT source_subscription_id FROM commercial.subscription_plan_changes WHERE id=?', [$id]);
                if (!is_string($sourceId)) return 0;
                $db->executeQuery('SELECT id FROM commercial.subscriptions WHERE id=? FOR UPDATE', [$sourceId]);
                $change = $db->fetchAssociative('SELECT status, compensation_status, commercial_account_id, invoice_id FROM commercial.subscription_plan_changes WHERE id=? FOR UPDATE', [$id]);
                if ($change === false || $change['status'] !== 'paid_unapplied' || $change['compensation_status'] !== 'pending') return 0;
                $canceled = $db->fetchOne(<<<'SQL'
SELECT r.id FROM commercial.payments p JOIN commercial.refunds r ON r.payment_id=p.id
WHERE p.invoice_id=? AND r.client_request_key=? AND r.status='canceled' FOR UPDATE OF r
SQL, [$change['invoice_id'], 'plan-change-unapplied-' . $id]);
                if (!is_string($canceled)) return 0;
                $db->executeStatement("UPDATE commercial.subscription_plan_changes SET compensation_status='recovery_required', compensation_reason='provider_refund_canceled', updated_at=now() WHERE id=?", [$id]);
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.plan_change_recovery_required', 'subscription_plan_change', ?, ?, 'failure')", [$change['commercial_account_id'], $id, $correlationId]);
                return 1;
            });
        }
        return $marked;
    }
}
