<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class IssueRenewalInvoice
{
    public function __construct(private Connection $connection, private SubscriptionPeriodCalculator $periods)
    {
    }

    /** @return array{id: string, subscription_id: string, billing_reason: string, status: string, currency: string, total_minor: int, service_period_start: string, service_period_end: string} */
    public function issue(string $accountId, string $subscriptionId, string $correlationId): array
    {
        if (!Uuid::isValid($accountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid renewal invoice request.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($accountId, $subscriptionId, $correlationId): array {
            $subscription = $connection->fetchAssociative('SELECT * FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $accountId]);
            if ($subscription === false) {
                throw new \DomainException('Subscription was not found.');
            }

            return $this->issueLocked($connection, $subscription, $correlationId);
        });
    }

    public function prepareDue(\DateTimeImmutable $instant, int $leadDays, int $limit, string $correlationId): int
    {
        if ($leadDays < 0 || $leadDays > 365 || $limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid renewal preparation request.');
        }
        $cutoff = $instant->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('+%d days', $leadDays))->format('Y-m-d H:i:s.uP');

        return $this->connection->transactional(function (Connection $connection) use ($cutoff, $limit, $correlationId): int {
            $rows = $connection->fetchAllAssociative(<<<'SQL'
SELECT s.*
FROM commercial.subscriptions s
WHERE s.status = 'active' AND s.current_period_end <= ?::timestamptz
  AND NOT EXISTS (
    SELECT 1 FROM commercial.invoices i
    WHERE i.subscription_id = s.id AND i.billing_reason = 'subscription_renewal'
      AND i.service_period_start = s.current_period_end AND i.status IN ('issued','paid')
  )
ORDER BY s.current_period_end, s.id
LIMIT ?
FOR UPDATE OF s SKIP LOCKED
SQL, [$cutoff, $limit], [ParameterType::STRING, ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $this->issueLocked($connection, $row, $correlationId);
            }

            return count($rows);
        });
    }

    /** @param array<string, mixed> $subscription
     * @return array{id: string, subscription_id: string, billing_reason: string, status: string, currency: string, total_minor: int, service_period_start: string, service_period_end: string}
     */
    private function issueLocked(Connection $connection, array $subscription, string $correlationId): array
    {
        foreach (['id', 'commercial_account_id', 'billing_profile_id', 'current_period_end'] as $field) {
            if (!is_string($subscription[$field] ?? null)) {
                throw new \LogicException('Active subscription renewal contract is incomplete.');
            }
        }
        if ($subscription['status'] !== 'active') {
            throw new \DomainException('Renewal invoice requires an active subscription.');
        }
        $items = $connection->fetchAllAssociative(<<<'SQL'
SELECT si.id AS subscription_item_id, si.plan_price_id, si.quantity,
       pp.currency, pp.amount_minor, pp.billing_period,
       p.code AS plan_code, p.version AS plan_version, p.name AS plan_name
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE si.subscription_id = ?
ORDER BY si.created_at, si.id
FOR UPDATE OF si, pp, p
SQL, [$subscription['id']]);
        if ($items === []) {
            throw new \DomainException('Subscription must contain at least one item.');
        }
        $currency = null;
        $billingPeriod = null;
        $total = 0;
        $lines = [];
        foreach ($items as $item) {
            if (!is_string($item['currency']) || !is_string($item['billing_period']) || !in_array($item['billing_period'], ['month', 'year'], true)) {
                throw new \DomainException('Subscription renewal contract is invalid.');
            }
            if (($currency !== null && $currency !== $item['currency']) || ($billingPeriod !== null && $billingPeriod !== $item['billing_period'])) {
                throw new \DomainException('Subscription renewal contract has incompatible terms.');
            }
            $quantity = (int) $item['quantity'];
            $unit = Money::nonNegativeBigInt($item['amount_minor']);
            $lineTotal = Money::multiply($unit, $quantity);
            $total = Money::add($total, $lineTotal);
            $currency = $item['currency'];
            $billingPeriod = $item['billing_period'];
            $lines[] = [$item, $quantity, $unit, $lineTotal];
        }
        $start = (new \DateTimeImmutable($subscription['current_period_end']))->setTimezone(new \DateTimeZone('UTC'));
        $period = $this->periods->calculate($start, (string) $billingPeriod);
        $startText = $period['start']->format('Y-m-d H:i:s.uP');
        $endText = $period['end']->format('Y-m-d H:i:s.uP');
        $existing = $connection->fetchAssociative("SELECT id, subscription_id, billing_reason, status, currency, total_minor, service_period_start, service_period_end FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_renewal' AND service_period_start = ?::timestamptz AND service_period_end = ?::timestamptz AND status IN ('issued','paid') FOR UPDATE", [$subscription['id'], $startText, $endText]);
        if ($existing !== false) {
            return $this->normalize($existing);
        }
        $profile = $connection->fetchAssociative('SELECT id, legal_name, country_code, contact_name, contact_email, tax_identifier, address::text AS address FROM commercial.billing_profiles WHERE id = ? AND commercial_account_id = ? AND archived_at IS NULL FOR UPDATE', [$subscription['billing_profile_id'], $subscription['commercial_account_id']]);
        if ($profile === false || !is_string($profile['address'])) {
            throw new \DomainException('Subscription billing profile is not active.');
        }
        $invoiceId = $connection->fetchOne(<<<'SQL'
INSERT INTO commercial.invoices
 (commercial_account_id, subscription_id, billing_profile_id, billing_reason, service_period_start, service_period_end,
  currency, total_minor, status, bill_to_legal_name, bill_to_country_code, bill_to_contact_name,
  bill_to_contact_email, bill_to_tax_identifier, bill_to_address)
VALUES (?, ?, ?, 'subscription_renewal', ?::timestamptz, ?::timestamptz, ?, 0, 'draft', ?, ?, ?, ?, ?, ?::jsonb)
RETURNING id
SQL, [$subscription['commercial_account_id'], $subscription['id'], $profile['id'], $startText, $endText, $currency, $profile['legal_name'], $profile['country_code'], $profile['contact_name'], $profile['contact_email'], $profile['tax_identifier'], $profile['address']]);
        if (!is_string($invoiceId)) {
            throw new \LogicException('Renewal invoice identity is unavailable.');
        }
        foreach ($lines as [$item, $quantity, $unit, $lineTotal]) {
            $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines
 (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$invoiceId, $item['subscription_item_id'], $item['plan_price_id'], $item['plan_code'], $item['plan_version'], $item['plan_name'], $item['billing_period'], sprintf('%s — %s subscription renewal', $item['plan_name'], $item['billing_period'] === 'month' ? 'monthly' : 'yearly'), $quantity, $unit, $lineTotal]);
        }
        $issued = $connection->fetchAssociative("UPDATE commercial.invoices SET total_minor = ?, status = 'issued', issued_at = now(), updated_at = now() WHERE id = ? RETURNING id, subscription_id, billing_reason, status, currency, total_minor, service_period_start, service_period_end", [$total, $invoiceId]);
        if ($issued === false) {
            throw new \LogicException('Renewal invoice state is unavailable.');
        }
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'invoice.renewal_issued', 'invoice', ?, ?, 'success')", [$subscription['commercial_account_id'], $invoiceId, $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewal_invoice_issued', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'service_period_start', to_jsonb(?::timestamptz), 'service_period_end', to_jsonb(?::timestamptz)))", [$subscription['id'], $subscription['id'], $invoiceId, $subscription['commercial_account_id'], $startText, $endText]);

        return $this->normalize($issued);
    }

    /** @param array<string, mixed> $row
     * @return array{id: string, subscription_id: string, billing_reason: string, status: string, currency: string, total_minor: int, service_period_start: string, service_period_end: string}
     */
    private function normalize(array $row): array
    {
        foreach (['id', 'subscription_id', 'billing_reason', 'status', 'currency', 'service_period_start', 'service_period_end'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new \LogicException('Renewal invoice result is invalid.');
            }
        }

        return ['id' => $row['id'], 'subscription_id' => $row['subscription_id'], 'billing_reason' => $row['billing_reason'], 'status' => $row['status'], 'currency' => $row['currency'], 'total_minor' => Money::nonNegativeBigInt($row['total_minor']), 'service_period_start' => $row['service_period_start'], 'service_period_end' => $row['service_period_end']];
    }
}
