<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\ProviderReceipt;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

final class RefundReceiptLifecycleTest extends RefundTestCase
{
    public function testRefundSuccessCreatesAndReconcilesSeparateRefundReceipt(): void
    {
        [$owner, $account, $payment, , $line, , $providerPayment] = $this->succeededRefundablePayment('refund-receipt');
        $refund = $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-receipt-key', $this->uuid());
        $refundGateway = new FakeRefundProviderGateway();
        $receiptGateway = new FakeFiscalReceiptProviderGateway();
        $submission = $this->refundSubmission($refundGateway, $receiptGateway);
        self::assertFalse($submission->submit($refund['id'], $this->uuid()));
        $providerRefund = (string) $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id = ?', [$refund['id']]);
        $refundGateway->refunds[$providerRefund] = new ProviderRefund($providerRefund, $providerPayment, 'succeeded', 1000, 'RUB', new \DateTimeImmutable('2026-09-27T10:00:00Z'), $refund['id']);
        self::assertTrue($submission->submit($refund['id'], $this->uuid()));
        $receipt = $this->connection->fetchAssociative("SELECT id, external_receipt_id FROM commercial.receipts WHERE refund_id = ? AND receipt_type = 'refund'", [$refund['id']]);
        self::assertIsArray($receipt);
        self::assertIsString($receipt['external_receipt_id']);
        $pending = $receiptGateway->receipts[$receipt['external_receipt_id']];
        self::assertInstanceOf(ProviderReceipt::class, $pending);
        self::assertSame('refund', $pending->type);
        self::assertSame($providerRefund, $pending->providerRefundId);
        $this->connection->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
