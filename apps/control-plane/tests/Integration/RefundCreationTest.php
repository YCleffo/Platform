<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RefundCreationTest extends RefundTestCase
{
    public function testPartialAllocationsAreIdempotentAndDriveRefundableBalance(): void
    {
        [$owner, $account, $payment, , $line] = $this->succeededRefundablePayment('refund-create');
        $items = [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 500]];
        $first = $this->refundCreator()->create($owner, $account, $payment, $items, 'refund-create-key', $this->uuid());
        $second = $this->refundCreator()->create($owner, $account, $payment, $items, 'refund-create-key', $this->uuid());

        self::assertSame($first['id'], $second['id']);
        self::assertSame(500, $first['amount_minor']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id = ?', [$payment]));
        $public = $this->paymentReader()->get($owner, $account, $payment);
        self::assertSame(0, $public['refunded_minor']);
        self::assertSame(1500, $public['refundable_minor']);
    }

    public function testSamePublicKeyWithChangedAllocationConflicts(): void
    {
        [$owner, $account, $payment, , $line] = $this->succeededRefundablePayment('refund-key');
        $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-same-key', $this->uuid());
        $this->expectException(\DomainException::class);
        $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 2, 'unit_amount_minor' => 1000]], 'refund-same-key', $this->uuid());
    }
}
