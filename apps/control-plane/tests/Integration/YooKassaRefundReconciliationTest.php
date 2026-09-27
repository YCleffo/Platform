<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Refund\Application\ReconcileYooKassaRefunds;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

final class YooKassaRefundReconciliationTest extends RefundTestCase
{
    public function testLostCreateResponseRetriesSameProviderRequestKey(): void
    {
        [$owner, $account, $payment, , $line] = $this->succeededRefundablePayment('refund-reconcile');
        $refund = $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-reconcile-key', $this->uuid());
        $gateway = new FakeRefundProviderGateway();
        $gateway->createFailure = PaymentProviderException::uncertain('refund_timeout');
        $submission = $this->refundSubmission($gateway);
        try {
            $submission->submit($refund['id'], $this->uuid());
        } catch (PaymentProviderException) {
            self::addToAssertionCount(1);
        }
        $result = (new ReconcileYooKassaRefunds($this->connection, $submission))->reconcile(100, $this->uuid());
        self::assertSame(1, $result['selected']);
        self::assertCount(2, $gateway->createCalls);
        self::assertSame($gateway->createCalls[0][1]->providerRequestKey, $gateway->createCalls[1][1]->providerRequestKey);
    }
}
