<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Clears the change lock only after an authoritative full provider refund. */
final readonly class FinalizeRefundedPlanChanges
{
    public function __construct(private Connection $connection) {}

    public function runBatch(int $limit, string $correlationId): int
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid refunded plan change batch.');
        }
        $ids = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT c.id FROM commercial.subscription_plan_changes c
JOIN commercial.payments p ON p.invoice_id = c.invoice_id AND p.status = 'succeeded'
JOIN commercial.refunds r ON r.payment_id = p.id AND r.status = 'succeeded'
 AND r.client_request_key = 'plan-change-unapplied-' || c.id::text
 AND r.amount_minor = p.amount_minor
WHERE c.status = 'paid_unapplied'
ORDER BY c.requested_at, c.id LIMIT ?
SQL, [$limit], [ParameterType::INTEGER]);
        $count = 0;
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $count += $this->connection->transactional(function (Connection $db) use ($id, $correlationId): int {
                $sourceId = $db->fetchOne('SELECT source_subscription_id FROM commercial.subscription_plan_changes WHERE id = ?', [$id]);
                if (!is_string($sourceId)) {
                    return 0;
                }
                $db->executeQuery('SELECT id FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$sourceId]);
                $change = $db->fetchAssociative("SELECT id, status, target_subscription_id, commercial_account_id, invoice_id FROM commercial.subscription_plan_changes WHERE id = ? FOR UPDATE", [$id]);
                if ($change === false || $change['status'] !== 'paid_unapplied'
                    || !is_string($change['target_subscription_id']) || !is_string($change['invoice_id'])) {
                    return 0;
                }
                $refunded = $db->fetchOne(<<<'SQL'
SELECT 1 FROM commercial.payments p JOIN commercial.refunds r ON r.payment_id = p.id
WHERE p.invoice_id = ? AND p.status = 'succeeded' AND r.status = 'succeeded'
  AND r.client_request_key = ? AND r.amount_minor = p.amount_minor
SQL, [$change['invoice_id'], 'plan-change-unapplied-' . $id]);
                if ($refunded === false) {
                    return 0;
                }
                $db->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ? AND status = 'draft'", [$change['target_subscription_id']]);
                $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'refunded', updated_at = now() WHERE id = ?", [$id]);
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.plan_change_refunded', 'subscription_plan_change', ?, ?, 'success')", [$change['commercial_account_id'], $id, $correlationId]);
                return 1;
            });
        }
        return $count;
    }
}
