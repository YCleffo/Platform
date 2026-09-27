<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

final class RefundLifecycleTest extends RefundTestCase
{
    public function testAuthoritativeSuccessIsTerminalAndCreatesRefundReceiptObligation(): void
    {
        [$owner, $account, $payment, , $line, , $providerPayment] = $this->succeededRefundablePayment('refund-life');
        $refund = $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-life-key', $this->uuid());
        $gateway = new FakeRefundProviderGateway();
        $submission = $this->refundSubmission($gateway);
        self::assertFalse($submission->submit($refund['id'], $this->uuid()));
        $providerId = $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id = ?', [$refund['id']]);
        self::assertIsString($providerId);
        $gateway->refunds[$providerId] = new ProviderRefund($providerId, $providerPayment, 'succeeded', 1000, 'RUB', new \DateTimeImmutable('2026-09-26T11:00:00Z'), $refund['id']);

        self::assertTrue($submission->submit($refund['id'], $this->uuid()));
        self::assertFalse($submission->submit($refund['id'], $this->uuid()));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id = ?', [$refund['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.receipts WHERE refund_id = ? AND receipt_type = 'refund'", [$refund['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'refund.succeeded' AND aggregate_id = ?", [$refund['id']]));
        self::assertSame('succeeded', $this->refundReader()->one($owner, $account, $refund['id'])['status']);
    }
}
