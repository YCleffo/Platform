<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** A plan change replaces the subscription; the historical active items stay immutable. */
final readonly class RequestSubscriptionPlanChange
{
    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
        private ProrationCalculator $proration,
        private ApplySubscriptionPlanChange $application,
    ) {}

    /** @return array{id: string, status: string, change_kind: string, source_subscription_id: string, target_subscription_id: string, invoice_id: ?string, amount_due_minor: int, currency: string} */
    public function request(string $userId, string $accountId, string $sourceSubscriptionId, string $targetPriceId,
        int $quantity, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($sourceSubscriptionId)
            || !Uuid::isValid($targetPriceId) || !Uuid::isValid($correlationId) || $quantity <= 0) {
            throw new \InvalidArgumentException('Invalid plan change request.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $requestHash = hash('sha256', json_encode([
            strtolower($accountId), strtolower($sourceSubscriptionId), strtolower($targetPriceId), $quantity,
        ], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $db) use ($userId, $accountId, $sourceSubscriptionId,
            $targetPriceId, $quantity, $idempotencyKey, $correlationId, $requestHash): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $source = $db->fetchAssociative('SELECT id, status, billing_status, billing_profile_id, current_period_start, current_period_end FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$sourceSubscriptionId, $accountId]);
            if ($source === false) {
                throw new \DomainException('Source subscription was not found.');
            }
            $prior = $db->fetchAssociative("SELECT id, status, change_kind, source_subscription_id, target_subscription_id, invoice_id, amount_due_minor, currency, encode(request_sha256, 'hex') AS request_hash FROM commercial.subscription_plan_changes WHERE commercial_account_id = ? AND idempotency_key = ? FOR UPDATE", [$accountId, $idempotencyKey]);
            if ($prior !== false) {
                if (!is_string($prior['request_hash']) || !hash_equals($prior['request_hash'], $requestHash)) {
                    throw new \DomainException('Idempotency key was reused with a different plan change.');
                }
                return $this->result($prior);
            }
            if ($source['status'] !== 'active' || $source['billing_status'] !== 'current'
                || !is_string($source['current_period_start']) || !is_string($source['current_period_end'])
                || !is_string($source['billing_profile_id'])) {
                throw new \DomainException('Only a current active subscription can change plan.');
            }
            $nowText = $db->fetchOne('SELECT now()::text');
            if (!is_string($nowText)) {
                throw new \LogicException('Database time is unavailable.');
            }
            $now = new \DateTimeImmutable($nowText);
            $periodStart = new \DateTimeImmutable($source['current_period_start']);
            $periodEnd = new \DateTimeImmutable($source['current_period_end']);
            if ($now < $periodStart || $now >= $periodEnd) {
                throw new \DomainException('Current subscription period has ended.');
            }
            if ($db->fetchOne("SELECT 1 FROM commercial.subscription_plan_changes WHERE source_subscription_id = ? AND status IN ('scheduled','payment_required','paid_unapplied')", [$sourceSubscriptionId]) !== false) {
                throw new \DomainException('Another plan change is still open.');
            }
            if ($db->fetchOne("SELECT 1 FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_renewal' AND service_period_start = ?::timestamptz AND status IN ('issued','paid')", [$sourceSubscriptionId, $source['current_period_end']]) !== false) {
                throw new \DomainException('A renewal invoice already fixes the next period terms.');
            }
            if ($db->fetchOne("SELECT 1 FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'draft'", [$accountId]) !== false) {
                throw new \DomainException('A draft subscription already exists.');
            }
            $items = $db->fetchAllAssociative(<<<'SQL'
SELECT si.id, si.plan_price_id, si.quantity, pp.currency, pp.amount_minor, pp.billing_period
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
WHERE si.subscription_id = ? AND si.valid_from <= ?::timestamptz
  AND (si.valid_until IS NULL OR ?::timestamptz < si.valid_until)
FOR UPDATE OF si, pp
SQL, [$sourceSubscriptionId, $nowText, $nowText]);
            if (count($items) !== 1) {
                throw new \DomainException('Plan change requires exactly one current subscription item.');
            }
            $old = $items[0];
            $new = $db->fetchAssociative(<<<'SQL'
SELECT pp.id, pp.currency, pp.amount_minor, pp.billing_period, p.code, p.version, p.name, p.status AS plan_status
FROM commercial.plan_prices pp JOIN commercial.plans p ON p.id = pp.plan_id
WHERE pp.id = ? FOR UPDATE OF pp, p
SQL, [$targetPriceId]);
            if ($new === false || $new['plan_status'] !== 'published'
                || $new['currency'] !== $old['currency'] || $new['billing_period'] !== $old['billing_period']
                || !in_array($new['billing_period'], ['month', 'year'], true)) {
                throw new \DomainException('Target plan must be published with the same currency and billing period.');
            }
            if ($targetPriceId === $old['plan_price_id'] && $quantity === (int) $old['quantity']) {
                throw new \DomainException('The requested plan is already active.');
            }
            $oldUnit = Money::nonNegativeBigInt($old['amount_minor']);
            $newUnit = Money::nonNegativeBigInt($new['amount_minor']);
            $oldTotal = Money::multiply($oldUnit, (int) $old['quantity']);
            $newTotal = Money::multiply($newUnit, $quantity);
            if ($oldTotal === $newTotal) {
                throw new \DomainException('Equal-price plan changes require a separate product policy.');
            }
            $calculation = $this->proration->calculate($periodStart, $periodEnd, $now,
                $oldUnit, $newUnit, (int) $old['quantity'], $quantity, (string) $old['currency']);
            $kind = $newTotal > $oldTotal ? 'upgrade' : 'downgrade';
            $amountDue = $kind === 'upgrade' ? max(0, $calculation['net_minor']) : $newTotal;
            $targetSubscriptionId = $db->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$accountId, $source['billing_profile_id']]);
            if (!is_string($targetSubscriptionId)) {
                throw new \LogicException('Replacement subscription identity is unavailable.');
            }
            $targetItemId = $db->fetchOne('INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, ?, ?::timestamptz) RETURNING id', [$targetSubscriptionId, $targetPriceId, $quantity, $nowText]);
            if (!is_string($targetItemId)) {
                throw new \LogicException('Replacement subscription item is unavailable.');
            }
            $invoiceId = $kind === 'upgrade' && $amountDue > 0 ? $this->issueUpgradeInvoice($db, $accountId, $targetSubscriptionId,
                $targetItemId, $targetPriceId, $source['billing_profile_id'], $new, $amountDue, $correlationId) : null;
            $status = $invoiceId === null ? 'scheduled' : 'payment_required';
            $changeId = $db->fetchOne(<<<'SQL'
INSERT INTO commercial.subscription_plan_changes
 (commercial_account_id, source_subscription_id, target_subscription_id, old_plan_price_id,
  new_plan_price_id, old_quantity, new_quantity, change_kind, status, idempotency_key,
  request_sha256, calculation, currency, proration_net_minor, amount_due_minor,
  period_start, period_end, invoice_id)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, ?::timestamptz, ?::timestamptz, ?)
RETURNING id
SQL, [$accountId, $sourceSubscriptionId, $targetSubscriptionId, $old['plan_price_id'], $targetPriceId,
                $old['quantity'], $quantity, $kind, $status, $idempotencyKey, $requestHash,
                json_encode($calculation, JSON_THROW_ON_ERROR), $old['currency'], $calculation['net_minor'],
                $amountDue, $source['current_period_start'], $source['current_period_end'], $invoiceId]);
            if (!is_string($changeId)) {
                throw new \LogicException('Plan change identity is unavailable.');
            }
            $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'subscription.plan_change_requested', 'subscription_plan_change', ?, ?, 'success')", [$userId, $accountId, $changeId, $correlationId]);
            if ($kind === 'upgrade' && $amountDue === 0) {
                $this->application->applyInTransaction($db, $changeId, $now, $correlationId);
                $status = 'applied';
            }

            return ['id' => $changeId, 'status' => $status, 'change_kind' => $kind,
                'source_subscription_id' => $sourceSubscriptionId, 'target_subscription_id' => $targetSubscriptionId,
                'invoice_id' => $invoiceId, 'amount_due_minor' => $amountDue, 'currency' => (string) $old['currency']];
        });
    }

    /** @param array<string, mixed> $new */
    private function issueUpgradeInvoice(Connection $db, string $accountId, string $targetSubscriptionId,
        string $targetItemId, string $targetPriceId, string $profileId, array $new, int $amount,
        string $correlationId): string
    {
        $profile = $db->fetchAssociative('SELECT id, legal_name, country_code, contact_name, contact_email, tax_identifier, address::text AS address FROM commercial.billing_profiles WHERE id = ? AND commercial_account_id = ? AND archived_at IS NULL FOR UPDATE', [$profileId, $accountId]);
        if ($profile === false || !is_string($profile['address'])) {
            throw new \DomainException('Subscription billing profile is not active.');
        }
        $invoiceId = $db->fetchOne(<<<'SQL'
INSERT INTO commercial.invoices
 (commercial_account_id, subscription_id, billing_profile_id, billing_reason, currency, total_minor,
  status, bill_to_legal_name, bill_to_country_code, bill_to_contact_name, bill_to_contact_email,
  bill_to_tax_identifier, bill_to_address)
VALUES (?, ?, ?, 'subscription_create', ?, 0, 'draft', ?, ?, ?, ?, ?, ?::jsonb) RETURNING id
SQL, [$accountId, $targetSubscriptionId, $profileId, $new['currency'], $profile['legal_name'],
            $profile['country_code'], $profile['contact_name'], $profile['contact_email'],
            $profile['tax_identifier'], $profile['address']]);
        if (!is_string($invoiceId)) {
            throw new \LogicException('Upgrade invoice identity is unavailable.');
        }
        $db->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines
 (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name,
  billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
SQL, [$invoiceId, $targetItemId, $targetPriceId, $new['code'], $new['version'], $new['name'],
            $new['billing_period'], 'Prorated subscription upgrade', $amount, $amount]);
        $db->executeStatement("UPDATE commercial.invoices SET total_minor = ?, status = 'issued', issued_at = now(), updated_at = now() WHERE id = ?", [$amount, $invoiceId]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'invoice.plan_upgrade_issued', 'invoice', ?, ?, 'success')", [$accountId, $invoiceId, $correlationId]);

        return $invoiceId;
    }

    /** @param array<string, mixed> $row
     * @return array{id: string, status: string, change_kind: string, source_subscription_id: string, target_subscription_id: string, invoice_id: ?string, amount_due_minor: int, currency: string}
     */
    private function result(array $row): array
    {
        foreach (['id', 'status', 'change_kind', 'source_subscription_id', 'target_subscription_id', 'currency'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new \LogicException('Plan change result is unavailable.');
            }
        }
        return ['id' => $row['id'], 'status' => $row['status'], 'change_kind' => $row['change_kind'],
            'source_subscription_id' => $row['source_subscription_id'], 'target_subscription_id' => $row['target_subscription_id'],
            'invoice_id' => is_string($row['invoice_id'] ?? null) ? $row['invoice_id'] : null,
            'amount_due_minor' => Money::nonNegativeBigInt($row['amount_due_minor'] ?? null), 'currency' => $row['currency']];
    }
}
