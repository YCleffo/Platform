<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Payment\Provider\FiscalReceiptProviderGateway;
use ControlPlane\Payment\Provider\PaymentProviderException;
use Doctrine\DBAL\Connection;

final readonly class SubmitYooKassaReceipt
{
    private const IDEMPOTENCY_WINDOW_SECONDS = 86400;

    public function __construct(private Connection $connection, private ReceiptSnapshot $snapshots, private FiscalReceiptProviderGateway $gateway, private ApplyAuthoritativeReceipt $applier)
    {
    }

    public function submit(string $receiptId, string $correlationId): bool
    {
        [$row, $account, $request] = $this->snapshots->load($this->connection, $receiptId);
        if ($row['status'] !== 'pending') {
            return false;
        }
        try {
            if (is_string($row['external_receipt_id'])) {
                $provider = $this->gateway->getReceipt($account, $row['external_receipt_id']);
            } else {
                if ((new \DateTimeImmutable((string) $row['created_at']))->getTimestamp() < time() - self::IDEMPOTENCY_WINDOW_SECONDS) {
                    $this->recordError($receiptId, 'receipt_reconciliation_required');

                    return false;
                }
                $provider = $this->gateway->createReceipt($account, $request);
            }

            return $this->applier->apply($receiptId, $provider, $request, $correlationId);
        } catch (PaymentProviderException $exception) {
            $this->recordError($receiptId, $exception->errorCode);
            throw $exception;
        } catch (\DomainException $exception) {
            $this->recordError($receiptId, 'receipt_provider_mismatch');
            throw $exception;
        }
    }

    public function submitForPayment(string $paymentId, string $correlationId): bool
    {
        $receiptId = $this->connection->fetchOne("SELECT id FROM commercial.receipts WHERE payment_id = ? AND receipt_type = 'payment' AND status = 'pending'", [$paymentId]);

        return is_string($receiptId) && $this->submit($receiptId, $correlationId);
    }

    public function submitForRefund(string $refundId, string $correlationId): bool
    {
        $receiptId = $this->connection->fetchOne("SELECT id FROM commercial.receipts WHERE refund_id = ? AND receipt_type = 'refund' AND status = 'pending'", [$refundId]);

        return is_string($receiptId) && $this->submit($receiptId, $correlationId);
    }

    private function recordError(string $receiptId, string $code): void
    {
        $this->connection->executeStatement("UPDATE commercial.receipts SET last_attempted_at = now(), last_error_code = ?, updated_at = now() WHERE id = ? AND status = 'pending'", [$code, $receiptId]);
    }
}
