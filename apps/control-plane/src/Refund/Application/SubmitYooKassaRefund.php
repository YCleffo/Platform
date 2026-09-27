<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\RefundProviderGateway;
use Doctrine\DBAL\Connection;

final readonly class SubmitYooKassaRefund
{
    public function __construct(private Connection $connection, private RefundSnapshot $snapshots, private RefundProviderGateway $gateway, private ApplyAuthoritativeRefund $applier, private SubmitYooKassaReceipt $receipts)
    {
    }

    public function submit(string $refundId, string $correlationId): bool
    {
        [$row, $account, $request] = $this->snapshots->load($this->connection, $refundId);
        if ($row['status'] !== 'pending') {
            return false;
        }
        try {
            $provider = is_string($row['provider_refund_id'])
                ? $this->gateway->getRefund($account, $row['provider_refund_id'])
                : $this->gateway->createRefund($account, $request);
            $changed = $this->applier->apply($refundId, $provider, $request, $correlationId);
            if ($provider->status === 'succeeded') {
                try {
                    $this->receipts->submitForRefund($refundId, $correlationId);
                } catch (PaymentProviderException|\DomainException) {
                    // Refund settlement is committed; receipt reconciliation owns delivery.
                }
            }

            return $changed;
        } catch (PaymentProviderException $exception) {
            if (!$exception->retryable) {
                $this->cancelDefinitive($refundId, $exception->errorCode, $correlationId);
            }
            $this->recordError($refundId, $exception->errorCode);
            throw $exception;
        } catch (\DomainException $exception) {
            $this->recordError($refundId, 'refund_provider_mismatch');
            throw $exception;
        }
    }

    public function processAuthoritative(string $refundId, \ControlPlane\Payment\Provider\ProviderRefund $provider, string $correlationId): bool
    {
        [, , $request] = $this->snapshots->load($this->connection, $refundId);
        if ($provider->localRefundId !== null && strtolower($provider->localRefundId) !== strtolower($refundId)) {
            throw new \DomainException('Provider refund metadata does not match local state.');
        }
        $changed = $this->applier->apply($refundId, $provider, $request, $correlationId);
        if ($provider->status === 'succeeded') {
            try {
                $this->receipts->submitForRefund($refundId, $correlationId);
            } catch (PaymentProviderException|\DomainException) {
                // Refund settlement is already committed.
            }
        }

        return $changed;
    }

    private function recordError(string $refundId, string $code): void
    {
        $this->connection->executeStatement("UPDATE commercial.refunds SET last_attempted_at = now(), last_error_code = ?, updated_at = now() WHERE id = ? AND status = 'pending'", [$code, $refundId]);
    }

    private function cancelDefinitive(string $refundId, string $code, string $correlationId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($refundId, $code, $correlationId): void {
            $row = $connection->fetchAssociative('SELECT id, payment_id, commercial_account_id, status FROM commercial.refunds WHERE id = ? FOR UPDATE', [$refundId]);
            if ($row === false || $row['status'] !== 'pending') {
                return;
            }
            $connection->executeStatement("UPDATE commercial.refunds SET status = 'canceled', completed_at = now(), last_attempted_at = now(), last_error_code = ?, updated_at = now() WHERE id = ?", [$code, $refundId]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome, metadata) VALUES ('system', ?, 'refund.canceled', 'refund', ?, ?, 'failure', jsonb_build_object('failure_code', ?::text))", [$row['commercial_account_id'], $refundId, $correlationId, $code]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('refund.canceled', 'refund', ?, jsonb_build_object('refund_id', ?::text, 'payment_id', ?::text, 'status', 'canceled'))", [$refundId, $refundId, $row['payment_id']]);
        });
    }
}
