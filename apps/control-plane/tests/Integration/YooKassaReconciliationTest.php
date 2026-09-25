<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;

final class YooKassaReconciliationTest extends PaymentTestCase
{
    public function testReconciliationCompletesSuccessAndLeavesPendingUntouched(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedRubInvoiceContext('yk-reconcile-success');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-reconcile-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-reconcile-success-provider';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = new ProviderPayment(
            $providerId,
            'succeeded',
            true,
            1000,
            'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoice['id']],
            null,
            new \DateTimeImmutable('2026-09-26T13:00:00Z'),
        );

        $result = $this->yooKassaReconciliation($gateway)->reconcile(10, $this->uuid());

        self::assertSame(['selected' => 1, 'completed' => 1, 'pending' => 0, 'failed' => 0], $result);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));

        [$pendingOwner, $pendingAccount, , , , , $pendingInvoice] = $this->issuedRubInvoiceContext('yk-reconcile-pending');
        $pendingPayment = $this->paymentCreator()->create($pendingOwner, $pendingAccount, $pendingInvoice['id'], 'yk-reconcile-pending-create', $this->uuid());
        $pendingAttempt = $this->attemptBeginner()->begin($pendingPayment['id'], $provider, 'sbp', $this->uuid());
        $pendingProviderId = 'yk-reconcile-pending-provider';
        $this->attemptBinder()->bind($pendingAttempt['attempt_id'], $pendingProviderId);
        $gateway->payments[$pendingProviderId] = new ProviderPayment(
            $pendingProviderId,
            'pending',
            false,
            1000,
            'RUB',
            ['payment_id' => $pendingPayment['id'], 'attempt_id' => $pendingAttempt['attempt_id'], 'invoice_id' => $pendingInvoice['id']],
            'https://yookassa.test/confirmation/pending',
            null,
        );

        $pendingResult = $this->yooKassaReconciliation($gateway)->reconcile(10, $this->uuid());
        self::assertSame(['selected' => 1, 'completed' => 0, 'pending' => 1, 'failed' => 0], $pendingResult);
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$pendingAttempt['attempt_id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$pendingPayment['id']]));
    }
}
