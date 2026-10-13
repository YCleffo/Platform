<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;

/** Issues a priced replacement's immutable initial invoice inside its plan-change transaction. */
final readonly class PlanChangeInvoiceIssuer
{
    public function issueInTransaction(Connection $db, string $accountId, string $targetSubscriptionId,
        string $profileId, int $quantity, int $unitAmountMinor, int $totalMinor,
        string $description, string $correlationId): string
    {
        if ($totalMinor < 0 || Money::multiply($unitAmountMinor, $quantity) !== $totalMinor) {
            throw new \InvalidArgumentException('Invalid plan change invoice amount.');
        }
        $item = $db->fetchAssociative(<<<'SQL'
SELECT si.id AS subscription_item_id, si.plan_price_id, pp.currency, pp.billing_period,
       p.code, p.version, p.name
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE si.subscription_id = ? FOR UPDATE OF si, pp, p
SQL, [$targetSubscriptionId]);
        if ($item === false || !is_string($item['subscription_item_id']) || !is_string($item['plan_price_id'])
            || !is_string($item['currency']) || !is_string($item['billing_period'])) {
            throw new \LogicException('Replacement subscription item is unavailable.');
        }
        $profile = $db->fetchAssociative('SELECT id, legal_name, country_code, contact_name, contact_email, tax_identifier, address::text AS address FROM commercial.billing_profiles WHERE id = ? AND commercial_account_id = ? AND archived_at IS NULL FOR UPDATE', [$profileId, $accountId]);
        if ($profile === false || !is_string($profile['address'])) {
            throw new \DomainException('Subscription billing profile is not active.');
        }
        $existing = $db->fetchOne("SELECT id FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_create' AND status IN ('issued','paid') FOR UPDATE", [$targetSubscriptionId]);
        if (is_string($existing)) {
            return $existing;
        }
        $invoiceId = $db->fetchOne(<<<'SQL'
INSERT INTO commercial.invoices
 (commercial_account_id, subscription_id, billing_profile_id, billing_reason, currency, total_minor,
  status, bill_to_legal_name, bill_to_country_code, bill_to_contact_name, bill_to_contact_email,
  bill_to_tax_identifier, bill_to_address)
VALUES (?, ?, ?, 'subscription_create', ?, 0, 'draft', ?, ?, ?, ?, ?, ?::jsonb) RETURNING id
SQL, [$accountId, $targetSubscriptionId, $profileId, $item['currency'], $profile['legal_name'],
            $profile['country_code'], $profile['contact_name'], $profile['contact_email'],
            $profile['tax_identifier'], $profile['address']]);
        if (!is_string($invoiceId)) {
            throw new \LogicException('Plan change invoice identity is unavailable.');
        }
        $db->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines
 (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name,
  billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$invoiceId, $item['subscription_item_id'], $item['plan_price_id'], $item['code'], $item['version'],
            $item['name'], $item['billing_period'], $description, $quantity, $unitAmountMinor, $totalMinor]);
        $db->executeStatement("UPDATE commercial.invoices SET total_minor = ?, status = 'issued', issued_at = now(), updated_at = now() WHERE id = ?", [$totalMinor, $invoiceId]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'invoice.plan_change_issued', 'invoice', ?, ?, 'success')", [$accountId, $invoiceId, $correlationId]);

        return $invoiceId;
    }
}
