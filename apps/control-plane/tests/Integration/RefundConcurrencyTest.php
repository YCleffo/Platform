<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RefundConcurrencyTest extends RefundTestCase
{
    public function testPendingRefundReservationPreventsConcurrentStyleOverRefund(): void
    {
        [$owner, $account, $payment, , $line] = $this->succeededRefundablePayment('refund-concurrency');
        $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-race-a', $this->uuid());
        $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-race-b', $this->uuid());
        try {
            $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-race-c', $this->uuid());
            self::fail('Reserved refunds must prevent over-refund.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.refunds WHERE payment_id = ? AND status = 'pending'", [$payment]));
    }
}
