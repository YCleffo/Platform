<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\CreateRefundReceiptObligation;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Refund\Application\ApplyAuthoritativeRefund;
use ControlPlane\Refund\Application\CreateRefund;
use ControlPlane\Refund\Application\GetRefunds;
use ControlPlane\Refund\Application\RefundSnapshot;
use ControlPlane\Refund\Application\SubmitYooKassaRefund;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

abstract class RefundTestCase extends PaymentTestCase
{
    /** @return array{string, string, string, string, string, string, string} owner, account, payment, invoice, line, provider, provider payment */
    protected function succeededRefundablePayment(string $suffix, int $quantity = 2): array
    {
        [$owner, $account, $billingProfile, , , $subscription] = $this->invoiceContext($suffix . '-paid', $quantity, 'month', 'RUB');
        $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name = ?, contact_email = ? WHERE id = ?', ['Refund Contact', 'refund-' . $suffix . '@example.test', $billingProfile]);
        $issued = $this->issuer()->issue($owner, $account, $subscription, $suffix . '-refund-invoice', $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $issued['id'], $suffix . '-refund-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerPayment = 'provider-' . $attempt['attempt_id'];
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerPayment);
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-09-26T10:00:00Z'), $this->uuid());
        $line = $this->connection->fetchOne('SELECT id FROM commercial.invoice_lines WHERE invoice_id = ?', [$issued['id']]);
        self::assertIsString($line);

        return [$owner, $account, $payment['id'], $issued['id'], $line, $provider, $providerPayment];
    }

    protected function refundCreator(): CreateRefund
    {
        return new CreateRefund($this->connection, new AccountAuthorization($this->connection));
    }

    protected function refundReader(): GetRefunds
    {
        return new GetRefunds($this->connection, new AccountAuthorization($this->connection));
    }

    protected function refundSubmission(FakeRefundProviderGateway $gateway, ?FakeFiscalReceiptProviderGateway $receiptGateway = null): SubmitYooKassaRefund
    {
        $receiptSubmission = new SubmitYooKassaReceipt($this->connection, new ReceiptSnapshot(), $receiptGateway ?? new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($this->connection));
        $obligation = new CreateRefundReceiptObligation();

        return new SubmitYooKassaRefund($this->connection, new RefundSnapshot(), $gateway, new ApplyAuthoritativeRefund($this->connection, $obligation), $receiptSubmission);
    }
}
