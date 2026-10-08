<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Billing\Application\RenewalCollectionLifecycle;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\AdvanceSubscriptionPeriod;
use Doctrine\DBAL\Connection;

final readonly class SettlePayment
{
    public function __construct(private ActivateSubscription $activation, private ?AdvanceSubscriptionPeriod $advancement = null, private ?RenewalCollectionLifecycle $renewalLifecycle = null)
    {
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $invoice
     * @return array{id: string, invoice_id: string, status: 'succeeded', currency: string, amount_minor: int, created_at: string, succeeded_at: string}
     */
    public function succeed(Connection $connection, array $payment, array $invoice, \DateTimeImmutable $settlementInstant, string $correlationId): array
    {
        foreach (['id', 'invoice_id', 'commercial_account_id', 'currency', 'created_at'] as $field) {
            if (!is_string($payment[$field] ?? null)) {
                throw new \LogicException('Payment settlement identity is unavailable.');
            }
        }
        if (!is_string($invoice['id'] ?? null) || !is_string($invoice['subscription_id'] ?? null)
            || !is_string($invoice['commercial_account_id'] ?? null) || !is_string($invoice['currency'] ?? null)
            || !is_string($invoice['billing_reason'] ?? null)) {
            throw new \LogicException('Invoice settlement identity is unavailable.');
        }
        if ($payment['invoice_id'] !== $invoice['id'] || $payment['commercial_account_id'] !== $invoice['commercial_account_id']
            || Money::nonNegativeBigInt($payment['amount_minor'] ?? null) !== Money::nonNegativeBigInt($invoice['total_minor'] ?? null)
            || $payment['currency'] !== $invoice['currency']) {
            throw new \DomainException('Payment does not match its invoice.');
        }
        if ($payment['status'] === 'succeeded' && $invoice['status'] === 'paid') {
            if (!is_string($payment['succeeded_at'] ?? null)) {
                throw new \LogicException('Succeeded payment timestamp is unavailable.');
            }

            return $this->result($payment, $payment['succeeded_at']);
        }
        if ($payment['status'] !== 'pending' || $invoice['status'] !== 'issued') {
            throw new \DomainException('Payment or invoice is not payable.');
        }

        $settledAt = $settlementInstant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $connection->executeStatement("UPDATE commercial.payments SET status = 'succeeded', succeeded_at = ?::timestamptz, updated_at = now() WHERE id = ?", [$settledAt, $payment['id']]);
        $connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = ?::timestamptz, updated_at = now() WHERE id = ?", [$settledAt, $invoice['id']]);
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'payment.succeeded', 'payment', ?, ?, 'success')", [$payment['commercial_account_id'], $payment['id'], $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'invoice.paid', 'invoice', ?, ?, 'success')", [$payment['commercial_account_id'], $invoice['id'], $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('payment.succeeded', 'payment', ?, jsonb_build_object('payment_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'amount_minor', ?::bigint, 'currency', ?::text))", [$payment['id'], $payment['id'], $invoice['id'], $payment['commercial_account_id'], $payment['amount_minor'], $payment['currency']]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('invoice.paid', 'invoice', ?, jsonb_build_object('invoice_id', ?::text, 'payment_id', ?::text, 'subscription_id', ?::text, 'commercial_account_id', ?::text))", [$invoice['id'], $invoice['id'], $payment['id'], $invoice['subscription_id'], $payment['commercial_account_id']]);
        if ($invoice['billing_reason'] === 'subscription_create') {
            $this->activation->activateInTransaction($connection, $payment['commercial_account_id'], $invoice['subscription_id'], $settlementInstant, $correlationId);
        } elseif ($invoice['billing_reason'] === 'subscription_renewal') {
            $this->renewalLifecycle?->succeededInTransaction($connection, $invoice, $payment['id'], $correlationId);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewal_paid', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'payment_id', ?::text, 'commercial_account_id', ?::text))", [$invoice['subscription_id'], $invoice['subscription_id'], $invoice['id'], $payment['id'], $payment['commercial_account_id']]);
            $subscription = $connection->fetchAssociative('SELECT status, current_period_end FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$invoice['subscription_id']]);
            if ($subscription !== false && $subscription['status'] === 'active' && is_string($subscription['current_period_end'])
                && $settlementInstant >= new \DateTimeImmutable($subscription['current_period_end'])) {
                ($this->advancement ?? new AdvanceSubscriptionPeriod($connection))->advanceInTransaction($connection, $payment['commercial_account_id'], $invoice['subscription_id'], $settlementInstant, $correlationId);
            }
        } else {
            throw new \DomainException('Unsupported invoice billing reason.');
        }

        $payment['status'] = 'succeeded';
        $payment['succeeded_at'] = $settledAt;

        return $this->result($payment, $settledAt);
    }

    /** @param array<string, mixed> $payment
     * @return array{id: string, invoice_id: string, status: 'succeeded', currency: string, amount_minor: int, created_at: string, succeeded_at: string}
     */
    private function result(array $payment, string $succeededAt): array
    {
        return [
            'id' => (string) $payment['id'],
            'invoice_id' => (string) $payment['invoice_id'],
            'status' => 'succeeded',
            'currency' => (string) $payment['currency'],
            'amount_minor' => Money::nonNegativeBigInt($payment['amount_minor']),
            'created_at' => (string) $payment['created_at'],
            'succeeded_at' => $succeededAt,
        ];
    }
}
