<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RefundConstraintsTest extends RefundTestCase
{
    public function testOverRefundAndSnapshotMutationAreRejected(): void
    {
        [$owner, $account, $payment, , $line] = $this->succeededRefundablePayment('refund-constraints');
        $refund = $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 2, 'unit_amount_minor' => 1000]], 'refund-full-key', $this->uuid());
        try {
            $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-over-key', $this->uuid());
            self::fail('Over-refund must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.refund_items SET quantity = 1, total_minor = 1000 WHERE refund_id = ?', [$refund['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.refunds WHERE id = ?', [$refund['id']]));
    }
}
