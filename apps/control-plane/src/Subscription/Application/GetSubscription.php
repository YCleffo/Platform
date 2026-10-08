<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetSubscription
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $userId, string $accountId, string $subscriptionId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $subscription = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, status, billing_status, past_due_since, grace_until, commercial_account_id, billing_profile_id, current_period_start, current_period_end, created_at, updated_at
FROM commercial.subscriptions
WHERE id = ? AND commercial_account_id = ?
SQL, [$subscriptionId, $accountId]);
        if ($subscription === false) {
            throw new \DomainException('Subscription was not found.');
        }

        $items = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT si.id, si.quantity, si.valid_from, si.valid_until,
       p.id AS plan_id, p.code AS plan_code, p.version AS plan_version, p.name AS plan_name,
       pp.id AS price_id, pp.currency, pp.amount_minor, pp.billing_period
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE si.subscription_id = ?
ORDER BY si.created_at, si.id
SQL, [$subscriptionId]);
        $subscription['items'] = array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'quantity' => (int) $item['quantity'],
            'valid_from' => $item['valid_from'],
            'valid_until' => $item['valid_until'],
            'plan' => [
                'id' => $item['plan_id'],
                'code' => $item['plan_code'],
                'version' => (int) $item['plan_version'],
                'name' => $item['plan_name'],
            ],
            'price' => [
                'id' => $item['price_id'],
                'currency' => $item['currency'],
                'amount_minor' => (int) $item['amount_minor'],
                'billing_period' => $item['billing_period'],
            ],
        ], $items);
        $renewal = $this->connection->fetchAssociative(<<<'SQL'
SELECT i.id AS invoice_id, i.status AS invoice_status, i.service_period_start, i.service_period_end,
       p.id AS payment_id, p.status AS payment_status
FROM commercial.invoices i
LEFT JOIN commercial.payments p ON p.invoice_id = i.id
WHERE i.subscription_id = ? AND i.billing_reason = 'subscription_renewal'
  AND i.status IN ('issued','paid') AND i.service_period_start = ?::timestamptz
ORDER BY i.created_at DESC LIMIT 1
SQL, [$subscriptionId, $subscription['current_period_end']]);
        $subscription['renewal_required'] = $subscription['status'] === 'active' && $renewal === false;
        $subscription['renewal'] = $renewal === false ? null : [
            'invoice_id' => $renewal['invoice_id'],
            'invoice_status' => $renewal['invoice_status'],
            'payment_id' => $renewal['payment_id'],
            'payment_status' => $renewal['payment_status'],
            'service_period_start' => $renewal['service_period_start'],
            'service_period_end' => $renewal['service_period_end'],
        ];

        return $subscription;
    }
}
