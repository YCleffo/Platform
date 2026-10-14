<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Refund\Application\ReserveUnappliedPlanChangeRefund;
use Doctrine\DBAL\Connection;

/** Applies a replacement only inside the same transaction as a paid invoice. */
final readonly class ApplySubscriptionPlanChange
{
    public function __construct(private SubscriptionPeriodCalculator $periods,
        private ?ReserveUnappliedPlanChangeRefund $refunds = null) {}

    /** @return 'applied'|'paid_unapplied' */
    public function applyInTransaction(Connection $db, string $changeId, \DateTimeImmutable $instant, string $correlationId): string
    {
        $change = $db->fetchAssociative('SELECT * FROM commercial.subscription_plan_changes WHERE id = ? FOR UPDATE', [$changeId]);
        if ($change === false) {
            throw new \DomainException('Plan change was not found.');
        }
        if ($change['status'] === 'applied') {
            return 'applied';
        }
        if (!in_array($change['status'], ['scheduled', 'payment_required'], true)) {
            throw new \DomainException('Plan change cannot be applied from its current state.');
        }
        if (!is_string($change['source_subscription_id']) || !is_string($change['target_subscription_id'])
            || !is_string($change['commercial_account_id']) || !is_string($change['period_end'])
            || !is_string($change['change_kind'])) {
            throw new \LogicException('Plan change identity is invalid.');
        }
        $source = $db->fetchAssociative('SELECT id, status, current_period_end FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$change['source_subscription_id'], $change['commercial_account_id']]);
        $target = $db->fetchAssociative('SELECT id, status FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$change['target_subscription_id'], $change['commercial_account_id']]);
        if ($source === false || $target === false || $target['status'] !== 'draft') {
            throw new \LogicException('Replacement subscription state is unavailable.');
        }
        $invoiceId = $change['invoice_id'];
        if ($invoiceId !== null) {
            $paid = $db->fetchOne("SELECT 1 FROM commercial.invoices WHERE id = ? AND subscription_id = ? AND commercial_account_id = ? AND status = 'paid'", [$invoiceId, $change['target_subscription_id'], $change['commercial_account_id']]);
            if ($paid === false) {
                throw new \DomainException('Plan change requires its paid invoice.');
            }
        } elseif ((int) $change['amount_due_minor'] !== 0) {
            throw new \DomainException('Paid plan change invoice is missing.');
        }
        $instant = $instant->setTimezone(new \DateTimeZone('UTC'));
        $settlementInstant = $instant;
        if ($change['change_kind'] === 'upgrade') {
            $databaseNow = $db->fetchOne('SELECT now()::text');
            if (!is_string($databaseNow)) {
                throw new \LogicException('Database time is unavailable.');
            }
            $instant = min($instant, new \DateTimeImmutable($databaseNow));
        }
        $start = $instant->format('Y-m-d H:i:s.uP');
        $oldEnd = new \DateTimeImmutable($change['period_end']);
        if ($change['change_kind'] === 'upgrade' && $settlementInstant >= $oldEnd) {
            return $this->paidWithoutAccess($db, $change, $correlationId);
        }
        if ($change['change_kind'] === 'downgrade' && $instant < $oldEnd) {
            throw new \DomainException('Scheduled downgrade cannot apply before the period boundary.');
        }
        if ($change['change_kind'] === 'upgrade' && ($source['status'] !== 'active'
            || $source['current_period_end'] !== $change['period_end'])) {
            return $this->paidWithoutAccess($db, $change, $correlationId);
        }
        if ($change['change_kind'] === 'downgrade' && !in_array($source['status'], ['active', 'expired'], true)) {
            return $this->paidWithoutAccess($db, $change, $correlationId);
        }
        if ($change['change_kind'] === 'downgrade' && $source['status'] === 'active'
            && $source['current_period_end'] !== $change['period_end']) {
            return $this->paidWithoutAccess($db, $change, $correlationId);
        }
        $end = $oldEnd;
        if ($change['change_kind'] === 'downgrade') {
            $period = $db->fetchOne('SELECT pp.billing_period FROM commercial.plan_prices pp WHERE pp.id = ?', [$change['new_plan_price_id']]);
            if (!is_string($period) || !in_array($period, ['month', 'year'], true)) {
                throw new \LogicException('Downgrade billing period is unavailable.');
            }
            $end = $this->periods->calculate($instant, $period)['end'];
        }
        $endText = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        if ($source['status'] === 'active') {
            $closed = $db->executeStatement("UPDATE commercial.usage_periods SET status = 'closed', closed_at = now() WHERE subscription_id = ? AND status = 'open'", [$source['id']]);
            if ($closed !== 1) {
                throw new \LogicException('Source usage period is unavailable.');
            }
            $db->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ?", [$source['id']]);
            $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.cancelled', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text))", [$source['id'], $source['id'], $change['commercial_account_id']]);
        }
        $db->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = ?::timestamptz, current_period_end = ?::timestamptz, updated_at = now() WHERE id = ?", [$start, $endText, $target['id']]);
        $db->executeStatement("INSERT INTO commercial.usage_periods (subscription_id, starts_at, ends_at, status) VALUES (?, ?::timestamptz, ?::timestamptz, 'open')", [$target['id'], $start, $endText]);
        $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'applied', applied_at = ?::timestamptz, updated_at = now() WHERE id = ?", [$start, $changeId]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.plan_changed', 'subscription_plan_change', ?, ?, 'success')", [$change['commercial_account_id'], $changeId, $correlationId]);
        $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.activated', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'commercial_account_id', ?::text, 'current_period_start', to_jsonb(?::timestamptz), 'current_period_end', to_jsonb(?::timestamptz)))", [$target['id'], $target['id'], $change['commercial_account_id'], $start, $endText]);

        return 'applied';
    }

    /** @param array<string, mixed> $change
     * @return 'paid_unapplied'
     */
    private function paidWithoutAccess(Connection $db, array $change, string $correlationId): string
    {
        if ($change['invoice_id'] === null) {
            throw new \DomainException('Unpaid plan change cannot be applied.');
        }
        $db->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ? AND status = 'draft'", [$change['target_subscription_id']]);
        $refundId = ($this->refunds ?? new ReserveUnappliedPlanChangeRefund())->reserveInTransaction($db,
            $change['id'], $change['invoice_id'], $change['commercial_account_id'], $correlationId);
        $reason = null;
        if ($refundId === null) {
            $provider = $db->fetchOne(<<<'SQL'
SELECT ppa.provider_code FROM commercial.payments p
JOIN commercial.payment_attempts a ON a.payment_id=p.id AND a.status='succeeded'
JOIN commercial.payment_provider_accounts ppa ON ppa.id=a.provider_account_id
WHERE p.invoice_id=?
SQL, [$change['invoice_id']]);
            $reason = $provider === 'yookassa' ? 'unsupported_fiscal_configuration' : 'unsupported_provider';
        }
        $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'paid_unapplied', compensation_status = ?, compensation_reason = ?, updated_at = now() WHERE id = ?",
            [$refundId === null ? 'recovery_required' : 'pending', $reason, $change['id']]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.plan_change_refund_required', 'subscription_plan_change', ?, ?, 'failure')", [$change['commercial_account_id'], $change['id'], $correlationId]);
        if ($reason !== null) {
            $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'subscription.plan_change_recovery_required', 'subscription_plan_change', ?, ?, 'failure')",
                [$change['commercial_account_id'], $change['id'], $correlationId]);
        }
        $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.plan_change_refund_required', 'subscription_plan_change', ?, jsonb_build_object('change_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'refund_id', ?::text))", [$change['id'], $change['id'], $change['invoice_id'], $change['commercial_account_id'], $refundId]);

        return 'paid_unapplied';
    }
}
