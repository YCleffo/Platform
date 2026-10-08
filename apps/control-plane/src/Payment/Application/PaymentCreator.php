<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;

/** Trusted payment creation primitive. Authorization remains at its caller's boundary. */
final readonly class PaymentCreator
{
    public function __construct(private SettlePayment $settlement) {}

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    public function createInTransaction(Connection $db, array $invoice, string $correlationId, string $actorType, ?string $actorId = null): array
    {
        foreach (['id', 'commercial_account_id', 'currency', 'status'] as $field) {
            if (!is_string($invoice[$field] ?? null)) throw new \LogicException('Invoice payment identity is unavailable.');
        }
        $existing = $db->fetchAssociative('SELECT id, invoice_id, commercial_account_id, status, currency, amount_minor, created_at, succeeded_at FROM commercial.payments WHERE invoice_id = ? FOR UPDATE', [$invoice['id']]);
        if ($existing !== false) return $existing;
        if ($invoice['status'] !== 'issued') throw new \DomainException('Payment requires an issued invoice.');
        $amount = Money::nonNegativeBigInt($invoice['total_minor'] ?? null);
        $payment = $db->fetchAssociative("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, ?, ?, 'pending') RETURNING id, invoice_id, commercial_account_id, status, currency, amount_minor, created_at, succeeded_at", [$invoice['id'], $invoice['commercial_account_id'], $amount, $invoice['currency']]);
        if ($payment === false || !is_string($payment['id'])) throw new \LogicException('Created payment state is unavailable.');
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES (?, ?, ?, 'payment.created', 'payment', ?, ?, 'success')", [$actorType, $actorId, $invoice['commercial_account_id'], $payment['id'], $correlationId]);
        $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('payment.created', 'payment', ?, jsonb_build_object('payment_id', ?::text, 'invoice_id', ?::text, 'commercial_account_id', ?::text, 'currency', ?::text, 'amount_minor', ?::bigint))", [$payment['id'], $payment['id'], $invoice['id'], $invoice['commercial_account_id'], $invoice['currency'], $amount]);

        return $amount === 0 ? $this->settlement->succeed($db, $payment, $invoice, new \DateTimeImmutable('now'), $correlationId) : $payment;
    }
}
