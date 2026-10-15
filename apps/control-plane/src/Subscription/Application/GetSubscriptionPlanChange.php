<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetSubscriptionPlanChange
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization) {}

    /** @return array<string, mixed> */
    public function get(string $userId, string $accountId, string $changeId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($changeId)) {
            throw new \InvalidArgumentException('Invalid plan change identifier.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT c.id, c.source_subscription_id, c.target_subscription_id, c.old_plan_price_id,
       c.new_plan_price_id, c.old_quantity, c.new_quantity, c.change_kind, c.status,
       c.compensation_status, c.compensation_reason,
       c.calculation::text AS calculation, c.currency, c.proration_net_minor,
       c.amount_due_minor, c.period_start, c.period_end, c.invoice_id, c.applied_at,
       i.status AS invoice_status, p.id AS payment_id, p.status AS payment_status,
       CASE WHEN c.status='refunded' AND EXISTS (
         SELECT 1 FROM commercial.external_compensation_attestations e
         WHERE e.plan_change_id=c.id AND e.status='approved'
       ) THEN 'external'
       WHEN c.status='refunded' AND EXISTS (
         SELECT 1 FROM commercial.refunds r WHERE r.payment_id=p.id AND r.status='succeeded'
           AND r.client_request_key='plan-change-unapplied-' || c.id::text
       ) THEN 'provider' ELSE NULL END AS compensation_origin
FROM commercial.subscription_plan_changes c
LEFT JOIN commercial.invoices i ON i.id = c.invoice_id
LEFT JOIN commercial.payments p ON p.invoice_id = i.id
WHERE c.id = ? AND c.commercial_account_id = ?
SQL, [$changeId, $accountId]);
        if ($row === false || !is_string($row['calculation'])) {
            throw new \DomainException('Plan change was not found.');
        }
        $row['calculation'] = json_decode($row['calculation'], true, 32, JSON_THROW_ON_ERROR);
        $row['old_quantity'] = (int) $row['old_quantity'];
        $row['new_quantity'] = (int) $row['new_quantity'];
        $row['proration_net_minor'] = (int) $row['proration_net_minor'];
        $row['amount_due_minor'] = Money::nonNegativeBigInt($row['amount_due_minor']);

        return $row;
    }
}
