<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Fiscalization\Application\CreateRefundReceiptObligation;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\RefundProviderRequest;
use Doctrine\DBAL\Connection;

final readonly class ApplyAuthoritativeRefund
{
    public function __construct(private Connection $connection, private CreateRefundReceiptObligation $receipts)
    {
    }

    public function apply(string $refundId, ProviderRefund $provider, RefundProviderRequest $expected, string $correlationId): bool
    {
        if ($provider->providerPaymentId !== $expected->providerPaymentId || $provider->amountMinor !== $expected->amountMinor || $provider->currency !== $expected->currency) {
            throw new \DomainException('Provider refund does not match the immutable local snapshot.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($refundId, $provider, $correlationId): bool {
            $row = $connection->fetchAssociative('SELECT * FROM commercial.refunds WHERE id = ? FOR UPDATE', [$refundId]);
            if ($row === false) {
                throw new \DomainException('Refund was not found.');
            }
            if (is_string($row['provider_refund_id']) && $row['provider_refund_id'] !== $provider->id) {
                throw new \DomainException('Provider refund reference does not match local state.');
            }
            if (in_array($row['status'], ['succeeded', 'canceled'], true)) {
                if ($row['status'] !== $provider->status) {
                    throw new \DomainException('Terminal refund status does not match provider state.');
                }

                return false;
            }
            $connection->executeStatement('UPDATE commercial.refunds SET provider_refund_id = COALESCE(provider_refund_id, ?), last_attempted_at = now(), last_error_code = NULL, updated_at = now() WHERE id = ?', [$provider->id, $refundId]);
            if ($provider->status === 'pending') {
                return false;
            }
            $completed = ($provider->completedAt ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            $error = $provider->status === 'canceled' ? 'provider_refund_canceled' : null;
            $connection->executeStatement('UPDATE commercial.refunds SET status = ?, completed_at = ?::timestamptz, last_error_code = ?, updated_at = now() WHERE id = ?', [$provider->status, $completed, $error, $refundId]);
            $event = 'refund.' . $provider->status;
            $outcome = $provider->status === 'succeeded' ? 'success' : 'failure';
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, ?, 'refund', ?, ?, ?)", [$row['commercial_account_id'], $event, $refundId, $correlationId, $outcome]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES (?, 'refund', ?, jsonb_build_object('refund_id', ?::text, 'payment_id', ?::text, 'status', ?::text, 'amount_minor', ?::bigint, 'currency', ?::text))", [$event, $refundId, $refundId, $row['payment_id'], $provider->status, Money::nonNegativeBigInt($row['amount_minor']), $row['currency']]);
            if ($provider->status === 'succeeded') {
                $this->receipts->createInTransaction($connection, $refundId, $correlationId);
            }

            return true;
        });
    }
}
