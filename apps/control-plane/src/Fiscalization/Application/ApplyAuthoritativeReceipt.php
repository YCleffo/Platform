<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Payment\Provider\FiscalReceiptRequest;
use ControlPlane\Payment\Provider\ProviderReceipt;
use Doctrine\DBAL\Connection;

final readonly class ApplyAuthoritativeReceipt
{
    public function __construct(private Connection $connection)
    {
    }

    public function apply(string $receiptId, ProviderReceipt $provider, FiscalReceiptRequest $expected, string $correlationId): bool
    {
        $this->validate($provider, $expected);

        return $this->connection->transactional(function (Connection $connection) use ($receiptId, $provider, $correlationId): bool {
            // Receipt updates can recheck payment/invoice foreign keys. Acquire
            // the invoice first, matching settlement, before owning the receipt
            // or a payment key-share lock during those checks.
            $connection->executeQuery('SELECT id FROM commercial.invoices WHERE id = (SELECT invoice_id FROM commercial.receipts WHERE id = ?) FOR KEY SHARE', [$receiptId]);
            $row = $connection->fetchAssociative('SELECT id, commercial_account_id, receipt_type, external_receipt_id, status FROM commercial.receipts WHERE id = ? FOR UPDATE', [$receiptId]);
            if ($row === false) {
                throw new \DomainException('Receipt was not found.');
            }
            if (is_string($row['external_receipt_id']) && $row['external_receipt_id'] !== $provider->id) {
                throw new \DomainException('Provider receipt reference does not match local state.');
            }
            if (in_array($row['status'], ['succeeded', 'canceled'], true)) {
                if ($row['status'] !== $provider->status) {
                    throw new \DomainException('Terminal receipt status does not match provider state.');
                }

                return false;
            }
            $connection->executeStatement('UPDATE commercial.receipts SET external_receipt_id = COALESCE(external_receipt_id, ?), last_attempted_at = now(), last_error_code = NULL, updated_at = now() WHERE id = ?', [$provider->id, $receiptId]);
            if ($provider->status === 'pending') {
                return false;
            }
            $completed = ($provider->completedAt ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            $error = $provider->status === 'canceled' ? 'provider_receipt_canceled' : null;
            $connection->executeStatement('UPDATE commercial.receipts SET status = ?, completed_at = ?::timestamptz, last_error_code = ?, updated_at = now() WHERE id = ?', [$provider->status, $completed, $error, $receiptId]);
            $event = $row['receipt_type'] === 'refund' ? 'receipt.refund_' . $provider->status : 'receipt.' . $provider->status;
            $outcome = $provider->status === 'succeeded' ? 'success' : 'failure';
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, ?, 'receipt', ?, ?, ?)", [$row['commercial_account_id'], $event, $receiptId, $correlationId, $outcome]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES (?, 'receipt', ?, jsonb_build_object('receipt_id', ?::text, 'status', ?::text))", [$event, $receiptId, $receiptId, $provider->status]);

            return true;
        });
    }

    private function validate(ProviderReceipt $actual, FiscalReceiptRequest $expected): void
    {
        $referenceMatches = $expected->receiptType === 'payment'
            ? $actual->providerPaymentId === $expected->providerPaymentId
            : $actual->providerRefundId === $expected->providerRefundId;
        if ($actual->type !== $expected->receiptType || !$referenceMatches || $actual->amountMinor !== $expected->amountMinor || $actual->currency !== $expected->currency || count($actual->items) !== count($expected->items)) {
            throw new \DomainException('Provider receipt does not match the immutable local snapshot.');
        }
        foreach ($expected->items as $index => $item) {
            if ($actual->items[$index] != $item) {
                throw new \DomainException('Provider receipt items do not match the immutable local snapshot.');
            }
        }
    }
}
